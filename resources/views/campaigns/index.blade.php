@extends('layouts.app')

@section('title', 'Campaign Automation')

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1">Outreach Campaigns</h3>
        <p class="text-muted small mb-0">Personalized email campaigns with compliance guardrails, manual approval gates, and daily throttling.</p>
    </div>

    <a href="{{ route('campaigns.create') }}" class="btn btn-sm btn-primary-lp d-flex align-items-center gap-1">
        <i class="bi bi-plus-circle"></i>
        <span>Create Campaign</span>
    </a>
</div>

<div class="card-lp overflow-hidden mb-4">
    <div class="table-responsive">
        <table class="table table-lp align-middle">
            <thead>
                <tr>
                    <th>Campaign & Service Offered</th>
                    <th>Targeting Rules</th>
                    <th class="text-center">Min Score</th>
                    <th class="text-center">Recipients</th>
                    <th class="text-center">Delivered</th>
                    <th class="text-center">Replies</th>
                    <th>Status & Approval</th>
                    <th class="text-end">Controls</th>
                </tr>
            </thead>
            <tbody>
                @forelse($campaigns as $camp)
                <tr>
                    <td>
                        <a href="{{ route('campaigns.show', $camp) }}" class="fw-bold text-dark text-decoration-none fs-6">
                            {{ $camp->name }}
                        </a>
                        <div class="small text-muted">
                            <i class="bi bi-tag me-1"></i>{{ $camp->service_offered }}
                        </div>
                    </td>

                    <td>
                        <div class="small text-dark fw-semibold">
                            {{ !empty($camp->target_countries) ? implode(', ', (array) $camp->target_countries) : 'All Countries' }}
                        </div>
                        <div class="small text-muted">Limit: {{ $camp->daily_limit }}/day</div>
                    </td>

                    <td class="text-center">
                        <span class="badge bg-light text-dark border">{{ $camp->min_lead_score }}+ pts</span>
                    </td>

                    <td class="text-center">
                        <span class="badge bg-light text-dark border">{{ $camp->total_recipients }}</span>
                    </td>

                    <td class="text-center">
                        <span class="badge bg-success-subtle text-success border">{{ $camp->delivered_count }}</span>
                    </td>

                    <td class="text-center">
                        <span class="badge bg-primary-subtle text-primary border">{{ $camp->replied_count }}</span>
                    </td>

                    <td>
                        @if($camp->emergency_stopped)
                            <span class="badge bg-danger">HALTED (Emergency Stop)</span>
                        @elseif($camp->status === 'running')
                            <span class="badge bg-success">Running</span>
                        @elseif($camp->status === 'paused')
                            <span class="badge bg-warning text-dark">Paused</span>
                        @elseif($camp->status === 'approved')
                            <span class="badge bg-info text-dark">Approved</span>
                        @else
                            <span class="badge bg-secondary">Draft (Needs Approval)</span>
                        @endif
                    </td>

                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="{{ route('campaigns.show', $camp) }}" class="btn btn-light border" title="View Campaign Management">
                                <i class="bi bi-eye"></i>
                            </a>

                            @if(Auth::user()?->isAdmin() && $camp->status === 'draft')
                            <form action="{{ route('campaigns.approve', $camp) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-light border text-success" title="Approve Campaign">
                                    <i class="bi bi-check-circle"></i>
                                </button>
                            </form>
                            @endif

                            @if($camp->status === 'running')
                            <form action="{{ route('campaigns.pause', $camp) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-light border text-warning" title="Pause Campaign">
                                    <i class="bi bi-pause-circle"></i>
                                </button>
                            </form>
                            @elseif($camp->status === 'paused' && !$camp->emergency_stopped)
                            <form action="{{ route('campaigns.resume', $camp) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-light border text-success" title="Resume Campaign">
                                    <i class="bi bi-play-circle"></i>
                                </button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="text-center py-5 text-muted">
                        <i class="bi bi-send-x fs-1 d-block mb-2 opacity-50"></i>
                        No outreach campaigns configured yet.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($campaigns->hasPages())
    <div class="p-3 border-top bg-light-subtle d-flex justify-content-end">
        {{ $campaigns->links() }}
    </div>
    @endif
</div>
@endsection
