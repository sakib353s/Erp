@extends('layouts.app')

@section('page_title', 'Shipment Tracking')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Shipment Tracking</h1>
            <p class="erp-page-sub">
                <code>{{ $shipment->order?->order_no ?? '—' }}</code>
                · {{ $shipment->courier?->name ?? 'No courier' }}
                · status {{ str_replace('_', ' ', $shipment->status) }}
                @if ($shipment->external_ref)
                    · ref <code>{{ $shipment->external_ref }}</code>
                @endif
            </p>
        </div>
        <div>
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('sales.shipments.index') }}">Back to shipments</a>
        </div>
    </div>

    @if ($perm('sales.delivery.shipments.create'))
        <div class="erp-card mb-3">
            <h2 class="erp-h3">Record tracking event</h2>
            <p class="small text-muted mb-2">Operator-recorded observations only — statuses move forward through validated transitions, never backwards.</p>
            <form method="POST" action="{{ route('sales.shipments.tracking.store', $shipment) }}" class="row g-2">
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="event_code">Event</label>
                    <select class="form-select" id="event_code" name="event_code" required>
                        @foreach ($codes as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="description">Description</label>
                    <input class="form-control" id="description" name="description" maxlength="500"
                           placeholder="Defaults to the event label">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="location">Location</label>
                    <input class="form-control" id="location" name="location" maxlength="150">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="occurred_at">Occurred at</label>
                    <input class="form-control" id="occurred_at" name="occurred_at" type="datetime-local">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button class="btn btn-primary w-100" type="submit">Record</button>
                </div>
            </form>
        </div>
    @endif

    <div class="erp-card">
        <h2 class="erp-h3">Timeline</h2>
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Occurred</th>
                        <th>Source</th>
                        <th>Event</th>
                        <th>Description</th>
                        <th>Location</th>
                        <th>External id</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr>
                            <td>{{ $event->occurred_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                <span class="erp-status {{ $event->source === 'webhook' ? 'erp-status-active' : 'erp-status-pending' }}">
                                    {{ $event->source }}
                                </span>
                            </td>
                            <td>{{ str_replace('_', ' ', $event->event_code) }}</td>
                            <td>{{ $event->description }}</td>
                            <td class="small">{{ $event->location ?? '—' }}</td>
                            <td class="small"><code>{{ $event->external_event_id ?? '—' }}</code></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                No tracking events recorded yet — the courier has not reported anything.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
