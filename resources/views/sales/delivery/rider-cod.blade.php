@extends('layouts.app')

@section('page_title', 'Rider COD Collection')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Rider COD Collection</h1>
            <p class="erp-page-sub">Cash riders collected against accepted assignments — a record only; reconciliation posts separately.</p>
        </div>
    </div>

    @if ($errors->has('rider_assignment_id'))
        <div class="alert alert-warning">{{ $errors->first('rider_assignment_id') }}</div>
    @endif

    <div class="erp-card mb-3">
        <h2 class="erp-h3">Record collection</h2>
        @if ($collectable->isEmpty())
            <p class="text-muted mb-0">No accepted rider assignments awaiting COD collection.</p>
        @else
            <form method="POST" action="{{ route('sales.delivery.rider-cod.store') }}" class="row g-2">
                @csrf
                <div class="col-md-5">
                    <label class="form-label" for="rider_assignment_id">Accepted assignment</label>
                    <select class="form-select" id="rider_assignment_id" name="rider_assignment_id" required>
                        <option value="">Select assignment…</option>
                        @foreach ($collectable as $assignment)
                            <option value="{{ $assignment->id }}" @selected((string) old('rider_assignment_id') === (string) $assignment->id)>
                                #{{ $assignment->id }} — {{ $assignment->rider?->full_name }} (shipment #{{ $assignment->shipment_id }}{{ $assignment->shipment?->order?->order_no ? ', '.$assignment->shipment->order->order_no : '' }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="amount">Amount</label>
                    <input class="form-control" id="amount" name="amount" type="number" step="0.01" min="0.01" required value="{{ old('amount') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="collected_at">Collected at</label>
                    <input class="form-control" id="collected_at" name="collected_at" type="datetime-local" value="{{ old('collected_at') }}">
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-primary" type="submit">Record</button>
                </div>
                <div class="col-12">
                    <label class="form-label" for="notes">Notes</label>
                    <input class="form-control" id="notes" name="notes" maxlength="500" value="{{ old('notes') }}">
                </div>
            </form>
        @endif
    </div>

    <div class="erp-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h2 class="erp-h3 mb-0">Collections</h2>
            <span class="fw-semibold">Total {{ number_format($total, 2) }}</span>
        </div>
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Assignment</th>
                        <th>Rider</th>
                        <th class="text-end">Amount</th>
                        <th>Collected at</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($collections as $collection)
                        <tr>
                            <td>{{ $collection->id }}</td>
                            <td>
                                #{{ $collection->rider_assignment_id }}
                                <span class="small text-muted">shipment #{{ $collection->assignment?->shipment_id }}</span>
                            </td>
                            <td class="fw-semibold">{{ $collection->rider?->full_name ?? 'Employee #'.$collection->rider_employee_id }}</td>
                            <td class="text-end">{{ number_format((float) $collection->amount, 2) }}</td>
                            <td class="small">{{ $collection->collected_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td class="small">{{ $collection->notes ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted">No COD collections recorded yet.</td>
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
