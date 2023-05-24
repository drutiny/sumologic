<?php
namespace Drutiny\SumoLogic\Audit;

use Drutiny\Attribute\Parameter;
use Drutiny\Attribute\Type;
use Drutiny\Audit\AbstractAnalysis;
use Drutiny\SumoLogic\Client;

/**
 * Analyse a metrics query response from SumoLogic.
 */
#[Parameter(
    name: 'queries', 
    description: 'An array of arrays with query and rowID keys. E.g. [["query" => "...", "rowId" => "A"], ["query" => ... ]]', 
    mode: Parameter::REQUIRED,
    type: Type::ARRAY
)]
class MetricsAnalysis extends AbstractAnalysis
{
    /**
     * {@inheritdoc}
     */
    protected function gather(Client $api):void
    {
        $timeRange = [
            'type' => 'BeginBoundedTimeRange',
            'from' => [
                'type' => 'Iso8601TimeRangeBoundary',
                'iso8601Time' => $this->reportingPeriodStart->format('c'),
            ],
            'to' => [
                'type' => 'Iso8601TimeRangeBoundary',
                'iso8601Time' => $this->reportingPeriodEnd->format('c'),
            ]
        ];

        $this->set('metrics', $api->getMetricsQueries(
            queries: array_map(fn($q) => $this->processQuery($q), $this->getParameter('queries')),
            timeRange: $timeRange
        ));
    }

    protected function processQuery(array $query) {
        $query['query'] = $this->interpolate($query['query']);
        return $query;
    }
}