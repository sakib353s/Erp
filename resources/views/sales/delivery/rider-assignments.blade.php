@extends('layouts.app')

@section('page_title', 'Rider Assignment')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Rider Assignment</h1>
            <p class="erp-page-sub">Assign roster riders to shipments and track accept/decline — assignment itself moves no stock; handover happens at dispatch.</p>
        </div>
    </div>

    @if ($errors->has('shipment_id'))
        <div class="alert alert-warning">{{ $errors->first('shipment_id') }}</div>
    @endif
    @if ($errors->has('assignment'))
        <div class="alert alert-warning">{{ $errors->first('assignment') }}</div>
    @endif

    <div class="erp-card mb-3">
        <h2 class="erp-h3">Assign rider</h2>
        @if ($profiles->isEmpty() || $shipments->isEmpty())
            <p class="text-muted mb-0">
                @if ($profiles->isEmpty())
                    No active riders on the roster — add one from Own Delivery Riders first.
                @else
                    Every shipment already has an active rider assignment.
                @endif
            </p>
        @else
            <form method="POST" action="{{ route('sales.delivery.rider-assignments.store') }}" class="row g-2">
                @csrf
                <div class="col-md-5">
                    <label class="form-label" for="shipment_id">Shipment</label>
                    <select class="form-select" id="shipment_id" name="shipment_id" required>
                        <option value="">Select shipment…</option>
                        @foreach ($shipments as $shipment)
                            <option value="{{ $shipment->id }}" @selected((string) old('shipment_id') === (string) $shipment->id)>
                                #{{ $shipment->id }} — {{ $shipment->order?->order_no }} ({{ $courierById[$shipment->courier_id]?->name ?? 'Courier #'.$shipment->courier_id }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="rider_profile_id">Rider</label>
                    <select class="form-select" id="rider_profile_id" name="rider_profile_id" required>
                        <option value="">Select rider…</option>
                        @foreach ($profiles as $profile)
                            <option value="{{ $profile->id }}" @selected((string) old('rider_profile_id') === (string) $profile->id)>
                                {{ $profile->employee?->full_name }}{{ $profile->is_available ? '' : ' (busy)' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 d-grid">
                    <button class="btn btn-primary" type="submit">Assign rider</button>
                </div>
            </form>
        @endif
    </div>

    <div class="erp-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h2 class="erp-h3 mb-0">Assignments</h2>
            <div>
                <a href="{{ route('sales.delivery.rider-assignments.index') }}" class="btn btn-sm {{ $statusFilter === null ? 'btn-primary' : 'btn-outline-secondary' }}">All</a>
                @foreach ($statuses as $st)
                    <a href="{{ route('sales.delivery.rider-assignments.index', ['status' => $st]) }}"
                       class="btn btn-sm {{ $statusFilter === $st ? 'btn-primary' : 'btn-outline-secondary' }}">{{ ucfirst($st) }}</a>
                @endforeach
            </div>
        </div>
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Shipment</th>
                        <th>Rider</th>
                        <th>Status</th>
                        <th>Assigned by</th>
                        <th>Responded</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($assignments as $assignment)
                        <tr>
                            <td>{{ $assignment->id }}</td>
                            <td>
                                #{{ $assignment->shipment_id }}
                                <span class="small text-muted">{{ $assignment->shipment?->order?->order_no }}</span>
                            </td>
                            <td class="fw-semibold">
                                {{ $assignment->rider?->full_name ?? $assignment->rider_name }}
                                @if ($assignment->rider_employee_id === null)
                                    <span class="badge text-bg-light border">name only</span>
                                @endif
                            </td>
                            <td>
                                <span class="erp-status {{
                                    $assignment->status === 'accepted' ? 'erp-status-active'
                                        : ($assignment->status === 'declined' ? 'erp-status-disabled' : 'erp-status-pending')
                                }}">{{ $assignment->status }}</span>
                            </td>
                            <td class="small">{{ $assignment->assigned_by ?? '—' }}</td>
                            <td class="small">
                                @if ($assignment->responded_at)
                                    {{ $assignment->responded_at->format('Y-m-d H:i') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($assignment->status === 'pending')
                                    <form method="POST" action="{{ route('sales.delivery.rider-assignments.accept', $assignment) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-success" type="submit">Accept</button>
                                    </form>
                                    <form method="POST" action="{{ route('sales.delivery.rider-assignments.decline', $assignment) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Decline</button>
                                    </form>
                                @else
                                    <span class="text-muted small">No action</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-muted">No rider assignments yet.</td>
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
