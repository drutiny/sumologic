<?php

namespace Drutiny\SumoLogic\Audit;

use DateTimeZone;
use Drutiny\Attribute\UseService;
use Drutiny\Audit\AbstractAnalysis;
use Drutiny\Audit\AuditInterface;
use Drutiny\Sandbox\Sandbox;
use Drutiny\SumoLogic\Client;
use Exception;

/**
 *
 */
#[UseService(Client::class, 'setApiClient')]
class QueryAnalysis extends AbstractAnalysis
{
    protected Client $api;

    /**
     * Set the Sumologic API Client to the audit class.
     */
    public function setApiClient(Client $api):void
    {
        $this->api = $api;
    }

    /**
     * {@inheritdoc}
     */
    public function configure():void
    {
        
        $this->addParameter(
            'query',
            AuditInterface::PARAMETER_REQUIRED,
            'The sumologic query to send to the API'
        );
        
        $this->addParameter(
            'from',
            AuditInterface::PARAMETER_OPTIONAL,
            'The reporting date to start from. e.g. -24 hours.',
            null,
        );
        $this->addParameter(
            'to',
            AuditInterface::PARAMETER_OPTIONAL,
            'The reporting date to end on. e.g. now.',
            null
        );
        $this->addParameter(
            'timezone',
            AuditInterface::PARAMETER_OPTIONAL,
            'The timeZone the dates refer to.',
            null,
        );
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function gather(Sandbox $sandbox)
    {
        $query = $this->interpolate($this->getParameter('query'), [
            'timeslice' => $this->getTimeslice(),
        ]);

        $options = [];

        $options['from']     = $this->getReportingPeriodStart()->format(\DateTime::ATOM);
        $options['to']       = $this->getReportingPeriodEnd()->format(\DateTime::ATOM);
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
        $records = $this->api->query($query, $options, function ($records) {
            foreach ($records as &$record) {
                if (isset($record['_timeslice'])) {
                    $record['_timeslice'] = date('Y-m-d H:i:s', $record['_timeslice']/1000);
                }
            }
            $this->set('records', $records);
            return $records;
        });

        if (($globals = $this->getParameter('globals', [])) && $row = reset($records)) {
            foreach ($globals as $key) {
                $this->set($key, $row[$key]);
            }
        }
    }

    /**
     * Get the timeslice for a query.
     */
    protected function getTimeslice():string {
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
    protected function getTimezone():string
    {

        $tz = $this->getReportingPeriodStart()->getTimeZone()->getName();

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
