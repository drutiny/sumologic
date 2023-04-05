<?php

namespace Drutiny\SumoLogic\Audit;

use DateTimeZone;
use Drutiny\Attribute\UseService;
use Drutiny\Audit\AbstractAnalysis;
use Drutiny\Audit\AuditInterface;
use Drutiny\Policy;
use Drutiny\Sandbox\Sandbox;
use Drutiny\SumoLogic\Client;
use Exception;
use SebastianBergmann\Type\VoidType;

/**
 *
 */
#[UseService(Client::class, 'setApiClient')]
class LogMatchPolicyRouter extends AbstractAnalysis
{
    protected Client $api;

    protected array $queries = [];

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
            'The base sumologic query to search on top of'
        );
        $this->addParameter(
            'search_field',
            AuditInterface::PARAMETER_REQUIRED,
            'The search field to check matches a given search phrase',
        );
        
        $this->addParameter(
            'search_phrase',
            AuditInterface::PARAMETER_REQUIRED,
            'The search phrase to match against',
        );

        $this->addParameter(
            'group_by',
            AuditInterface::PARAMETER_REQUIRED,
            'Additional fields to group by ontop of the search_phrase policy match.',
        );

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    public function prepare(Policy $policy):void
    {
        $this->queries[$policy->parameters->get('query')][$policy->name] = $policy->parameters->get('search_phrase');
    }

    /**
     * {@inheritdoc}
     */
    protected function gather(Sandbox $sandbox)
    {
        $query_key = $this->getParameter('query');
        $query = $this->interpolate($query_key);
        $keywords = [];
        foreach ($this->queries[$query_key] as $search_phrase) {
            $keywords[] = sprintf(' "%s"', addslashes($search_phrase));
        }
        $query .= ' ( '.implode(' or ', $keywords) . ' )'.PHP_EOL;
        $query .= '| "" as policy_match'.PHP_EOL;
        $field = $this->getParameter('search_field');
        foreach ($this->queries[$query_key] as $policy => $search_phrase) {
            $query .= sprintf('| if ('.$field.' matches "*%s*", "%s", policy_match) as policy_match', addslashes($search_phrase), $policy).PHP_EOL;
        }
        $query .= '| count, first('.$field.') as first_match by policy_match, ' . $this->getParameter('group_by');

        $options = [];

        $options['from']     = $this->reportingPeriodStart->format(\DateTime::ATOM);
        $options['to']       = $this->reportingPeriodEnd->format(\DateTime::ATOM);
        $options['timeZone'] = $this->getTimezone();

        $this->logger->debug($query);
        $this->logger->notice("Waiting for SumoLogic query to return...");

        // Get cached records.
        $records = $this->api->query($query, $options, function ($records) {
            foreach ($records as &$record) {
                if (isset($record['_timeslice'])) {
                    $record['_timeslice'] = date('Y-m-d H:i:s', $record['_timeslice']/1000);
                }
            }
            return $records;
        });

        // Filter down to policy_match records.
        $records = array_filter($records, fn($r) => $r['policy_match'] == $this->policy->name);
        $this->set('records', $records);
    }

    /**
     * Get the timezone.
     */
    protected function getTimezone():string
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
