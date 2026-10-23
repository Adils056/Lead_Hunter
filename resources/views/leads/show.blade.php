@extends('layouts.app')

@section('title', $lead->full_name . ' — Lead Profile')

@section('content')
<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-2" style="font-size: 0.825rem;">
            <li class="breadcrumb-item"><a href="{{ route('leads.index') }}">Leads</a></li>
            <li class="breadcrumb-item active">{{ $lead->full_name }}</li>
        </ol>
    </nav>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h3 class="fw-bold mb-0">{{ $lead->full_name }}</h3>
                <span class="badge bg-light text-dark border">{{ ucwords(str_replace('_', ' ', $lead->lifecycle_stage)) }}</span>
                <span class="badge-lp {{ \App\Support\ScoreBands::badgeClass($lead->overall_score) }}">
                    Score: {{ $lead->overall_score }} / 100
                </span>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-3 text-muted small mt-1">
                <span><i class="bi bi-briefcase me-1"></i>{{ $lead->job_title ?: 'Role unknown' }}</span>
                @if($lead->company)
                    <span><i class="bi bi-building me-1"></i><a href="{{ route('companies.show', $lead->company) }}" class="text-decoration-none">{{ $lead->company->name }}</a></span>
                @endif
                <span><i class="bi bi-geo-alt me-1"></i>{{ $lead->country }}</span>
                <span><i class="bi bi-person me-1"></i>Assigned: {{ $lead->assignedUser?->name ?? 'Unassigned' }}</span>
            </div>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('assistant.show', $lead) }}" class="btn btn-sm btn-primary-lp d-flex align-items-center gap-1 shadow-sm">
                <i class="bi bi-magic"></i>
                <span>Sales Assistant</span>
            </a>
            <form action="{{ route('leads.rescore', $lead) }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-primary d-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-arrow-repeat text-primary"></i>
                    <span>Re-Evaluate Score</span>
                </button>
            </form>
            @if($lead->email)
            <form action="{{ route('leads.verify-email', $lead) }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-success d-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-patch-check text-success"></i>
                    <span>Verify Deliverability</span>
                </button>
            </form>
            @endif
            <a href="{{ route('leads.edit', $lead) }}" class="btn btn-sm btn-light border d-flex align-items-center gap-1">
                <i class="bi bi-pencil"></i>
                <span>Edit</span>
            </a>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: 100-Point Transparent Score Engine Breakdown -->
    <div class="col-lg-7">
        <!-- 100-Point Score Card -->
        <div class="card-lp p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="p-2 rounded bg-success-subtle text-success"><i class="bi bi-shield-check fs-5"></i></span>
                    <div>
                        <h6 class="fw-bold mb-0">100-Point Explainable Scoring Model</h6>
                        <span class="text-muted" style="font-size: 0.75rem;">Weighted evidence model (v{{ $lead->score_breakdown['scoring_version'] ?? '1' }}) · weights set in Settings → Lead Scoring</span>
                    </div>
                </div>
                <div class="text-end">
                    <span class="fs-4 fw-bold {{ \App\Support\ScoreBands::textClass($lead->overall_score) }}">
                        {{ $lead->overall_score }}
                    </span>
                    <span class="text-muted small">/ 100</span>
                </div>
            </div>

            <!-- Score Explanation Narrative -->
            <div class="p-3 rounded bg-light-subtle border mb-4">
                <strong class="d-block small text-dark fw-bold mb-1">
                    <i class="bi bi-chat-left-text-fill text-primary me-1"></i> Scoring Rationale
                </strong>
                <p class="small text-muted mb-0" style="line-height: 1.6;">
                    {{ $lead->score_explanation ?: 'No scoring rationale generated yet.' }}
                </p>
            </div>

            @include('leads._score_panel')
        </div>

        <!-- Outreach History & Inbound Replies -->
        <div class="card-lp p-4 mb-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-envelope-paper text-primary me-1"></i> Outreach & Reply Activity</h6>

            @if($lead->inboundReplies->isNotEmpty())
                <div class="mb-3">
                    <strong class="d-block small text-muted text-uppercase fw-bold mb-2">Inbound Responses ({{ $lead->inboundReplies->count() }})</strong>
                    @foreach($lead->inboundReplies as $reply)
                    <div class="p-3 rounded border mb-2 {{ $reply->classification === 'positive' || $reply->classification === 'meeting_request' ? 'bg-success-subtle border-success-subtle' : 'bg-light' }}">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="badge {{ $reply->classification === 'positive' ? 'bg-success' : ($reply->classification === 'meeting_request' ? 'bg-primary' : 'bg-secondary') }}">
                                {{ ucwords(str_replace('_', ' ', $reply->classification)) }} ({{ $reply->ai_confidence }}% AI Conf)
                            </span>
                            <span class="small text-muted">{{ $reply->received_at->format('M d, Y H:i') }}</span>
                        </div>
                        <div class="fw-semibold text-dark small mb-1">{{ $reply->subject }}</div>
                        <div class="small text-muted" style="white-space: pre-wrap;">{{ $reply->body_text }}</div>
                    </div>
                    @endforeach
                </div>
            @endif

            @if($lead->campaignRecipients->isNotEmpty())
                <div>
                    <strong class="d-block small text-muted text-uppercase fw-bold mb-2">Campaign Outreach History</strong>
                    <div class="table-responsive">
                        <table class="table table-sm table-lp align-middle">
                            <thead>
                                <tr class="text-muted small">
                                    <th>Campaign</th>
                                    <th>Status</th>
                                    <th>Dispatched At</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($lead->campaignRecipients as $cr)
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-dark">{{ $cr->campaign?->name ?? 'Direct' }}</div>
                                        <div class="small text-muted">{{ $cr->personalized_subject }}</div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">{{ ucfirst($cr->status) }}</span>
                                    </td>
                                    <td class="small text-muted">
                                        {{ $cr->sent_at ? $cr->sent_at->format('M d, Y H:i') : 'Draft / Scheduled' }}
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <p class="text-muted small mb-0">No outbound campaign messages dispatched to this contact yet.</p>
            @endif
        </div>
    </div>

    <!-- Right Column: Verification, CRM Tasks, Deals, Next Steps -->
    <div class="col-lg-5">
        <!-- Contact & Deliverability Status -->
        <div class="card-lp p-4 mb-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-shield-check text-info me-1"></i> Deliverability & Compliance</h6>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small text-muted">Direct Business Email:</span>
                    <span class="fw-semibold text-dark">{{ $lead->email ?: 'None provided' }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small text-muted">Verification Status:</span>
                    <span class="badge {{ \App\Support\EmailStatus::badgeClass($lead->email_verification_status) }} border">
                        {{ \App\Support\EmailStatus::label($lead->email_verification_status) }}
                    </span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small text-muted">Global Suppression Check:</span>
                    @if($lead->isSuppressed())
                        <span class="badge bg-danger-subtle text-danger border">Suppressed (Do Not Contact)</span>
                    @else
                        <span class="badge bg-success-subtle text-success border">Clear (Outreach Permitted)</span>
                    @endif
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small text-muted">Phone:</span>
                    <span class="small text-dark">{{ $lead->phone ?: 'Not provided' }}</span>
                </div>
            </div>

            @if($lead->email)
            <form action="{{ route('leads.verify-email', $lead) }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-sm btn-light border w-100">
                    <i class="bi bi-arrow-clockwise me-1"></i> Run DNS / MX Email Verification
                </button>
            </form>
            @endif
        </div>

        <!-- Follow-up Next Action -->
        <div class="card-lp p-4 mb-4" style="background: #faf5ff; border-color: #e9d5ff;">
            <h6 class="fw-bold mb-2 text-purple" style="color: #6b21a8;"><i class="bi bi-calendar-check me-1"></i> Next Action & Follow-up</h6>
            @if($lead->next_action_date)
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small text-muted">Due Date:</span>
                    <strong class="text-dark small">{{ $lead->next_action_date->format('M d, Y') }}</strong>
                </div>
                <div class="small text-dark mt-1">
                    {{ $lead->next_action_note ?: 'Follow up on outreach discussion.' }}
                </div>
            @else
                <p class="small text-muted mb-0">No scheduled follow-up task recorded.</p>
            @endif
        </div>

        <!-- Follow-up Tasks Modal Trigger -->
        <div class="card-lp p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-check2-square text-success me-1"></i> Tasks ({{ $lead->tasks->count() }})</h6>
                <button type="button" class="btn btn-sm btn-light border py-1 px-2" data-bs-toggle="modal" data-bs-target="#addTaskModal">
                    <i class="bi bi-plus"></i> Add
                </button>
            </div>

            <div class="d-flex flex-column gap-2">
                @forelse($lead->tasks as $t)
                <div class="p-2 rounded border bg-light-subtle d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small fw-semibold text-dark {{ $t->status === 'completed' ? 'text-decoration-line-through text-muted' : '' }}">
                            {{ $t->title }}
                        </div>
                        <div class="text-muted" style="font-size: 0.725rem;">Due {{ $t->due_date->format('M d') }} • {{ ucfirst($t->priority) }} Priority</div>
                    </div>
                    @if($t->status !== 'completed')
                    <form action="{{ route('crm.tasks.complete', $t) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-light border py-0 px-2" title="Mark Complete">
                            <i class="bi bi-check text-success"></i>
                        </button>
                    </form>
                    @endif
                </div>
                @empty
                <div class="text-muted small text-center py-2">No tasks logged.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<!-- Add Task Modal -->
<div class="modal fade" id="addTaskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="{{ route('crm.tasks.store') }}" method="POST" class="modal-content">
            @csrf
            <input type="hidden" name="lead_id" value="{{ $lead->id }}">
            <input type="hidden" name="company_id" value="{{ $lead->company_id }}">
            <div class="modal-header">
                <h5 class="modal-title fw-bold fs-6">Schedule Follow-up Task</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Task Title</label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. Call to discuss ERP proposal" required>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Due Date</label>
                        <input type="date" name="due_date" class="form-control" value="{{ date('Y-m-d', strtotime('+2 days')) }}" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Priority</label>
                        <select name="priority" class="form-select">
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Assigned Rep</label>
                    <select name="assigned_to" class="form-select">
                        <option value="{{ Auth::id() }}">{{ Auth::user()->name }} (You)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary-lp">Create Task</button>
            </div>
        </form>
    </div>
</div>
@endsection
