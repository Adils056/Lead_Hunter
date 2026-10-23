<?php

namespace App\Services\Autopilot;

use App\Models\Incident;

/**
 * Level-3 events: stop the affected workflow and notify admins immediately.
 * Deduplicated: an open incident with the same key is updated, not duplicated.
 */
class IncidentService
{
    public const PAUSES = [
        'bounce_spike' => ['outreach', 'follow_ups'],
        'complaint_spike' => ['outreach', 'follow_ups'],
        'duplicate_send' => ['outreach', 'follow_ups'],
        'spend_exceeded' => ['discovery', 'enrichment', 'ai_drafting'],
        'auth_failures' => [],                 // provider itself is disabled by ProviderHealthService
        'queue_stalled' => [],
        'scheduler_stalled' => [],
        'provider_disabled' => [],
        'notification_failures' => [],
        'data_quality' => ['discovery'],
        'security' => ['outreach', 'follow_ups', 'discovery'],
    ];

    public function raise(string $type, string $title, string $details, ?string $workflow = null, array $evidence = [], ?string $dedupeKey = null): Incident
    {
        $key = $dedupeKey ?: $type . ':' . ($workflow ?? 'global');
        $open = Incident::where('dedupe_key', $key)->whereIn('status', ['open', 'acknowledged'])->first();
        if ($open) {
            $open->update(['details' => $details, 'evidence' => $evidence]);

            return $open;
        }

        $incident = Incident::create([
            'type' => $type, 'severity' => in_array($type, ['security', 'duplicate_send', 'complaint_spike'], true) ? 'critical' : 'high',
            'workflow' => $workflow, 'dedupe_key' => $key, 'status' => 'open', 'title' => $title, 'details' => $details, 'evidence' => $evidence,
        ]);

        $paused = [];
        foreach (array_unique(array_filter(array_merge(self::PAUSES[$type] ?? [], $workflow ? [$workflow] : []))) as $wf) {
            app(WorkflowControlService::class)->pause($wf, "Incident #{$incident->id}: {$title}", $incident->id);
            $paused[] = $wf;
        }

        app(Notifier::class)->notifyRole('admin', [
            'title' => "🚨 {$title}",
            'priority' => $incident->severity,
            'what' => $details,
            'why' => 'Level-3 safety rule: the affected workflow was stopped automatically and needs a human decision before resuming.',
            'affected' => $paused ? 'Paused workflows: ' . implode(', ', $paused) : 'No workflow paused (monitoring alert).',
            'evidence' => $evidence,
            'attempted' => ['Stopped affected workflow(s) immediately', 'Recorded incident #' . $incident->id],
            'options' => ['Resolve and resume workflow', 'Keep paused and investigate'],
            'recommended' => 'Open the Autopilot dashboard, review the evidence, fix the cause, then resume.',
            'url' => route('autopilot.index'),
        ], "incident:{$incident->id}");

        return $incident;
    }

    public function resolve(Incident $incident, int $userId, bool $resumeWorkflows = true): void
    {
        $incident->update(['status' => 'resolved', 'resolved_by' => $userId, 'resolved_at' => now()]);
        if ($resumeWorkflows) {
            foreach (\App\Models\WorkflowControl::where('incident_id', $incident->id)->get() as $wc) {
                app(WorkflowControlService::class)->resume($wc->workflow);
            }
        }
    }
}
