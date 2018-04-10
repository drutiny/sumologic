<?php

namespace Drutiny\SumoLogic\Audit;

use Drutiny\SumoLogic\Client;
use Drutiny\Audit;
use Drutiny\Sandbox\Sandbox;
use Drutiny\Credential\Manager;

abstract class ApiEnabledAudit extends Audit {

  protected function requireApiCredentials()
  {
    return Manager::load('sumologic') ? TRUE : FALSE;
  }

  protected function search(Sandbox $sandbox, $query)
  {
    $sandbox
      ->logger()
      ->info(get_class($this) . ': ' . $query);

    $creds = Manager::load('sumologic');
    $client = new Client($creds['access_id'], $creds['access_key']);

    $options = [
      'from' => (new \DateTime($sandbox->getParameter('from', '-24 hours')))->format(\DateTime::ATOM),
      'to' => (new \DateTime($sandbox->getParameter('to', 'now')))->format(\DateTime::ATOM),
      'timeZone' => $sandbox->getParameter('timezone', date_default_timezone_get())
    ];

    $sandbox
      ->logger()
      ->info(get_class($this) . ': ' . print_r($options, TRUE));

    $client->query($query, $options)
      ->onSuccess(function ($records) use ($sandbox) {
        foreach ($records as &$record) {
          if (isset($record['_timeslice'])) {
            $record['_timeslice'] = date('Y-m-d H:i:s', $record['_timeslice']/1000);
          }
        }
        $sandbox->setParameter('records', $records);
      })
      ->wait($sandbox->logger());
    return $sandbox->getParameter('records', []);
  }
}

 ?>
