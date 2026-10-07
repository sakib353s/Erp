@extends('layouts.app')

@section('page_title', 'Flash Sales')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Flash Sales</h1>
            <p class="erp-page-sub">Countdown uses server DB timestamps only — no client-authored timers.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.promotions.index') }}">All promotions</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.reports.promotions') }}">Promotion reports</a>
        </div>
    </div>

    @if ($perm('sales.promotions.create'))
        <div class="erp-card mb-3">
            <h2 class="erp-h3 mb-3">Create flash sale</h2>
            <form method="POST" action="{{ route('sales.promotions.store') }}" class="row g-2">
                @csrf
                <input type="hidden" name="kind" value="flash">
                <div class="col-md-3">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" value="{{ old('name') }}" required maxlength="120">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="type">Type</label>
                    <select class="form-select" id="type" name="type" required>
                        @foreach ($types as $t)
                            <option value="{{ $t }}" @selected(old('type', 'percent_off') === $t)>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="value">Value</label>
                    <input class="form-control" id="value" name="value" type="number" step="0.01" min="0" value="{{ old('value', 20) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="starts_at">Starts (required)</label>
                    <input class="form-control" id="starts_at" name="starts_at" type="datetime-local" value="{{ old('starts_at') }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="ends_at">Ends (required)</label>
                    <input class="form-control" id="ends_at" name="ends_at" type="datetime-local" value="{{ old('ends_at') }}" required>
                </div>
                @if ($errors->has('name'))
                    <div class="col-12 text-danger small">{{ $errors->first('name') }}</div>
                @endif
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Create flash sale</button>
                </div>
            </form>
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Active now</div>
                <div class="fs-5 fw-semibold">{{ $status['active_count'] }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Upcoming</div>
                <div class="fs-5 fw-semibold">{{ $status['upcoming_count'] }}</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="erp-card">
                <div class="text-muted small">Server time</div>
                <div class="fs-6">{{ $status['server_time'] }}</div>
            </div>
        </div>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>State</th>
                        <th>Window (DB)</th>
                        <th class="text-end">Remaining (s)</th>
                        <th class="text-end">Value</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($flashPromotions as $row)
                        @php($promotion = $row['promotion'])
                        <tr
                            data-flash-row
                            data-ends-at="{{ $row['ends_at'] }}"
                            data-remaining="{{ $row['remaining_seconds'] }}"
                        >
                            <td>{{ $promotion->name }}</td>
                            <td>
                                <span class="erp-status {{ $row['state'] === 'active' ? 'erp-status-active' : ($row['state'] === 'upcoming' ? 'erp-status-inactive' : 'erp-status-disabled') }}">
                                    {{ $row['state'] }}
                                </span>
                            </td>
                            <td class="small">
                                {{ $promotion->starts_at?->toDateTimeString() ?? '—' }}
                                →
                                {{ $row['ends_at_db'] ?? '—' }}
                            </td>
                            <td class="text-end" data-countdown>{{ $row['remaining_seconds'] }}</td>
                            <td class="text-end">
                                @if ($promotion->type === 'percent_off')
                                    {{ rtrim(rtrim(number_format((float) $promotion->value, 2), '0'), '.') }}%
                                @else
                                    {{ number_format((float) $promotion->value, 2) }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No flash sales yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small mt-2">method: {{ $status['method'] }}</p>

    @if ($flashPromotions->isNotEmpty())
        <script>
            (function () {
                var serverNow = Date.parse(@json($status['server_time']));
                if (isNaN(serverNow)) { return; }
                var clientNow = Date.now();
                var skew = serverNow - clientNow;

                document.querySelectorAll('[data-flash-row]').forEach(function (row) {
                    var endsAt = row.getAttribute('data-ends-at');
                    var countdown = row.querySelector('[data-countdown]');
                    if (!endsAt || !countdown) { return; }
                    var end = Date.parse(endsAt);
                    if (isNaN(end)) { return; }

                    function tick() {
                        var remaining = Math.max(0, Math.floor((end - (Date.now() + skew)) / 1000));
                        countdown.textContent = String(remaining);
                    }
                    tick();
                    setInterval(tick, 1000);
                });
            })();
        </script>
    @endif

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
