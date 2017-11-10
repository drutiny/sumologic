<?php

namespace Drutiny\SumoLogic;
use Yriveiro\Backoff\Backoff;
use Yriveiro\Backoff\BackoffException;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Psr\Log\AbstractLogger;

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
    $this->successCallback = function ($records) {};
  }

  public function onSuccess(callable $callback)
  {
    $this->successCallback = $callback;
    return $this;
  }

  public function wait(AbstractLogger $logger)
  {

    $attempt = 12;
    $options = Backoff::getDefaultOptions();
    $options['cap'] = 120 * 1000000;
    $options['maxAttempts'] = 1000;
    $backoff = new Backoff($options);

    while ($this->status < Client::QUERY_COMPLETE) {
      $wait = $backoff->exponential($attempt);
      usleep($wait);
      $this->status = $this->client->queryStatus($this->job->id);
      $logger->info(__CLASS__ . ": Job {$this->job->id} status: {$this->status}");
      $attempt++;
    }

    // Query completed, lets acquire the rows.
    $records = $this->client->fetchRecords($this->job->id);
    return call_user_func($this->successCallback, $records);
  }

  public function __destruct()
  {
    $this->client->deleteQuery($this->job->id);
  }

}

 ?>
