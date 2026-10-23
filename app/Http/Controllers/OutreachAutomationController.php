<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EmailTemplate;
use App\Models\OutreachSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OutreachAutomationController extends Controller
{
    public function show()
    {
        // Must be admin
        if (Auth::user()->role !== 'admin') {
            abort(403, 'Unauthorized. Admin privilege is required to access Outreach Automation Settings.');
        }

        $settings = OutreachSetting::current();
        $templates = EmailTemplate::where('is_active', true)->get();

        $availableServices = [
            'laravel_php' => 'Laravel & PHP Development',
            'custom_software' => 'Custom Software & Web Apps',
            'web_dev' => 'Website Design & Development',
            'ecommerce' => 'E-Commerce Development',
            'erp_pos' => 'Cloud ERP & POS Systems',
            'api_integration' => 'API Development & Integration',
            'saas_mvp' => 'SaaS MVP Development',
            'mobile_app' => 'Mobile Application Development',
            'ai_automation' => 'AI Automation & Workflows',
            'database_optimization' => 'Database Design & Optimization',
            'maintenance_support' => 'Software Maintenance & Support',
        ];
        // v3/v4 service catalog (config/leadpilot.php) — the keys autopilot campaigns use
        foreach (config('leadpilot.services') as $k => $def) {
            $availableServices[$k] ??= $def['label'];
        }

        $availableCountries = [
            'Pakistan' => 'Pakistan (Domestic)',
            'United States' => 'United States (North America)',
            'United Kingdom' => 'United Kingdom (Europe)',
            'Canada' => 'Canada (North America)',
            'Australia' => 'Australia (Oceania)',
            'Germany' => 'Germany (European Union)',
        ];

        return view('settings.outreach_automation', compact('settings', 'templates', 'availableServices', 'availableCountries'));
    }

    public function update(Request $request)
    {
        if (Auth::user()->role !== 'admin') {
            abort(403, 'Unauthorized.');
        }

        $data = $request->validate([
            'sender_name' => ['required', 'string', 'max:255'],
            'sender_email' => ['required', 'email', 'max:255'],
            'sending_domain' => ['required', 'string', 'max:255'],
            'reply_to_email' => ['nullable', 'email', 'max:255'],
            'min_lead_score' => ['required', 'integer', 'min:' . \App\Support\ScoreBands::nurture(), 'max:100'],
            'min_evidence_confidence' => ['required', 'integer', 'min:40', 'max:100'],
            'require_verified_email' => ['nullable', 'boolean'],
            'allow_shared_inbox' => ['nullable', 'boolean'],
            'allow_mx_only' => ['nullable', 'boolean'],
            'max_daily_sends' => ['required', 'integer', 'min:1', 'max:500'],
            'max_sends_per_recipient' => ['required', 'integer', 'min:1', 'max:5'],
            'sending_window_start' => ['required', 'string'],
            'sending_window_end' => ['required', 'string'],
            'sending_timezone' => ['required', 'string'],
            'follow_up_delay_days' => ['required', 'integer', 'min:1', 'max:30'],
            'max_follow_ups' => ['required', 'integer', 'min:0', 'max:5'],
            'controlled_pilot_recipient' => ['nullable', 'email', 'max:255'],
            'allowed_countries' => ['nullable', 'array'],
            'approved_services' => ['nullable', 'array'],
        ]);

        $settings = OutreachSetting::current();
        $data['require_verified_email'] = $request->has('require_verified_email');
        $data['allow_shared_inbox'] = $request->has('allow_shared_inbox');
        $data['allow_mx_only'] = $request->has('allow_mx_only');
        $data['allowed_countries'] = $request->input('allowed_countries', []);
        $data['approved_services'] = $request->input('approved_services', []);

        $settings->update($data);

        AuditLog::record('outreach_settings_updated', $settings, null, [
            'updated_by' => Auth::user()->email,
        ]);

        return redirect()->route('settings.outreach-automation')
            ->with('success', 'Outreach automation configuration updated successfully.');
    }

    public function activate(Request $request)
    {
        if (Auth::user()->role !== 'admin') {
            abort(403, 'Unauthorized.');
        }

        $targetMode = $request->validate([
            'mode' => ['required', 'in:dry_run,controlled_pilot,live'],
            'controlled_pilot_recipient' => ['nullable', 'email'],
            'confirmation' => ['required', 'string'],
        ]);

        $settings = OutreachSetting::current();

        if ($targetMode['mode'] === 'controlled_pilot') {
            if (empty($targetMode['controlled_pilot_recipient'])) {
                return back()->withErrors(['controlled_pilot_recipient' => 'Controlled pilot requires an authorized test email address that you own.']);
            }
            $settings->controlled_pilot_recipient = strtolower(trim($targetMode['controlled_pilot_recipient']));
        }

        if ($targetMode['mode'] === 'live' && strtolower($targetMode['confirmation']) !== 'activate live outreach') {
            return back()->withErrors(['confirmation' => 'Please type exactly "ACTIVATE LIVE OUTREACH" to confirm live outreach activation.']);
        }

        $settings->mode = $targetMode['mode'];
        $settings->activated_at = now();
        $settings->activated_by = Auth::id();
        $settings->save();

        AuditLog::record('outreach_mode_changed', $settings, null, [
            'new_mode' => $targetMode['mode'],
            'activated_by' => Auth::user()->email,
        ]);

        return redirect()->route('settings.outreach-automation')
            ->with('success', "Outreach mode changed to " . strtoupper(str_replace('_', ' ', $targetMode['mode'])) . " successfully.");
    }

    public function toggleEmergencyStop(Request $request)
    {
        if (Auth::user()->role !== 'admin') {
            abort(403, 'Unauthorized.');
        }

        $settings = OutreachSetting::current();
        $newState = !$settings->emergency_stop;
        $reason = $request->input('reason', $newState ? 'Manually triggered by administrator via dashboard.' : null);

        $settings->emergency_stop = $newState;
        $settings->emergency_stop_reason = $newState ? $reason : null;
        $settings->save();

        AuditLog::record('emergency_stop_toggled', $settings, null, [
            'active' => $newState,
            'reason' => $reason,
            'triggered_by' => Auth::user()->email,
        ]);

        $statusText = $newState ? 'EMERGENCY STOP ENGAGED! All outbound sends halted immediately.' : 'Emergency stop disengaged. Normal sending operations restored.';
        return redirect()->route('settings.outreach-automation')->with($newState ? 'warning' : 'success', $statusText);
    }
}
