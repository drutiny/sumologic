<?php

namespace Drutiny\SumoLogic\Audit;

use DateTimeZone;
use Drutiny\Attribute\Parameter;
use Drutiny\Audit\AbstractAnalysis;
use Drutiny\SumoLogic\Client;
use Exception;

/**
 * Analyse a search query response from SumoLogic.
 */
#[Parameter(name: 'query', description: 'The sumologic query to send to the API', mode: Parameter::REQUIRED)]
#[Parameter(name: 'from', description: 'The reporting date to start from. e.g. -24 hours')]
#[Parameter(name: 'to', description: 'The reporting date to end on. e.g. now.')]
#[Parameter(name: 'timezone', description: 'The timeZone the dates refer to.')]
#[Parameter(name: 'globals', description: 'string[] of global fields to extract from the resultset.')]
class QueryAnalysis extends AbstractAnalysis
{
    /**
     * {@inheritdoc}
     */
    protected function gather(Client $api):void
    {
        $query = $this->interpolate($this->getParameter('query'), [
            'timeslice' => $this->getTimeslice(),
        ]);

        $options = [];

        $options['from']     = $this->reportingPeriodStart->format(\DateTime::ATOM);
        $options['to']       = $this->reportingPeriodEnd->format(\DateTime::ATOM);
        $options['timeZone'] = $this->getTimezone();

        // Allow policy overrides of reporting period data.
        if ($time = $this->getParameter('from')) {
            $options['from'] = date(\DateTime::ATOM, strtotime($time));
        }
        if ($time = $this->getParameter('to')) {
            $options['to'] = date(\DateTime::ATOM, strtotime($time));
        }
        if ($tz = $this->getParameter('timezone')) {
            $options['timeZone'] = $tz;
        }
        $this->logger->debug($query);
        $this->logger->notice("Waiting for SumoLogic query to return...");
        $records = $api->query($query, $options, function ($records) {
            foreach ($records as &$record) {
                if (isset($record['_timeslice'])) {
                    $record['_timeslice'] = date('Y-m-d H:i:s', $record['_timeslice']/1000);
                }
            }
            return $records;
        });
        $this->set('records', $records);

        if ($globals = $this->getParameter('globals', [])) {
            $row = reset($records) ?: [];
            foreach ($globals as $key) {
                $this->set($key, $row[$key] ?? null);
            }
        }
    }

    /**
     * Get the timeslice for a query.
     */
    public function getTimeslice():string {
        $steps = $this->getReportingPeriodSteps();
        return match (true) {
            $steps >= 86400 => round($steps / 86400) . 'd',
            $steps >= 3600 => round($steps / 3600) . 'h',
            $steps > 60 => round($steps / 60) . 'm',
            default => $steps . 's',
        };
    }

    /**
     * Get the timezone.
     */
    public function getTimezone():string
    {
        $tz = $this->reportingPeriodStart->getTimeZone()->getName();

        // SumoLogic requires a formal timezone. E.g. Pacific/Auckland.
        // If the timezone provided is in a short format (e.g. EST, NZST)
        // then it needs to be converted into the format sumologic supports.
        if (strpos($tz, '/')) {
            return $tz;
        }

        $codes = DateTimeZone::listAbbreviations();
        $tz = strtolower($tz);
        if (isset($codes[$tz])) {
            return $codes[$tz][0]['timezone_id'];
        }
        throw new Exception("Unknown timezone: '$tz'.");
    }
}
