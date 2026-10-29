@props(['name' => 'company_id', 'selected' => null, 'required' => false, 'emptyLabel' => 'Select Company...'])
{{-- Type-to-search company field: loads 20 matches at a time instead of every company in one <select>. --}}
<div class="company-picker" data-lookup="{{ route('companies.lookup') }}">
    <input type="search" class="form-control form-control-sm mb-1 company-picker-q" placeholder="Type a company name or domain…" autocomplete="off">
    <select name="{{ $name }}" class="form-select company-picker-select" @if($required) required @endif>
        <option value="">{{ $emptyLabel }}</option>
        @if($selected)
            <option value="{{ $selected->id }}" selected>{{ \App\Http\Controllers\CompanyController::lookupLabel($selected) }}</option>
        @endif
    </select>
</div>
@once
@push('scripts')
<script>
document.querySelectorAll('.company-picker').forEach(function (box) {
    var input = box.querySelector('.company-picker-q'), select = box.querySelector('.company-picker-select'), timer;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            var q = input.value.trim();
            if (q.length < 2) { return; }
            fetch(box.dataset.lookup + '?q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}})
                .then(function (r) { return r.json(); })
                .then(function (rows) {
                    var keep = select.value, first = select.options[0];
                    select.innerHTML = '';
                    select.appendChild(first);
                    rows.forEach(function (c) {
                        var o = document.createElement('option');
                        o.value = c.id; o.textContent = c.label;
                        select.appendChild(o);
                    });
                    if (rows.length === 1) { select.value = rows[0].id; } else if (keep) { select.value = keep; }
                    if (!rows.length) { first.textContent = 'No company matches "' + q + '"'; }
                });
        }, 250);
    });
});
</script>
@endpush
@endonce
