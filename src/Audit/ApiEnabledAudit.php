<?php

namespace Drutiny\SumoLogic\Audit;

use Drutiny\SumoLogic\Client;
use Drutiny\Audit;
use Drutiny\Sandbox\Sandbox;
use Drutiny\Credential\Manager;
use Drutiny\Annotation\Param;

/**
 * @Param(
 *  name = "from",
 *  description = "The reporting date to start from. e.g. -24 hours.",
 *  default = false,
 *  type = "string"
 * )
 * @Param(
 *  name = "to",
 *  description = "The reporting date to end on. e.g. now.",
 *  default = false,
 *  type = "string"
 * )
 * @Param(
 *  name = "timeZone",
 *  description = "The timeZone the dates refer to.",
 *  default = false,
 *  type = "string"
 * )
 */
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

    $options['from']     = $sandbox->getReportingPeriodStart()->format(\DateTime::ATOM);
    $options['to']       = $sandbox->getReportingPeriodEnd()->format(\DateTime::ATOM);
    $options['timeZone'] = $sandbox->getReportingPeriodStart()->getTimeZone()->getName();

    if ($time = $sandbox->getParameter('from')) {
      $options['from'] = date(\DateTime::ATOM, strtotime($time));
    }
    if ($time = $sandbox->getParameter('to')) {
      $options['to'] = date(\DateTime::ATOM, strtotime($time));
    }
    if ($tz = $sandbox->getParameter('timezone')) {
      $options['timeZone'] = $tz;
    }

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
      ->wait();
    return $sandbox->getParameter('records', []);
  }
}
