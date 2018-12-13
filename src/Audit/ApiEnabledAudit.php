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
    $steps = $sandbox->getReportingPeriodSteps();
    switch (TRUE) {
      case $steps >= 86400:
        $timeslice = round($steps / 86400) . 'd';
        break;

      case $steps >= 3600:
        $timeslice = round($steps / 3600) . 'h';
        break;

      case $steps > 60:
        $timeslice = round($steps / 60) . 'm';
        break;

      default:
        $timeslice = $steps . 's';
        break;
    }

    $sandbox->setParameter('sumologic_timeslice', $timeslice);
    $query = strtr($query, [
      '@_timeslice' => $timeslice
    ]);

    $sandbox
      ->logger()
      ->debug(get_class($this) . ': ' . $query);

    $creds = Manager::load('sumologic');
    $client = new Client($creds['access_id'], $creds['access_key']);

    $options['from']     = $sandbox->getReportingPeriodStart()->format(\DateTime::ATOM);
    $options['to']       = $sandbox->getReportingPeriodEnd()->format(\DateTime::ATOM);

    $tz = $sandbox->getReportingPeriodStart()->getTimeZone()->getName();

    // SumoLogic requires a formal timezone. E.g. Pacific/Auckland.
    // If the timezone provided is in a short format (e.g. EST, NZST)
    // then it needs to be converted into the format sumologic supports.
    if (strpos('/', $tz)) {
      $options['timeZone'] = $tz;
    }
    else {
      $codes = \DateTimeZone::listAbbreviations();
      $tz = strtolower($tz);
      if (isset($codes[$tz])) {
        $options['timeZone'] = $codes[$tz][0]['timezone_id'];
      }
    }

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
      ->debug(get_class($this) . ': ' . print_r($options, TRUE));

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
