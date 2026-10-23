<?php

namespace App\Services\Autopilot;

use App\Models\WorkflowControl;

/** Per-workflow pause switches so an incident stops only the affected workflow. */
class WorkflowControlService
{
    public const WORKFLOWS = ['discovery', 'enrichment', 'outreach', 'follow_ups', 'reply_sync', 'ai_drafting'];

    public function isPaused(string $workflow): bool
    {
        return (bool) WorkflowControl::where('workflow', $workflow)->value('paused');
    }

    public function pause(string $workflow, string $reason, ?int $incidentId = null): void
    {
        WorkflowControl::updateOrCreate(['workflow' => $workflow], ['paused' => true, 'reason' => mb_substr($reason, 0, 255), 'incident_id' => $incidentId, 'paused_at' => now()]);
    }

    public function resume(string $workflow): void
    {
        WorkflowControl::updateOrCreate(['workflow' => $workflow], ['paused' => false, 'reason' => null, 'incident_id' => null, 'paused_at' => null]);
    }

    public function states(): array
    {
        $rows = WorkflowControl::all()->keyBy('workflow');

        return collect(self::WORKFLOWS)->mapWithKeys(fn ($w) => [$w => [
            'paused' => (bool) ($rows[$w]->paused ?? false),
            'reason' => $rows[$w]->reason ?? null,
            'since' => $rows[$w]->paused_at ?? null,
        ]])->all();
    }
}
