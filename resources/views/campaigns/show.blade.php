@extends('layouts.app')

@section('title', $campaign->name . ' — Campaign Details')

@section('content')
<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-2" style="font-size: 0.825rem;">
            <li class="breadcrumb-item"><a href="{{ route('campaigns.index') }}">Campaigns</a></li>
            <li class="breadcrumb-item active">{{ $campaign->name }}</li>
        </ol>
    </nav>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h3 class="fw-bold mb-0">{{ $campaign->name }}</h3>
                @if($campaign->emergency_stopped)
                    <span class="badge bg-danger">HALTED (Emergency Stop)</span>
                @elseif($campaign->status === 'running')
                    <span class="badge bg-success">Running</span>
                @elseif($campaign->status === 'approved')
                    <span class="badge bg-info text-dark">Approved</span>
                @elseif($campaign->status === 'paused')
                    <span class="badge bg-warning text-dark">Paused</span>
                @else
                    <span class="badge bg-secondary">Draft / Awaiting Review</span>
                @endif
            </div>
            <div class="d-flex flex-wrap align-items-center gap-3 text-muted small mt-1">
                <span><i class="bi bi-tag me-1"></i>{{ $campaign->service_offered }}</span>
                <span><i class="bi bi-speedometer2 me-1"></i>Daily Quota: {{ $campaign->sent_today }} / {{ $campaign->daily_limit }} sent today</span>
                <span><i class="bi bi-person me-1"></i>Created by {{ $campaign->creator?->name }}</span>
                @if($campaign->approved_by)
                    <span><i class="bi bi-shield-check text-success me-1"></i>Approved by {{ $campaign->approver?->name }}</span>
                @endif
            </div>
        </div>

        <div class="d-flex gap-2">
            @if(Auth::user()?->isAdmin() && $campaign->status === 'draft')
            <form action="{{ route('campaigns.approve', $campaign) }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-sm btn-success d-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-check-circle"></i>
                    <span>Approve & Start Campaign</span>
                </button>
            </form>
            @endif

            @if($campaign->status === 'running')
            <form action="{{ route('campaigns.pause', $campaign) }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-sm btn-light border text-warning d-flex align-items-center gap-1">
                    <i class="bi bi-pause-circle"></i>
                    <span>Pause</span>
                </button>
            </form>
            @elseif($campaign->status === 'paused' && !$campaign->emergency_stopped)
            <form action="{{ route('campaigns.resume', $campaign) }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-sm btn-light border text-success d-flex align-items-center gap-1">
                    <i class="bi bi-play-circle"></i>
                    <span>Resume</span>
                </button>
            </form>
            @endif

            @if(!$campaign->emergency_stopped && Auth::user()?->isAdmin())
            <form action="{{ route('campaigns.emergency-stop', $campaign) }}" method="POST" onsubmit="return confirm('WARNING: Are you sure you want to trigger the global emergency stop for this campaign? All outbound queues will halt immediately.')">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-danger d-flex align-items-center gap-1">
                    <i class="bi bi-radioactive"></i>
                    <span>Emergency Stop</span>
                </button>
            </form>
            @endif
        </div>
    </div>
</div>

