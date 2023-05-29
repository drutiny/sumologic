<?php

namespace Drutiny\SumoLogic\Audit;

use DateTimeZone;
use Drutiny\Attribute\Parameter;
use Drutiny\Attribute\Type;
use Drutiny\Audit\AbstractAnalysis;
use Drutiny\Policy;
use Drutiny\SumoLogic\Client;
use Exception;

/**
 *
 */
#[Parameter(name: 'query', mode: Parameter::REQUIRED, description: 'The base sumologic query to search on top of', type: Type::STRING)]
#[Parameter(name: 'search_field', mode: Parameter::REQUIRED, description: 'The search field to check matches a given search phrase', type: Type::STRING)]
#[Parameter(name: 'search_phrase', mode: Parameter::REQUIRED, description: 'The search phrase to match against', type: Type::STRING)]
#[Parameter(name: 'group_by', mode: Parameter::REQUIRED, description: 'Additional fields to group by ontop of the search_phrase policy match.', type: Type::STRING)]
class LogMatchPolicyRouter extends AbstractAnalysis
{
    protected array $queries = [];
    protected array $groups = [];

    /**
     * {@inheritdoc}
     */
    public function prepare(Policy $policy):void
    {
        $policy = $this->prepareBuildParameters($policy);
        $key = $policy->parameters->get('query') . $policy->parameters->get('search_field');
        $this->queries[$key][$policy->name] = $policy->parameters->get('search_phrase');
        if (!in_array($policy->parameters->get('group_by'), $this->groups)) {
            $this->groups[] = $policy->parameters->get('group_by');
        }
    }

    /**
     * Prepare string to be within double-quoted Sumo query string.
     */
    protected function queryEscapeQuotes(string $search_phrase):string {
        return str_replace('"', '\"', $search_phrase);
    }

    /**
     * Extract start of string up to wildcard for Sumo matching
     */
    protected function queryTruncateToWildcard(string $search_phrase):string {
        $search_escaped_quotes = $this->queryEscapeQuotes($search_phrase);
        return preg_replace('/\*.*$/', '', $search_escaped_quotes);
    }

    /**
     * Surround query string with asterisks for matching, avoiding double quotes
     * and escaping quotes.
     */
    protected function queryAsteriskAndEscapeQuotes(string $search_phrase):string {
        $search_escaped_quotes = $this->queryEscapeQuotes($search_phrase);
        return preg_replace("/\\*\\**/", "*", "*{$search_escaped_quotes}*");
    }

    /**
     * {@inheritdoc}
     */
    protected function gather(Client $api)
    {
        $query_key = $this->getParameter('query') . $this->getParameter('search_field');
        $query = [$this->interpolate($this->getParameter('query'))];
        $keywords = [];
        foreach ($this->queries[$query_key] as $search_phrase) {
            $keywords[] = sprintf(' "%s"', $this->queryTruncateToWildcard($search_phrase));
        }
        $query[] = ' ( '.implode(' or ', $keywords) . ' )';
        $query[] = '| "" as policy_match';
        $field = $this->getParameter('search_field');
        foreach ($this->queries[$query_key] as $policy => $search_phrase) {
            $query[] = sprintf(
                '| if ('.$field.' matches "%s", "%s", policy_match) as policy_match',
                $this->queryAsteriskAndEscapeQuotes($search_phrase),
                $policy
            );
        }
        $query[] = '| count, first('.$field.') as first_match by policy_match, ' . implode(', ', $this->groups);

        // Combine query fragments.
        $query = implode(PHP_EOL, $query);

        $options = [];

        $options['from']     = $this->reportingPeriodStart->format(\DateTime::ATOM);
        $options['to']       = $this->reportingPeriodEnd->format(\DateTime::ATOM);
        $options['timeZone'] = $this->getTimezone();

        $this->logger->debug($query);
        $this->logger->notice("Waiting for SumoLogic query to return...");

        // Get cached records.
        $records = $api->query($query, $options, function ($records) {
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
