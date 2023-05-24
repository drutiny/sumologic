<?php
namespace Drutiny\SumoLogic\Audit;

use Drutiny\Attribute\Parameter;
use Drutiny\Attribute\Type;
use Drutiny\Attribute\UseService;
use Drutiny\Audit\AbstractAnalysis;
use Drutiny\Sandbox\Sandbox;
use Drutiny\SumoLogic\Client;

/**
 * Analyse a metrics query response from SumoLogic.
 */
#[UseService(Client::class, 'setApiClient')]
#[Parameter(
    name: 'queries', 
    description: 'The SumoLogic metrics QueryWithRowId object (array) to send to the API', 
    mode: Parameter::REQUIRED,
    type: Type::ARRAY
)]
class MetricsAnalysis extends AbstractAnalysis
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
    protected function gather(Sandbox $sandbox)
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

        $this->set('metrics', $this->api->getMetricsQueries(
            queries: array_map(fn($q) => $this->processQuery($q), $this->getParameter('queries')),
            timeRange: $timeRange
        ));
    }

    protected function processQuery(array $query) {
        $query['query'] = $this->interpolate($query['query']);
        return $query;
    }
}