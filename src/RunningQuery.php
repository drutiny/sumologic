<?php

namespace Drutiny\SumoLogic;
use Yriveiro\Backoff\Backoff;
use Yriveiro\Backoff\BackoffException;

class RunningQuery {

  protected $job;
  protected $client;
  protected $status;
  protected $successCallback;

  public function __construct($job, Client $client)
  {
    $this->job = $job;
    $this->client = $client;
    $this->status = Client::QUERY_JOB_NOT_STARTED;
    $this->successCallback = function () {};
  }

  public function onSuccess(callable $callback)
  {
    $this->successCallback = $callback;
    return $this;
  }

  public function wait()
  {
    $attempt = 12;
    $options = Backoff::getDefaultOptions();
    $options['cap'] = 120 * 1000000;
    $options['maxAttempts'] = 1000;
    $backoff = new Backoff($options);

    while ($this->status < Client::QUERY_COMPLETE) {
      $this->status = $this->client->queryStatus($this->job->id);
      usleep($backoff->exponential($attempt));
      $attempt++;
    }

    // Query completed, lets acquire the rows.
    $records = $this->client->fetchRecords($this->job->id);

    return $this->successCallback($records);
  }

  public function __destruct()
  {
    $this->client->deleteQuery($this->job->id);
  }

}

 ?>
