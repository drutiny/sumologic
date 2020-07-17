<?php

namespace Drutiny\SumoLogic\Audit;

use Drutiny\SumoLogic\Client;
use Drutiny\Audit;
use Drutiny\Sandbox\Sandbox;

/**
 *
 */
abstract class ApiEnabledAudit extends Audit
{
    public function configure()
    {
        $this->addParameter(
            'from',
            static::PARAMETER_OPTIONAL,
            'The reporting date to start from. e.g. -24 hours.',
            false,
        );
        $this->addParameter(
            'to',
            static::PARAMETER_OPTIONAL,
            'The reporting date to end on. e.g. now.',
            false
        );
        $this->addParameter(
            'timezone',
            static::PARAMETER_OPTIONAL,
            'The timeZone the dates refer to.',
            false,
        );
    }

    protected function search(Sandbox $sandbox, $query)
    {
        // Inject a query comment that uniquely identifies this query
        // for performance monitoring purposes.
        $query = "// Drutiny:\n"  . $query;

        $steps = $sandbox->getReportingPeriodSteps();
        switch (true) {
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

        $this->set('sumologic_timeslice', $timeslice);
        $query = strtr(
            $query, [
            '@_timeslice' => $timeslice
            ]
        );

        $this->getLogger()->debug(get_class($this) . ': ' . $query);

        $client = $this->container->get('sumologic.api');

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

        if ($time = $this->getParameter('from')) {
            $options['from'] = date(\DateTime::ATOM, strtotime($time));
        }
        if ($time = $this->getParameter('to')) {
            $options['to'] = date(\DateTime::ATOM, strtotime($time));
        }
        if ($tz = $this->getParameter('timezone')) {
            $options['timeZone'] = $tz;
        }

        $sandbox
            ->logger()
            ->debug(get_class($this) . ': ' . print_r($options, true));

        $client->query($query, $options,
                function ($records) {
                    foreach ($records as &$record) {
                        if (isset($record['_timeslice'])) {
                            $record['_timeslice'] = date('Y-m-d H:i:s', $record['_timeslice']/1000);
                        }
                    }
                    $this->set('records', $records);
                }
            );
        return $this->get('records', []);
    }
}
