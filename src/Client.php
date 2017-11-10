<?php

namespace Drutiny\SumoLogic;

use GuzzleHttp\Client as HTTPClient;
use GuzzleHttp\Cookie\CookieJar;
use DateTime;
use Yriveiro\Backoff\Backoff;
use Yriveiro\Backoff\BackoffException;

class Client {

  const QUERY_API_RATE_LIMITED = 1;
  const QUERY_JOB_NOT_STARTED = 2;
  const QUERY_JOB_IN_PROGRESS = 3;
  const QUERY_COMPLETE = 4;
  const QUERY_JOB_CANCELLED = 5;

  /**
   * Constructor.
   */
  public function __construct($access_id, $access_key, $endpoint = 'https://api.sumologic.com/api/v1/')
  {
    $jar = new CookieJar();
    $this->client = new HTTPClient([
      'cookies' => $jar,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept'       => 'application/json',
        'User-Agent'   => 'site-efficiency/1.0',
      ],
      'allow_redirects' => FALSE,
      'connect_timeout' => 5,
      'timeout' => 5,
      'auth' => [
        $access_id, $access_key
      ],
      'base_uri' => $endpoint
    ]);
  }

  public function query($search_query, $options = [])
  {
    $json = [
      'from' => (new DateTime('-24 hours'))->format(DateTime::ATOM),
      'to' => (new DateTime())->format(DateTime::ATOM),
      'timeZone' => date_default_timezone_get(),
    ];
    $json = array_merge($json, $options);
    $json['query'] = $search_query;
    $response = $this->client->request('POST','search/jobs', [
      'json' => $json,
    ]);
    $code = $response->getStatusCode();

    if ($code !== 202) {
      throw new \RuntimeException('Error getting data from Sumologic, error was HTTP ' . $code . ' - ' . $response->getBody() . '.');
    }
    if (!$data = json_decode($response->getBody())) {
      throw new \Exception('Unable to decode response: ' . $response->getBody());
    }

    return new RunningQuery($data, $this);
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
    switch ($state) {
      case "NOT STARTED" :
        return self::QUERY_JOB_NOT_STARTED;
      case "GATHERING RESULTS";
        return self::QUERY_JOB_IN_PROGRESS;
      case "DONE GATHERING RESULTS";
        return self::QUERY_COMPLETE;
      default:
        return self::QUERY_JOB_CANCELLED;
    }
  }

  /**
   * The search job status informs the user as to the number of produced
   * records, if the query performs an aggregation. Those records can be
   * requested using a paging API call (step 6 in the process flow), just as the
   * message can be requested.
   *
   * @see https://help.sumologic.com/APIs/Search-Job-API/About-the-Search-Job-API#Paging_through_the_records_found_by_a_Search_Job
   */
  public function fetchRecords($job_id) {
    $response = $this->client->request('GET', "search/jobs/$job_id/records", [
      'query' => [
        'offset' => 0,
        'limit' => 100
      ]
    ]);
    $data = json_decode($response->getBody());
    $records = $data->records;

    $rows = [];

    foreach ($records as $key => $record) {
      $rows[] = (array) $record->map;
    }

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
