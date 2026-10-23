<?php

namespace App\Services;

class EligibilityResult
{
    public bool $isEligible;
    public array $passedChecks = [];
    public array $failedChecks = [];
    public ?string $primaryReason = null;
    public string $recommendedLifecycleStage = 'qualified';
    public array $metadata = [];

    public function __construct(bool $isEligible = true)
    {
        $this->isEligible = $isEligible;
    }

    public function pass(string $checkKey, string $description): void
    {
        $this->passedChecks[$checkKey] = $description;
    }

    public function fail(string $checkKey, string $reason, string $recommendedStage = 'ready_for_review'): void
    {
        $this->isEligible = false;
        $this->failedChecks[$checkKey] = $reason;
        if ($this->primaryReason === null) {
            $this->primaryReason = $reason;
            $this->recommendedLifecycleStage = $recommendedStage;
        }
    }

    public function toArray(): array
    {
        return [
            'is_eligible' => $this->isEligible,
            'passed_count' => count($this->passedChecks),
            'failed_count' => count($this->failedChecks),
            'passed_checks' => $this->passedChecks,
            'failed_checks' => $this->failedChecks,
            'primary_reason' => $this->primaryReason,
            'recommended_stage' => $this->recommendedLifecycleStage,
            'metadata' => $this->metadata,
        ];
    }
}
