@extends('layouts.app')

@section('title', 'Outreach Automation Settings')

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <h3 class="fw-bold mb-1">Outreach Automation Engine</h3>
        <p class="text-muted small mb-0">Configure autonomous outbound rules, eligibility thresholds, approved services, and safety killswitch controls.</p>
    </div>

    <div class="d-flex align-items-center gap-2">
        <!-- Emergency Stop Trigger -->
        <form method="POST" action="{{ route('settings.outreach-automation.emergency-stop') }}" class="d-inline">
            @csrf
            @if($settings->isEmergencyStopped())
                <button type="submit" class="btn btn-outline-success btn-sm fw-bold px-3">
                    <i class="bi bi-play-circle me-1"></i> Disengage Emergency Stop
                </button>
            @else
                <button type="submit" class="btn btn-danger btn-sm fw-bold px-3 shadow-sm">
                    <i class="bi bi-slash-circle me-1"></i> Emergency Killswitch
                </button>
            @endif
        </form>

        <a href="{{ route('settings.integrations') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-sliders me-1"></i> API Credentials
        </a>
    </div>
</div>

<!-- Mode Status Banner -->
<div class="card mb-4 border-0 shadow-sm {{ $settings->isEmergencyStopped() ? 'bg-danger-subtle border-start border-danger border-5' : ($settings->isLive() ? 'bg-success-subtle border-start border-success border-5' : ($settings->isControlledPilot() ? 'bg-warning-subtle border-start border-warning border-5' : 'bg-primary-subtle border-start border-primary border-5')) }}">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge {{ $settings->isEmergencyStopped() ? 'bg-danger' : ($settings->isLive() ? 'bg-success' : ($settings->isControlledPilot() ? 'bg-warning text-dark' : 'bg-primary')) }} px-3 py-2 text-uppercase fs-6">
                        @if($settings->isEmergencyStopped())
                            <i class="bi bi-exclamation-octagon-fill me-1"></i> Emergency Stopped
                        @elseif($settings->isLive())
                            <i class="bi bi-broadcast me-1"></i> Live Autonomous Mode
                        @elseif($settings->isControlledPilot())
                            <i class="bi bi-shield-check me-1"></i> Controlled Pilot Mode
                        @else
                            <i class="bi bi-cone-striped me-1"></i> Dry-Run Mode (Safe)
                        @endif
                    </span>
                    @if($settings->activated_at)
                        <span class="text-muted small">Active since {{ $settings->activated_at->format('M d, Y H:i') }}</span>
                    @endif
                </div>
                <p class="mb-0 small text-dark mt-2">
                    @if($settings->isEmergencyStopped())
                        <strong class="text-danger">ALL OUTBOUND OUTREACH IS FROZEN.</strong> {{ $settings->emergency_stop_reason ?: 'Global killswitch is active.' }}
                    @elseif($settings->isLive())
                        Eligible prospects meeting all 10 qualification checks will receive outreach automatically within configured sending hours.
                    @elseif($settings->isControlledPilot())
                        Emails are restricted <strong>EXCLUSIVELY</strong> to authorized test recipient: <code>{{ $settings->controlled_pilot_recipient }}</code>. Real prospects are safely held in review.
                    @else
                        Safe testing mode. Leads are discovered, classified, and scored; drafts are prepared, but <strong>NO outbound emails are sent</strong>.
                    @endif
                </p>
            </div>

            <!-- Mode Switcher Buttons -->
            <div class="btn-group shadow-sm">
                <button type="button" class="btn btn-sm {{ $settings->isDryRun() ? 'btn-primary active fw-bold' : 'btn-outline-secondary' }}" data-bs-toggle="modal" data-bs-target="#modeModal" onclick="setTargetMode('dry_run')">
                    Dry-Run
                </button>
                <button type="button" class="btn btn-sm {{ $settings->isControlledPilot() ? 'btn-warning active fw-bold' : 'btn-outline-secondary' }}" data-bs-toggle="modal" data-bs-target="#modeModal" onclick="setTargetMode('controlled_pilot')">
                    Controlled Pilot
                </button>
                <button type="button" class="btn btn-sm {{ $settings->isLive() ? 'btn-success active fw-bold' : 'btn-outline-secondary' }}" data-bs-toggle="modal" data-bs-target="#modeModal" onclick="setTargetMode('live')">
                    Activate Live
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Main Outreach Configuration Form -->
<form method="POST" action="{{ route('settings.outreach-automation.update') }}">
    @csrf
    @method('PUT')

    <div class="row g-4">
        <!-- Column 1: Sender Identity & Schedule -->
        <div class="col-lg-6">
            <div class="card card-lp p-4 h-100 shadow-sm border-0">
                <h5 class="fw-bold mb-3 text-dark border-bottom pb-2">
                    <i class="bi bi-person-badge text-primary me-2"></i>Sender Identity & Domain
                </h5>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Sender Display Name</label>
                    <input type="text" name="sender_name" class="form-control" value="{{ old('sender_name', $settings->sender_name) }}" required>
                    <div class="form-text">e.g., LeadPilot Software Consulting, Alex Vance</div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Sender Email Address</label>
                        <input type="email" name="sender_email" class="form-control" value="{{ old('sender_email', $settings->sender_email) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Verified Sending Domain</label>
                        <input type="text" name="sending_domain" class="form-control" value="{{ old('sending_domain', $settings->sending_domain) }}" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold">Reply-To Email Address</label>
                    <input type="email" name="reply_to_email" class="form-control" value="{{ old('reply_to_email', $settings->reply_to_email) }}">
                    <div class="form-text">Inbound prospect replies will be routed to this inbox for classification.</div>
                </div>

                <h5 class="fw-bold mb-3 text-dark border-bottom pb-2 pt-2">
                    <i class="bi bi-clock-history text-primary me-2"></i>Sending Windows & Quotas
                </h5>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Daily Send Cap</label>
                        <input type="number" name="max_daily_sends" class="form-control" value="{{ old('max_daily_sends', $settings->max_daily_sends) }}" min="1" max="500" required>
                        <div class="form-text">Maximum emails dispatched per 24 hours.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Max Sends Per Recipient</label>
                        <input type="number" name="max_sends_per_recipient" class="form-control" value="{{ old('max_sends_per_recipient', $settings->max_sends_per_recipient) }}" min="1" max="5" required>
                        <div class="form-text">Total emails one contact can get: first email + follow-ups must fit inside this.</div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Window Start</label>
                        <input type="time" name="sending_window_start" class="form-control" value="{{ old('sending_window_start', $settings->sending_window_start) }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Window End</label>
                        <input type="time" name="sending_window_end" class="form-control" value="{{ old('sending_window_end', $settings->sending_window_end) }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Timezone</label>
                        <input type="text" name="sending_timezone" class="form-control" value="{{ old('sending_timezone', $settings->sending_timezone) }}" required>
                    </div>
                </div>
                <div class="alert alert-info small py-2 mb-3">
                    <i class="bi bi-globe2 me-1"></i>This window applies to <strong>manual campaigns</strong> only. Autopilot e-mails go out between
                    {{ config('leadpilot.autopilot.sending_hours')[0] }}:00 and {{ config('leadpilot.autopilot.sending_hours')[1] }}:00 on working days
                    <strong>in each prospect's own time zone</strong> (a New York lead at 9 am New York time = 6 pm Pakistan time).
                </div>

                <div class="row g-3 mb-2">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Follow-Up Delay (Days)</label>
                        <input type="number" name="follow_up_delay_days" class="form-control" value="{{ old('follow_up_delay_days', $settings->follow_up_delay_days) }}" min="1" max="30" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Max Follow-Ups</label>
                        <input type="number" name="max_follow_ups" class="form-control @error('max_follow_ups') is-invalid @enderror" value="{{ old('max_follow_ups', $settings->max_follow_ups) }}" min="0" max="5" required>
                        @error('max_follow_ups')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Each sequence sends {{ $settings->maxTouches() }} email(s) in total.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Column 2: Qualification Thresholds & Approved Scope -->
        <div class="col-lg-6">
            <div class="card card-lp p-4 h-100 shadow-sm border-0">
                <h5 class="fw-bold mb-3 text-dark border-bottom pb-2">
                    <i class="bi bi-shield-check text-primary me-2"></i>Qualification & Eligibility Gate
                </h5>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Minimum Lead Score</label>
                        <div class="input-group">
                            <input type="number" name="min_lead_score" class="form-control" value="{{ old('min_lead_score', $settings->min_lead_score) }}" min="{{ \App\Support\ScoreBands::nurture() }}" max="100" required>
                            <span class="input-group-text">/ 100</span>
                        </div>
                        <div class="form-text">Threshold for automated outreach eligibility. Recommended: the Qualified band ({{ \App\Support\ScoreBands::qualified() }}+), set in Settings → Lead Scoring.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Minimum Evidence Confidence</label>
                        <div class="input-group">
                            <input type="number" name="min_evidence_confidence" class="form-control" value="{{ old('min_evidence_confidence', $settings->min_evidence_confidence) }}" min="40" max="100" required>
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text">Filters out weak or unconfirmed buying signals.</div>
                    </div>
                </div>

                <div class="form-check form-switch mb-4 p-3 bg-light rounded border">
                    <input class="form-check-input ms-0 me-3" type="checkbox" id="require_verified_email" name="require_verified_email" value="1" {{ old('require_verified_email', $settings->require_verified_email) ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="require_verified_email">
                        Enforce Email Verification
                        <span class="d-block small text-muted fw-normal">Only dispatch to addresses whose mailbox was confirmed by an SMTP probe or Hunter (catch-all domains included).</span>
                    </label>
                </div>

                <div class="form-check form-switch mb-4 p-3 bg-light rounded border">
                    <input class="form-check-input ms-0 me-3" type="checkbox" id="allow_mx_only" name="allow_mx_only" value="1" {{ old('allow_mx_only', $settings->allow_mx_only) ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="allow_mx_only">
                        Also allow "MX only" addresses
                        <span class="d-block small text-muted fw-normal">The domain receives mail but the mailbox itself was never checked (this server cannot reach port 25 and Hunter was not used). Leave off unless you accept a higher bounce risk.</span>
                    </label>
                </div>

                <div class="form-check form-switch mb-4 p-3 bg-light rounded border">
                    <input class="form-check-input ms-0 me-3" type="checkbox" id="allow_shared_inbox" name="allow_shared_inbox" value="1" {{ old('allow_shared_inbox', $settings->allow_shared_inbox) ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="allow_shared_inbox">
                        Allow a business's own published inbox
                        <span class="d-block small text-muted fw-normal">Local businesses (clinics, shops, small firms) publish one address such as info@ or a Gmail box and have no "named decision maker" to find. On: that verified address may receive the evidence-based first email. Published tender contacts are never emailed automatically.</span>
                    </label>
                </div>

                <h5 class="fw-bold mb-3 text-dark border-bottom pb-2">
                    <i class="bi bi-globe text-primary me-2"></i>Target Markets & Countries
                </h5>
                <div class="row g-2 mb-4">
                    @foreach($availableCountries as $code => $countryLabel)
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="allowed_countries[]" value="{{ $code }}" id="country_{{ Str::slug($code) }}" {{ in_array($code, $settings->allowed_countries ?? []) ? 'checked' : '' }}>
                            <label class="form-check-label small" for="country_{{ Str::slug($code) }}">
                                {{ $countryLabel }}
                            </label>
                        </div>
                    </div>
                    @endforeach
                </div>

                <h5 class="fw-bold mb-3 text-dark border-bottom pb-2">
                    <i class="bi bi-code-slash text-primary me-2"></i>Approved Software Services
                </h5>
                <div class="row g-2 mb-3">
                    @foreach($availableServices as $srvKey => $srvTitle)
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="approved_services[]" value="{{ $srvKey }}" id="srv_{{ $srvKey }}" {{ in_array($srvKey, $settings->approved_services ?? []) ? 'checked' : '' }}>
                            <label class="form-check-label small" for="srv_{{ $srvKey }}">
                                {{ $srvTitle }}
                            </label>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <!-- Submit Toolbar -->
    <div class="mt-4 p-3 bg-white rounded border shadow-sm d-flex justify-content-between align-items-center">
        <span class="text-muted small">Changes will take effect on the next scheduled autonomous outreach cycle.</span>
        <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
            <i class="bi bi-check2-circle me-1"></i> Save Configuration
        </button>
    </div>
</form>

<!-- Mode Switch Modal -->
<div class="modal fade" id="modeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="{{ route('settings.outreach-automation.activate') }}">
                @csrf
                <input type="hidden" name="mode" id="targetModeInput" value="dry_run">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modeModalTitle">Confirm Outreach Mode Change</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div id="dryRunHelp" class="d-none">
                        <p class="text-muted">In <strong>Dry-Run Mode</strong>, all discovery, signal classification, and scoring continue as normal, but <strong>no outbound emails will be sent</strong>.</p>
                        <input type="hidden" name="confirmation" id="dryRunConf" value="confirm">
                    </div>

                    <div id="pilotHelp" class="d-none">
                        <div class="alert alert-warning small">
                            <i class="bi bi-shield-lock-fill me-1"></i> <strong>Controlled Pilot Safeguard:</strong> Real prospects are NOT contacted. The system will ONLY deliver emails to your verified test recipient.
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Authorized Test Email Recipient</label>
                            <input type="email" name="controlled_pilot_recipient" class="form-control" value="{{ $settings->controlled_pilot_recipient ?: Auth::user()->email }}" placeholder="your-email@example.com">
                            <div class="form-text">Must be an email address you own and are authorized to test.</div>
                        </div>
                        <input type="hidden" name="confirmation" id="pilotConf" value="confirm">
                    </div>

                    <div id="liveHelp" class="d-none">
                        <div class="alert alert-danger small">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> <strong>Live Outreach Activation:</strong> The system will automatically begin sending outreach emails to prospects who satisfy all 10 qualification criteria.
                        </div>
                        <p class="small text-muted mb-2">To prevent accidental live sends, please type <strong>ACTIVATE LIVE OUTREACH</strong> below:</p>
                        <input type="text" name="confirmation" id="liveConf" class="form-control" placeholder="ACTIVATE LIVE OUTREACH">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold" id="modeSubmitBtn">Apply Mode</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function setTargetMode(mode) {
    document.getElementById('targetModeInput').value = mode;
    document.getElementById('dryRunHelp').classList.add('d-none');
    document.getElementById('pilotHelp').classList.add('d-none');
    document.getElementById('liveHelp').classList.add('d-none');

    const btn = document.getElementById('modeSubmitBtn');

    if (mode === 'dry_run') {
        document.getElementById('dryRunHelp').classList.remove('d-none');
        document.getElementById('modeModalTitle').innerText = 'Switch to Dry-Run Mode';
        btn.className = 'btn btn-primary fw-bold';
        btn.innerText = 'Switch to Dry-Run';
    } else if (mode === 'controlled_pilot') {
        document.getElementById('pilotHelp').classList.remove('d-none');
        document.getElementById('modeModalTitle').innerText = 'Activate Controlled Pilot';
        btn.className = 'btn btn-warning fw-bold text-dark';
        btn.innerText = 'Activate Controlled Pilot';
    } else if (mode === 'live') {
        document.getElementById('liveHelp').classList.remove('d-none');
        document.getElementById('modeModalTitle').innerText = 'Activate Live Outreach';
        btn.className = 'btn btn-danger fw-bold';
        btn.innerText = 'Confirm Live Outreach';
    }
}
</script>
@endsection
