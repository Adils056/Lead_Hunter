@extends('layouts.app')
@section('title', 'Autonomy policy')
@section('content')
@php
    $help = [
        'discovery.free' => 'Search free sources (OpenStreetMap, tenders, job boards)', 'discovery.paid' => 'Search paid sources (Google Places, Apollo) within budget',
        'enrichment.audit' => 'Audit prospect websites', 'enrichment.verify_email' => 'Verify emails (free DNS; Hunter within budget)', 'scoring.run' => 'Score & qualify leads',
        'drafting.ai' => 'Polish drafts with AI (facts-only guard)', 'outreach.first_touch' => 'Send the FIRST email to a new prospect', 'outreach.follow_up' => 'Send follow-ups in an approved thread (stops on any reply)',
        'reply.classify' => 'Read & classify replies', 'reply.auto_respond' => 'Answer prospect replies without review', 'crm.stage_update' => 'Move CRM stages from evidence',
        'meeting.propose_slots' => 'Offer meeting times', 'proposal.send' => 'Send a proposal (commercial)', 'integration.enable' => 'Enable a new integration', 'spend.over_budget' => 'Spend beyond budget',
    ];
    $locked = ['proposal.send', 'spend.over_budget', 'integration.enable'];
@endphp
<h3 class="fw-bold mb-1">Autonomy &amp; budgets</h3>
<p class="text-muted small">Automatic = runs on its own · Approval = pauses and notifies the responsible person · Prohibited = never runs. Commercial commitments can never be automatic.</p>
<div class="row g-4">
    <div class="col-lg-7">
        <form method="POST" action="{{ route('autopilot.policy.update') }}" class="card-lp p-3">@csrf @method('PUT')
            <table class="table table-sm small align-middle mb-3"><thead><tr class="text-muted"><th>Action</th><th>Mode</th></tr></thead><tbody>
            @foreach($modes as $action => $mode)
                <tr><td><code>{{ $action }}</code><div class="text-muted">{{ $help[$action] ?? '' }}</div></td>
                    <td><select name="modes[{{ $action }}]" class="form-select form-select-sm">
                        @foreach(['automatic','approval','prohibited'] as $m)
                            @if(!(in_array($action, $locked) && $m === 'automatic'))<option value="{{ $m }}" @selected($mode === $m)>{{ ucfirst($m) }}</option>@endif
                        @endforeach
                    </select></td></tr>
            @endforeach
            </tbody></table>
            <button class="btn btn-primary-lp btn-sm">Save policy</button>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="card-lp p-3 mb-3">
            <h6 class="fw-bold">Budgets (USD)</h6>
            <p class="small text-muted">Free-first: with no budget, paid providers are never called. Global defaults from .env: daily ${{ number_format($globalDaily / 100, 2) }}, monthly ${{ number_format($globalMonthly / 100, 2) }}.</p>
            <table class="table table-sm small"><thead><tr class="text-muted"><th>Scope</th><th>Ref</th><th>Period</th><th>Limit</th></tr></thead><tbody>
            @forelse($budgets as $b)<tr><td>{{ $b->scope }}</td><td>{{ $b->scope_ref ?? '—' }}</td><td>{{ $b->period }}</td><td>${{ number_format($b->limit_cents / 100, 2) }}</td></tr>
            @empty <tr><td colspan="4" class="text-muted">No custom budgets.</td></tr> @endforelse
            </tbody></table>
            <form method="POST" action="{{ route('autopilot.budget') }}" class="row g-2">@csrf
                <div class="col-6"><select name="scope" class="form-select form-select-sm"><option>global</option><option>provider</option><option>campaign</option><option>icp</option></select></div>
                <div class="col-6"><input name="scope_ref" class="form-control form-control-sm" placeholder="e.g. google_places"></div>
                <div class="col-6"><select name="period" class="form-select form-select-sm"><option>monthly</option><option>daily</option></select></div>
                <div class="col-6"><input name="limit_usd" type="number" step="0.01" min="0" class="form-control form-control-sm" placeholder="limit $"></div>
                <div class="col-12"><button class="btn btn-sm btn-outline-primary">Save budget</button></div>
            </form>
        </div>
    </div>
</div>
@endsection
