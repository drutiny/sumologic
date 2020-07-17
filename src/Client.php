<?php

namespace Drutiny\SumoLogic;

use DateTime;
use Drutiny\Http\Client as HTTPClient;
use Drutiny\SumoLogic\Plugin\SumoLogicPlugin;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter as Cache;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class Client {

  const QUERY_JOB_RECORDS_LIMIT = 10000;
  const MAX_JOB_WAIT = 200;
  const QUERY_API_RATE_LIMITED = 1;
  const QUERY_JOB_NOT_STARTED = 2;
  const QUERY_JOB_IN_PROGRESS = 3;
  const QUERY_COMPLETE = 4;
  const QUERY_JOB_CANCELLED = 5;

  private $queryStatusDefinitions = [
    'NOT STARTED'	=> 'Search job has not been started yet.',
    'GATHERING RESULTS'	=> 'Search job is still gathering more results, however results might already be available.',
    'DONE GATHERING RESULTS'	=> 'Search job is done gathering results; the entire specified time range has been covered.',
    'CANCELED'	=> 'The search job has been canceled.'
  ];

  private $queryStatusMap = [
    'NOT STARTED'	=> self::QUERY_JOB_NOT_STARTED,
    'GATHERING RESULTS'	=> self::QUERY_JOB_IN_PROGRESS,
    'GATHERING RESULTS FROM SUBQUERIES' => self::QUERY_JOB_IN_PROGRESS,
    'DONE GATHERING RESULTS'	=> self::QUERY_COMPLETE,
    'CANCELED'	=> self::QUERY_JOB_CANCELLED,
  ];

  protected $client;
  protected $logger;
  protected $cache;

  /**
   * Constructor.
   */
  public function __construct(SumoLogicPlugin $plugin, HTTPClient $http, LoggerInterface $logger, CacheInterface $cache)
  {
    $this->logger = $logger;
    $this->cache = $cache;
    $creds = $plugin->load();
    $this->client = $http->create([
      'cookies' => new CookieJar(),
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept'       => 'application/json',
        'User-Agent'   => 'Drutiny Sumologic Driver (https://github.com/drutiny/sumologic)',
      ],
      'allow_redirects' => FALSE,
      'connect_timeout' => 5,
      'timeout' => 5,
      'auth' => [
        $creds['access_id'], $creds['access_key']
      ],
      'base_uri' => $creds['endpoint']
    ]);
  }

  public function query($search_query, $options = [], callable $callback = null)
  {
    $json = [
      'from' => (new DateTime('-24 hours'))->format(DateTime::ATOM),
      'to' => (new DateTime())->format(DateTime::ATOM),
      'timeZone' => date_default_timezone_get(),
    ];
    $json = array_merge($json, $options);
    $json['query'] = $search_query;

    $cid = hash('md5', http_build_query($json));

    $callable = $callback ?? function ($records) { return $records; };

    return $callable($this->cache->get($cid, function (ItemInterface $item) use ($json) {
      $response = $this->client->request('POST','search/jobs', [
        'json' => $json,
      ]);
      $code = $response->getStatusCode();

      if ($code !== 202) {
        throw new \RuntimeException('Error getting data from Sumologic, error was HTTP ' . $code . ' - ' . $response->getBody() . '.');
      }
      if (!$job = json_decode($response->getBody())) {
        throw new \Exception('Unable to decode response: ' . $response->getBody());
      }
      $attempt = 0;

      do {
        if ($attempt >= static::MAX_JOB_WAIT) {
          $this->logger->error("Sumologic query took too long. Quit waiting.");
          break;
        }
        sleep(3);
        $status = $this->queryStatus($job->id);
        $this->logger->info("Waiting for Sumologic job {$job->id} to complete. Poll $attempt/".static::MAX_JOB_WAIT." Response: " . $this->getStateName($status));
        $attempt++;
      }
      while ($status < Client::QUERY_COMPLETE);
      return $this->fetchRecords($job->id);
    }));
  }

  /**
   * Use the search job ID to obtain the current status of a search job. Ignore
   * rate limit errors, as they only last for 1 minute.
   *
   * @see https://help.sumologic.com/APIs/Search-Job-API/About-the-Search-Job-API#Getting_the_current_Search_Job_status
   */
  public function queryStatus($job_id) {
    $response = $this->client->request('GET', "search/jobs/$job_id", [
      'http_errors' => false
    ]);
    if ($response->getStatusCode() === 429) {
      return self::QUERY_API_RATE_LIMITED;
    }
    $data = json_decode($response->getBody());
    $state = $data->state;

    if (!isset($this->queryStatusMap[$state])) {
      $this->logger->error("SumoLogic returned unknown query status: $state.");
      return self::QUERY_JOB_CANCELLED;
    }
    return $this->queryStatusMap[$state];
  }

  public function getStateName($status_code)
  {
    $state = array_search($status_code, $this->queryStatusMap, TRUE);
    if ($state === FALSE) {
      $state = array_search(self::QUERY_JOB_CANCELLED, $this->queryStatusMap, TRUE);
    }
    return $state;
  }

  /**
   * The search job status informs the user as to the number of produced
   * records, if the query performs an aggregation. Those records can be
   * requested using a paging API call (step 6 in the process flow), just as the
   * message can be requested.
   *
   * @see https://help.sumologic.com/APIs/Search-Job-API/About-the-Search-Job-API#Paging_through_the_records_found_by_a_Search_Job
   */
  public function fetchRecords($job_id, $limit = self::QUERY_JOB_RECORDS_LIMIT) {
    $offset = 0;
    $query = [
      'limit' => min(self::QUERY_JOB_RECORDS_LIMIT, $limit)
    ];
    $rows = [];

    do {
      // Throttle the batch query after the first record set has been fetched.
      if ($offset) {
        sleep(1);
      }
      $query['offset'] = $offset;

      $response = $this->client->request('GET', "search/jobs/$job_id/records", [
        'query' => $query
      ]);

      $data = json_decode($response->getBody());
      $records = $data->records;

      foreach ($records as $key => $record) {
        $rows[] = (array) $record->map;
      }

      $offset += $query['limit'];
    }
    while ((count($records) == $query['limit']) && (count($rows) < $limit));

    return $rows;
  }


  /**
   * Although search jobs ultimately time out in the Sumo Logic backend, it's a
   * good practice to explicitly cancel a search job when it is not needed
   * anymore.
   *
   * @see https://help.sumologic.com/APIs/Search-Job-API/About-the-Search-Job-API#Deleting_a_search_job
   */
  public function deleteQuery($job_id)
  {
    return $this->client->request('DELETE', "search/jobs/$job_id");
  }

}
