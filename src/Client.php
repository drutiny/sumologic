<?php

namespace Drutiny\SumoLogic;

use DateTime;
use Drutiny\Attribute\Plugin;
use Drutiny\Attribute\PluginField;
use Drutiny\Http\Client as HTTPClient;
use Drutiny\Plugin as DrutinyPlugin;
use Drutiny\Plugin\FieldType;
use Drutiny\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\Console\Helper\ProgressBar;

#[Plugin(name: 'sumologic:api')]
#[PluginField(
  name: 'access_id',
  description: "Your access ID to connect to the Sumologic API with",
  type: FieldType::CREDENTIAL
)]
#[PluginField(
  name: 'access_key',
  description: 'Your access key to connect to the Sumologic API with',
  type: FieldType::CREDENTIAL
)]
#[PluginField(
  name: 'endpoint',
  description: 'The API endpoint to use. Defaults https://api.sumologic.com/api/v1/ if empty',
  type: FieldType::CONFIG,
  default: 'https://api.sumologic.com/api/v1/'
)]
class Client {

  const QUERY_JOB_RECORDS_LIMIT = 10000;

  protected int $maxJobWait = 200;
  protected int $pollWait = 5;
  protected ClientInterface $client;

  /**
   * Constructor.
   */
  public function __construct(
    DrutinyPlugin $plugin,
    HTTPClient $http,
    protected LoggerInterface $logger,
    Settings $settings,
    protected ProgressBar $progressBar,
    protected CacheInterface $cache
    )
  {
    $this->maxJobWait = $settings->get('sumologic.max_job_wait');
    $this->pollWait = $settings->get('sumologic.poll_wait');
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
        $plugin->access_id, $plugin->access_key
      ],
      'base_uri' => $plugin->endpoint
    ]);
  }

  public function query(string $search_query, array $options = [], callable $callback = null):array
  {
    $json = [
      'from' => (new DateTime('-24 hours'))->format(DateTime::ATOM),
      'to' => (new DateTime())->format(DateTime::ATOM),
      'timeZone' => date_default_timezone_get(),
    ];
    $json = array_merge($json, $options);
    $json['query'] = $search_query;

    $cid = hash('md5', http_build_query($json));

    $callback ??= fn ($r) => $r;

    return $callback($this->cache->get($cid, function (ItemInterface $item) use ($json) {
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
      $this->progressBar->setMaxSteps($this->progressBar->getMaxSteps() + $this->maxJobWait);

      $item->expiresAfter(3600);
      do {
        if ($attempt >= $this->maxJobWait) {
          $this->logger->error("Sumologic query took too long. Quit waiting.");
          $item->expiresAfter(0);
          break;
        }
        sleep($this->pollWait);
        $status = $this->queryStatus($job->id);
        $this->logger->notice("Waiting for Sumologic job {$job->id} to complete. Poll $attempt/".$this->maxJobWait." Response: " . $status->getDefinition());
        $attempt++;
        $this->progressBar->advance();
      }
      while (!$status->isComplete());

      $this->progressBar->advance($this->maxJobWait - $attempt);
      $records = $this->fetchRecords($job->id);

      // Query timed out so lets delete it so it doesn't continue.
      if (!$status->isComplete()) {
        $this->deleteQuery($job->id);
      }

      return $records;
    }));
  }

  /**
   * Use the search job ID to obtain the current status of a search job. Ignore
   * rate limit errors, as they only last for 1 minute.
   *
   * @see https://help.sumologic.com/APIs/Search-Job-API/About-the-Search-Job-API#Getting_the_current_Search_Job_status
   */
  public function queryStatus($job_id):JobStatus {
    $response = $this->client->request('GET', "search/jobs/$job_id", [
      'http_errors' => false
    ]);
    if ($response->getStatusCode() === 429) {
      return JobStatus::API_RATE_LIMITED;
    }
    $data = json_decode($response->getBody());

    return JobStatus::map($data->state);
  }

  /**
   * The search job status informs the user as to the number of produced
   * records, if the query performs an aggregation. Those records can be
   * requested using a paging API call (step 6 in the process flow), just as the
   * message can be requested.
   *
   * @see https://help.sumologic.com/APIs/Search-Job-API/About-the-Search-Job-API#Paging_through_the_records_found_by_a_Search_Job
   */
  public function fetchRecords($job_id, $limit = self::QUERY_JOB_RECORDS_LIMIT):array
  {
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


  /**
   * Get Metrics data from SumoLogic
   * 
   * @param array[] $queries The actual query expressions.
   * @param int $startTime Start of the query time range, in milliseconds since epoch.
   * @param int $endTime End of the query time range, in milliseconds since epoch.
   * @param int $requestedDataPoints Desired number of data points returned per series.
   * @param int $maxTotalDataPoints Upper bound on sum total number of data points returned across all series.
   * @param int $desiredQuantizationInSec Desired granularity of temporal quantization in seconds. 
   *                                      Note that this may be overridden by the backend in order to satisfy 
   *                                      constraints on the number of data points returned.
   * @see https://help.sumologic.com/docs/api/metrics/
   */
  public function getMetricsQueries(array $queries, array $timeRange): array
  {
    //metrics/results
    $json = array_filter([
      'queries' => $queries,
      'timeRange' => $timeRange,
    ]);
    $cid = hash('md5', http_build_query($json));

    return $this->cache->get($cid, function (ItemInterface $item) use ($json) {
      $item->expiresAfter(300);
      $response = $this->client->request('POST','metricsQueries', [
        RequestOptions::JSON => $json,
        RequestOptions::TIMEOUT => 30
      ]);
      if (!$metrics = json_decode($response->getBody(), true)) {
        throw new \Exception('Unable to decode response: ' . $response->getBody());
      }
      return $metrics;
    });
  }
}
