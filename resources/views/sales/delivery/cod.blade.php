@extends('layouts.app')

@section('page_title', 'COD Collection Tracking')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">COD Collection Tracking</h1>
            <p class="erp-page-sub">Cash riders recorded as collected, matched against what the office actually remitted. Reconciliation posts the cod_remittance journal (cash over/short carries the difference) and settles each order's invoice — nothing here fabricates a remittance.</p>
        </div>
    </div>

    @if ($errors->has('cod'))
        <div class="alert alert-warning">{{ $errors->first('cod') }}</div>
    @endif

    <div class="erp-card mb-3">
        <h2 class="erp-h3">Cash position</h2>
        <div class="row text-center">
            <div class="col">
                <div class="text-muted small">Collected</div>
                <span class="fw-semibold">{{ number_format($totals['collected'], 2) }}</span>
            </div>
            <div class="col">
                <div class="text-muted small">Reconciled</div>
                <span class="fw-semibold">{{ number_format($totals['reconciled'], 2) }}</span>
            </div>
            <div class="col">
                <div class="text-muted small">Outstanding</div>
                <span class="fw-semibold">{{ number_format($totals['outstanding'], 2) }}</span>
            </div>
            <div class="col">
                <div class="text-muted small">Remitted</div>
                <span class="fw-semibold">{{ number_format($totals['remitted'], 2) }}</span>
            </div>
        </div>
        <p class="small text-muted mb-0 mt-2">Outstanding = collected cash not yet matched to a remittance. Remitted = what reconciliations show the office physically received.</p>
    </div>

    <div class="erp-card mb-3">
        <h2 class="erp-h3">Reconcile a remittance</h2>
        @if ($open->isEmpty())
            <p class="text-muted mb-0">No outstanding COD collections — every recorded collection is already reconciled (or awaiting approval).</p>
        @else
            <form method="POST" action="{{ route('sales.delivery.cod.reconcile') }}">
                @csrf
                <div class="table-responsive">
                    <table class="table erp-table align-middle mb-2">
                        <thead>
                            <tr>
                                <th style="width: 2rem"><span class="visually-hidden">Select</span></th>
                                <th>Collected at</th>
                                <th>Rider</th>
                                <th>Shipment</th>
                                <th>Order</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($open as $row)
                                <tr>
                                    <td>
                                        <input class="form-check-input cod-select" type="checkbox"
                                            name="collection_ids[]" value="{{ $row['collection']->id }}">
                                    </td>
                                    <td>{{ $row['collection']->collected_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td>{{ $row['collection']->rider?->full_name ?? '—' }}</td>
                                    <td>Shipment #{{ $row['shipment_id'] ?? '—' }}</td>
                                    <td>{{ $row['order_no'] ?? '—' }}</td>
                                    <td class="text-end">{{ number_format((float) $row['collection']->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label" for="remitted_amount">Remitted amount</label>
                        <input class="form-control" id="remitted_amount" name="remitted_amount" type="number" step="0.01" min="0" required value="{{ old('remitted_amount') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="remitted_at">Remitted at</label>
                        <input class="form-control" id="remitted_at" name="remitted_at" type="date" value="{{ old('remitted_at') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="reference">Remittance reference</label>
                        <input class="form-control" id="reference" name="reference" maxlength="100" value="{{ old('reference') }}">
                    </div>
                    <div class="col-md-3 d-grid">
                        <button class="btn btn-primary" type="submit">Reconcile</button>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">Notes</label>
                        <input class="form-control" id="notes" name="notes" maxlength="500" value="{{ old('notes') }}">
                    </div>
                </div>
                <p class="small text-muted mb-0 mt-2">Select collections from a single rider. The remitted amount is matched against their cash total — a difference is kept as cash over/short on the reconciliation, never invented away.</p>
            </form>
        @endif
    </div>

    <div class="erp-card mb-3">
        <h2 class="erp-h3">Per-rider cash</h2>
        @if ($riders->isEmpty())
            <p class="text-muted mb-0">No COD collections yet — riders record cash as it is collected.</p>
        @else
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Rider</th>
                            <th class="text-end">Collections</th>
                            <th class="text-end">Collected</th>
                            <th class="text-end">Reconciled</th>
                            <th class="text-end">Pending approval</th>
                            <th class="text-end">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($riders as $rider)
                            <tr>
                                <td>{{ $rider['rider']?->full_name ?? '—' }}</td>
                                <td class="text-end">{{ $rider['collections'] }}</td>
                                <td class="text-end">{{ number_format($rider['collected'], 2) }}</td>
                                <td class="text-end">{{ number_format($rider['reconciled'], 2) }}</td>
                                <td class="text-end">{{ number_format($rider['pending'], 2) }}</td>
                                <td class="text-end">{{ number_format($rider['outstanding'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="erp-card mb-3">
        <h2 class="erp-h3">Reconciliations</h2>
        @if ($reconciliations->isEmpty())
            <p class="text-muted mb-0">No reconciliations yet — the first remittance match will appear here.</p>
        @else
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Rider</th>
                            <th>Remitted</th>
                            <th>Cash total</th>
                            <th>Variance</th>
                            <th>Status</th>
                            <th>Reference</th>
                            <th>Reconciled at</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reconciliations as $recon)
                            <tr>
                                <td>{{ $recon->id }}</td>
                                <td>{{ $recon->rider?->full_name ?? '—' }}</td>
                                <td>{{ number_format((float) $recon->remitted_amount, 2) }}</td>
                                <td>{{ number_format((float) $recon->cash_total, 2) }}</td>
                                <td>
                                    @if ((float) $recon->variance < 0)
                                        <span class="badge bg-danger">Short {{ number_format(abs((float) $recon->variance), 2) }}</span>
                                    @elseif ((float) $recon->variance > 0)
                                        <span class="badge bg-warning text-dark">Over {{ number_format((float) $recon->variance, 2) }}</span>
                                    @else
                                        <span class="badge bg-success">Matched</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($recon->isReconciled())
                                        <span class="badge bg-success">Reconciled</span>
                                    @else
                                        <span class="badge bg-secondary">Pending approval</span>
                                    @endif
                                </td>
                                <td>{{ $recon->reference ?? '—' }}</td>
                                <td>{{ $recon->reconciled_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="erp-card">
        <h2 class="erp-h3">Collection history</h2>
        @if ($rows->isEmpty())
            <p class="text-muted mb-0">No COD collections yet — riders record cash as it is collected.</p>
        @else
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Collected at</th>
                            <th>Rider</th>
                            <th>Shipment</th>
                            <th>Order</th>
                            <th class="text-end">Amount</th>
                            <th>State</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>{{ $row['collection']->collected_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td>{{ $row['collection']->rider?->full_name ?? '—' }}</td>
                                <td>Shipment #{{ $row['shipment_id'] ?? '—' }}</td>
                                <td>{{ $row['order_no'] ?? '—' }}</td>
                                <td class="text-end">{{ number_format((float) $row['collection']->amount, 2) }}</td>
                                <td>
                                    @if ($row['state'] === 'reconciled')
                                        <span class="badge bg-success">Reconciled</span>
                                    @elseif ($row['state'] === 'pending')
                                        <span class="badge bg-secondary">Pending approval</span>
                                    @else
                                        <span class="badge bg-info text-dark">Outstanding</span>
                                    @endif
                                </td>
                                <td class="small">{{ $row['collection']->notes ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
