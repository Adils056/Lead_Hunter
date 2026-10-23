@extends('layouts.app')
@section('title', 'Lead Hunter')
@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1">Lead Hunter</h3>
        <p class="text-muted small mb-0">Define who you want (Ideal Customer Profile). LeadPilot turns it into searches across permitted sources, audits websites and scores every prospect with evidence.</p>
    </div>
    @if(auth()->user()->isAdmin())
    <a href="{{ route('hunting.create') }}" class="btn btn-sm btn-primary-lp d-flex align-items-center gap-1"><i class="bi bi-plus-lg"></i> New ICP</a>
    @endif
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card-lp p-0">
            <table class="table table-lp align-middle mb-0">
                <thead><tr class="small text-muted"><th class="ps-3">Profile</th><th>Target</th><th>Sources</th><th>Leads</th><th>Last run</th></tr></thead>
                <tbody>
                @forelse($profiles as $p)
                    <tr>
                        <td class="ps-3"><a href="{{ route('hunting.show', $p) }}" class="fw-semibold text-decoration-none">{{ $p->name }}</a>
                            @unless($p->is_active)<span class="badge bg-secondary ms-1">inactive</span>@endunless
                            <div class="small text-muted">{{ \Illuminate\Support\Str::limit($p->objective ?: $p->description, 70) }}</div></td>
                        <td class="small">{{ implode(', ', array_slice(array_merge($p->list('niches'), $p->list('industries')), 0, 3)) ?: '—' }}<div class="text-muted">{{ implode(', ', array_slice(array_merge($p->list('cities'), $p->list('countries')), 0, 3)) }}</div></td>
                        <td class="small">{{ implode(', ', $p->list('sources')) ?: 'auto' }}</td>
                        <td><span class="badge bg-light text-dark border">{{ $p->leads_count }}</span></td>
                        <td class="small text-muted">{{ $p->last_run_at?->diffForHumans() ?? 'never' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-5">No Ideal Customer Profiles yet. Create one to start hunting.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-lp p-3 mt-4">
            <h6 class="fw-bold mb-2">Recent hunt runs</h6>
            <table class="table table-sm table-lp mb-0 small">
                <thead><tr class="text-muted"><th>When</th><th>ICP</th><th>Source</th><th>Status</th><th>Found</th><th>New leads</th><th>Excluded</th><th>Est. cost</th></tr></thead>
                <tbody>
                @forelse($runs as $r)
                    <tr><td>{{ $r->created_at->format('M d H:i') }}</td><td>{{ $r->icpProfile?->name ?? '—' }}</td><td>{{ $r->source_slug }}</td>
                        <td><span class="badge {{ $r->status === 'completed' ? 'bg-success' : ($r->status === 'skipped' ? 'bg-secondary' : 'bg-danger') }}" title="{{ implode(' | ', (array) $r->errors) }}">{{ $r->status }}</span></td>
                        <td>{{ $r->total_found }}</td><td>{{ $r->new_leads }}</td><td>{{ $r->excluded }}</td><td>${{ number_format($r->estimated_cost_cents / 100, 2) }}</td></tr>
                @empty
                    <tr><td colspan="8" class="text-muted">No runs yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card-lp p-3" id="source-status" data-url="{{ route('hunting.sources') }}">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0">Discovery sources</h6>
                <span class="text-muted" style="font-size:.72rem" id="source-status-stamp" title="Refreshes every 30 seconds from the database (last runs, provider health, rests)">updated {{ now()->format('H:i:s') }}</span>
            </div>
            <ul class="list-unstyled small mb-2" id="source-status-list">
                @foreach($sources as $slug => $s)
                    <li class="py-2 border-bottom" data-slug="{{ $slug }}">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <span class="src-name">{{ $s['name'] }}</span>
                            <span class="badge src-badge flex-shrink-0 text-nowrap {{ $s['class'] }}">{{ $s['label'] }}</span>
                        </div>
                        <div class="text-muted src-detail" style="font-size:.74rem">{{ $s['detail'] ?: ($s['configured'] ? 'No run recorded yet.' : '') }}</div>
                        <div class="src-error {{ ($s['error_level'] ?? 'danger') === 'warning' ? 'text-warning' : 'text-danger' }}" style="font-size:.72rem" @if(!$s['error']) hidden @endif>{{ ($s['error_level'] ?? null) === 'warning' ? 'Note: ' : '' }}{{ $s['error'] }}</div>
                    </li>
                @endforeach
            </ul>
            <p class="small text-muted mb-0">Status comes from real runs and provider health, not from a static list. Overture Maps Places and OpenStreetMap need no key (Overture uses the bundled DuckDB CLI in <code>tools/duckdb</code>). Google Places is optional and needs <code>GOOGLE_PLACES_API_KEY</code>. Wikidata (B2B companies) needs no key. Apollo is optional and needs a key in Integrations. Remotive is off by default (its terms forbid lead collection).</p>
        </div>
    </div>
</div>
@endsection
@push('scripts')
<script>
(function () {
    var box = document.getElementById('source-status');
    if (!box) return;
    var url = box.dataset.url;
    function paint(s) {
        var li = box.querySelector('li[data-slug="' + s.slug + '"]');
        if (!li) return;
        var b = li.querySelector('.src-badge');
        b.className = 'badge src-badge flex-shrink-0 text-nowrap ' + s.class;
        b.textContent = s.label;
        li.querySelector('.src-detail').textContent = s.detail || (s.configured ? 'No run recorded yet.' : '');
        var e = li.querySelector('.src-error');
        e.textContent = s.error ? ((s.error_level === 'warning' ? 'Note: ' : '') + s.error) : '';
        e.className = 'src-error ' + (s.error_level === 'warning' ? 'text-warning' : 'text-danger');
        e.hidden = !s.error;
    }
    function refresh() {
        fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (d) {
                (d.sources || []).forEach(paint);
                var t = new Date(d.as_of);
                document.getElementById('source-status-stamp').textContent = 'updated ' + t.toLocaleTimeString();
            })
            .catch(function () { document.getElementById('source-status-stamp').textContent = 'refresh failed; retrying'; });
    }
    setInterval(refresh, 30000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
})();
</script>
@endpush
