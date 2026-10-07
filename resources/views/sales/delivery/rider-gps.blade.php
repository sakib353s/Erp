@extends('layouts.app')

@section('page_title', 'Rider GPS Tracking')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Rider GPS Tracking</h1>
            <p class="erp-page-sub">Latest recorded position per rider — only riders who approved GPS sharing ever appear with coordinates.</p>
        </div>
    </div>

    @if ($errors->has('location'))
        <div class="alert alert-warning">{{ $errors->first('location') }}</div>
    @endif

    <div class="erp-card mb-3">
        <h2 class="erp-h3">Record rider position</h2>
        @if ($profiles->isEmpty())
            <p class="text-muted mb-0">No riders on this roster yet — add a rider under Own Delivery Riders first.</p>
        @else
        <form method="POST" action="{{ route('sales.delivery.riders.location', $profiles->first()) }}" class="row g-2" id="loc-form">
            @csrf
            <div class="col-md-3">
                <label class="form-label" for="rider_select">Rider</label>
                <select class="form-select" id="rider_select" name="rider_id" required onchange="document.getElementById('loc-form').action = '{{ url('/app/sales/delivery/riders') }}/' + this.value + '/location'">
                    <option value="">Select rider…</option>
                    @foreach ($profiles as $profile)
                        <option value="{{ $profile->id }}" @disabled(! $profile->gps_consent)>
                            {{ $profile->employee?->full_name }}{{ $profile->gps_consent ? '' : ' (sharing off)' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="latitude">Latitude</label>
                <input class="form-control" id="latitude" name="latitude" type="number" step="any" min="-90" max="90" required value="{{ old('latitude') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="longitude">Longitude</label>
                <input class="form-control" id="longitude" name="longitude" type="number" step="any" min="-180" max="180" required value="{{ old('longitude') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="captured_at">Captured at</label>
                <input class="form-control" id="captured_at" name="captured_at" type="datetime-local" value="{{ old('captured_at') }}">
            </div>
            <div class="col-md-1 d-grid">
                <button class="btn btn-primary" type="submit">Record</button>
            </div>
        </form>
        @endif
        <p class="small text-muted mb-0 mt-2">Positions are stored only for riders with GPS sharing approved — anyone else is refused with an explicit reason.</p>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Rider</th>
                        <th>GPS sharing</th>
                        <th>Latest position</th>
                        <th>Captured</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($profiles as $profile)
                        @php($point = $latest[$profile->employee_id] ?? null)
                        <tr>
                            <td class="fw-semibold">{{ $profile->employee?->full_name ?? 'Employee #'.$profile->employee_id }}</td>
                            <td>
                                <span class="erp-status {{ $profile->gps_consent ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $profile->gps_consent ? 'approved' : 'not approved' }}
                                </span>
                            </td>
                            <td>
                                @if ($point)
                                    {{ $point->latitude }}, {{ $point->longitude }}
                                @elseif ($profile->gps_consent)
                                    <span class="text-muted">No position recorded yet</span>
                                @else
                                    <span class="text-muted">Not tracked — consent not approved</span>
                                @endif
                            </td>
                            <td class="small">{{ $point?->captured_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-muted">No riders on the roster yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
