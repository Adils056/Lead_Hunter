<?php

namespace App\Services\Outreach;

use App\Models\OutreachSetting;
use App\Services\Autopilot\AutonomyPolicyService;
use App\Services\Autopilot\WorkflowControlService;

/**
 * Hard stops that apply to EVERY email to a prospect — automated or human-approved:
 * emergency stop, dry-run mode, controlled-pilot recipient, paused workflow, prohibited action.
 * (Sending window and caps apply to scheduled sends; human-approved sends outside the window are deferred.)
 */
class OutreachGuard
{
    /** @return string|null reason the send must not happen now */
    public static function blockReason(string $workflow, ?string $action = null, ?string $recipient = null): ?string
    {
        $s = OutreachSetting::current();
        if ($s->isEmergencyStopped()) {
            return 'Global emergency stop is active.';
        }
        if ($s->isDryRun()) {
            return 'System is in DRY-RUN mode: no email is sent to prospects (switch mode in Settings → Outreach Engine).';
        }
        if ($s->isControlledPilot() && $recipient) {
            $pilot = strtolower(trim((string) $s->controlled_pilot_recipient));
            if ($pilot !== '' && strtolower(trim($recipient)) !== $pilot) {
                return "Controlled-pilot mode: only {$pilot} may receive email.";
            }
        }
        if ($s->isLive() && $recipient && OutreachSetting::publicUrlIsLocal()
            && strtolower(trim($recipient)) !== strtolower(trim((string) $s->controlled_pilot_recipient))) {
            return 'APP_URL is a local address: unsubscribe links would not open for the prospect. Set a public APP_URL before live sending.';
        }
        if (app(WorkflowControlService::class)->isPaused($workflow)) {
            return ucfirst(str_replace('_', ' ', $workflow)) . ' workflow is paused.';
        }
        if ($action && app(AutonomyPolicyService::class)->isProhibited($action)) {
            return "Action {$action} is prohibited by the Autonomy policy.";
        }

        return null;
    }
}
