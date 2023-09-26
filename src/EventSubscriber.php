<?php

namespace Drutiny\SumoLogic;

use Drutiny\AuditResponse\AuditResponse;
use Drutiny\Console\Helper\User;
use Drutiny\Report\Report;
use Drutiny\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class EventSubscriber implements EventSubscriberInterface {

    /**
     * @var Array[]
     */
    private array $logs;

    public function __construct(protected Settings $settings, protected User $user)
    {
        
    }

    public static function getSubscribedEvents()
    {
        return [
            'report.create' => 'logReportCreate',
        ];
    }

    public function logReportCreate(Report $report):void {
        if (!$this->settings->has('sumologic.stats.collector')) {
            return;
        }
        $this->collectLog(
            type: 'report',
            uuid: $report->uuid,
            name: $report->profile->name,
            uri: $report->target->getUri(),
            targetType: get_class($report->target),
            id: $report->target->getId(),
            severity: $report->severity->value,
            passes: count(array_filter($report->results, fn(AuditResponse $r) => $r->state->isSuccessful())),
            failures: count(array_filter($report->results, fn(AuditResponse $r) => $r->state->isFailure())),
            warnings: count(array_filter($report->results, fn(AuditResponse $r) => $r->state->hasWarning())),
            errors: count(array_filter($report->results, fn(AuditResponse $r) => $r->state->hasError())),
            irrelevant: count(array_filter($report->results, fn(AuditResponse $r) => $r->state->isIrrelevant())),
            notices: count(array_filter($report->results, fn(AuditResponse $r) => $r->state->isNotice())),
            policies: count($report->results),
            start: $report->reportingPeriodStart->format('c'),
            end: $report->reportingPeriodEnd->format('c'),
            version: $this->settings->get('version'),
            timing: $report->timing,
            user: $this->user->getIdentity()
        );

        foreach ($report->results as $response) {
            $this->collectLog(
                type: 'policy',
                target: $report->target->getId(),
                uuid: $report->uuid,
                datetime: date('c', $response->timestamp),
                name: $response->policy->name,
                serverity: $response->getSeverity(),
                status: $response->state->name,
                timing: $response->timing,
                user: $this->user->getIdentity()
            );
        }
    }

    protected function collectLog(string $type, string|int|null|bool ...$fields):void {
        $this->logs[$type][] = $fields;
    }

    public function __destruct()
    {
        if (empty($this->logs)) {
            return;
        }
        $client = new Client();
        foreach ($this->logs as $type => $logs) {
            $payload = [];
            foreach ($logs as $log) {
                $payload[] = json_encode($log);
            }
            if (empty($payload)) {
                continue;
            }
            
            $response = $client->post($this->settings->get('sumologic.stats.collector'), [
                RequestOptions::BODY => implode(PHP_EOL, $payload),
                RequestOptions::HEADERS => [
                    'X-Sumo-Name' => $this->settings->get('name'),
                    'X-Sumo-Category' => $type,
                ]
            ]);
        }
    }
}