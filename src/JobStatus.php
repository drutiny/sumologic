<?php

namespace Drutiny\SumoLogic;

use RuntimeException;

enum JobStatus {

    CASE API_RATE_LIMITED;
    CASE JOB_NOT_STARTED;
    CASE JOB_IN_PROGRESS;
    CASE COMPLETE;
    CASE JOB_CANCELLED;

    static public function map(string $status):self
    {
        return match ($status) {
            'NOT STARTED'	                    => JobStatus::JOB_NOT_STARTED,
            'GATHERING RESULTS'	                => JobStatus::JOB_IN_PROGRESS,
            'GATHERING RESULTS FROM SUBQUERIES' => JobStatus::JOB_IN_PROGRESS,
            'DONE GATHERING RESULTS'	        => JobStatus::COMPLETE,
            'CANCELED'	                        => JobStatus::JOB_CANCELLED,
            default => throw new RuntimeException("So such status: $status")
        };
    }

    public function getDefinition():string
    {
        return match ($this) {
            JobStatus::JOB_NOT_STARTED => 'Search job has not been started yet.',
            JobStatus::JOB_IN_PROGRESS => 'Search job is still gathering more results, however results might already be available.',
            JobStatus::COMPLETE => 'Search job is done gathering results; the entire specified time range has been covered.',
            JobStatus::JOB_CANCELLED => 'The search job has been cancelled.'
        };
    }

    public function isComplete():bool
    {
        return $this == JobStatus::COMPLETE;
    }
}