<!-- Metrics Bar -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3 col-xl">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Enrolled</div>
            <div class="fs-4 fw-bold mt-1 text-dark">{{ $metrics['total'] }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Drafts</div>
            <div class="fs-4 fw-bold mt-1 text-secondary">{{ $metrics['draft'] }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Approved</div>
            <div class="fs-4 fw-bold mt-1 text-info">{{ $metrics['approved'] }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Dispatched</div>
            <div class="fs-4 fw-bold mt-1 text-primary">{{ $metrics['sent'] }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Delivered</div>
            <div class="fs-4 fw-bold mt-1 text-success">{{ $metrics['delivered'] }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Replies</div>
            <div class="fs-4 fw-bold mt-1 text-purple" style="color: #6366f1;">{{ $metrics['replied'] }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Bounced</div>
            <div class="fs-4 fw-bold mt-1 text-danger">{{ $metrics['bounced'] }}</div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Test Email Dispatch Card -->
    <div class="col-lg-6">
        <div class="card-lp p-4 h-100">
            <h6 class="fw-bold mb-2"><i class="bi bi-send-exclamation text-primary me-1"></i> Send Safe Test Email</h6>
            <p class="text-muted small mb-3">Send a sample formatted email to an administrative inbox before approving live sends.</p>
            <form action="{{ route('campaigns.test-email', $campaign) }}" method="POST">
                @csrf
                <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
                    <input type="email" name="test_email" class="form-control" placeholder="admin@leadpilot.test" value="{{ Auth::user()->email }}" required>
                    <button type="submit" class="btn btn-primary-lp">Send Test</button>
                </div>
                <div class="form-text" style="font-size: 0.725rem;">Renders safe dummy variables with real unsubscribe links.</div>
            </form>
        </div>
    </div>

    <!-- Campaign Template Details -->
    <div class="col-lg-6">
        <div class="card-lp p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0"><i class="bi bi-file-text text-info me-1"></i> Template Preview</h6>
                <span class="badge bg-light text-dark border">{{ $campaign->template?->name ?? 'Standard Template' }}</span>
            </div>
            <div class="small fw-semibold text-dark mb-1">Subject Formula:</div>
            <div class="small text-muted p-2 bg-light rounded border mb-2 font-monospace">
                {{ $campaign->template?->subject }}
            </div>
        </div>
    </div>
</div>

<!-- Enrolled Prospects & Queue Table -->
<div class="card-lp overflow-hidden mb-4">
    <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-light-subtle">
        <h6 class="fw-bold mb-0">Enrolled Prospects & Outreach Drafts ({{ $recipients->total() }})</h6>
        <span class="small text-muted">Suppressed emails are automatically excluded</span>
    </div>

    <div class="table-responsive">
        <table class="table table-lp align-middle">
            <thead>
                <tr>
                    <th>Prospect</th>
                    <th>Personalized Subject</th>
                    <th class="text-center">Status</th>
                    <th>Scheduled / Sent</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($recipients as $rec)
                <tr>
                    <td>
                        <a href="{{ route('leads.show', $rec->lead) }}" class="fw-bold text-dark text-decoration-none">
                            {{ $rec->lead->full_name }}
                        </a>
                        <div class="small text-muted">{{ $rec->lead->email }} • {{ $rec->lead->company?->name ?? 'Direct' }}</div>
                    </td>

                    <td>
                        <div class="small fw-semibold text-dark">{{ $rec->personalized_subject }}</div>
                        <div class="small text-muted text-truncate" style="max-width: 380px;">{{ Str::limit($rec->personalized_body, 80) }}</div>
                    </td>

                    <td class="text-center">
                        <span class="badge {{ $rec->status === 'delivered' ? 'bg-success' : ($rec->status === 'sent' ? 'bg-primary' : ($rec->status === 'replied' ? 'bg-purple' : ($rec->status === 'bounced' ? 'bg-danger' : ($rec->status === 'approved' ? 'bg-info text-dark' : 'bg-light text-secondary border')))) }}">
                            {{ ucfirst($rec->status) }}
                        </span>
                    </td>

                    <td class="small text-muted">
                        {{ $rec->sent_at ? $rec->sent_at->format('M d, Y H:i') : ($rec->scheduled_at ? 'Queued ' . $rec->scheduled_at->diffForHumans() : 'Draft') }}
                    </td>

                    <td class="text-end">
                        @if(in_array($rec->status, ['draft', 'approved', 'queued']))
                        <form action="{{ route('campaigns.recipient.dispatch', $rec) }}" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-primary py-0 px-2" title="Dispatch now via configured mailer">
                                <i class="bi bi-send me-1"></i> Send Now
                            </button>
                        </form>
                        @else
                        <span class="text-muted small"><i class="bi bi-check-all text-primary"></i> Processed</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center py-4 text-muted">No recipients found for this campaign.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($recipients->hasPages())
    <div class="p-3 border-top bg-light-subtle d-flex justify-content-end">
        {{ $recipients->links() }}
    </div>
    @endif
</div>
@endsection
