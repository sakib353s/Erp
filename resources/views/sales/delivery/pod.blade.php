@extends('layouts.app')

@section('page_title', 'Proof of Delivery')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Proof of Delivery</h1>
            <p class="erp-page-sub">Record that goods reached the customer — a signature, a photo, or the receiver's name. Only dispatched shipments qualify; stock and accounting stay at their configured stages.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->has('proof'))
        <div class="alert alert-warning">{{ $errors->first('proof') }}</div>
    @endif
    @if ($errors->hasAny(['receiver_name', 'notes', 'delivered_at', 'signature', 'photo']))
        <div class="alert alert-warning">
            <ul class="mb-0">
                @foreach (['receiver_name', 'notes', 'delivered_at', 'signature', 'photo'] as $key)
                    @if ($errors->has($key))
                        <li>{{ $errors->first($key) }}</li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif

    <div class="erp-card mb-3">
        <h2 class="erp-h3">Awaiting proof of delivery</h2>
        @if ($ready->isEmpty())
            <p class="text-muted mb-0">No shipments awaiting proof of delivery — dispatch a shipment first.</p>
        @else
            @foreach ($ready as $shipment)
                <div class="border rounded p-2 mb-2">
                    <div class="d-flex align-items-center justify-content-between">
                        <strong>
                            Shipment #{{ $shipment->id }}
                            <span class="small text-muted fw-normal">{{ $shipment->order?->order_no }}</span>
                        </strong>
                        <span class="small text-muted">
                            {{ $shipment->courier?->name ?? 'Courier #'.$shipment->courier_id }} ·
                            dispatched {{ $shipment->dispatched_at?->format('Y-m-d H:i') ?? '—' }} ·
                            <span class="erp-status erp-status-pending">{{ $shipment->status }}</span>
                        </span>
                    </div>
                    <form method="POST" action="{{ route('sales.delivery.pod.store', $shipment) }}"
                          enctype="multipart/form-data" class="row g-2 mt-1">
                        @csrf
                        <div class="col-md-3">
                            <label class="form-label" for="receiver-{{ $shipment->id }}">Receiver name</label>
                            <input class="form-control" id="receiver-{{ $shipment->id }}" type="text"
                                   name="receiver_name" maxlength="191" placeholder="Who received it">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="signature-{{ $shipment->id }}">Signature (image)</label>
                            <input class="form-control" id="signature-{{ $shipment->id }}" type="file"
                                   name="signature" accept=".png,.jpg,.jpeg,.webp">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="photo-{{ $shipment->id }}">Photo (image)</label>
                            <input class="form-control" id="photo-{{ $shipment->id }}" type="file"
                                   name="photo" accept=".png,.jpg,.jpeg,.webp">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="notes-{{ $shipment->id }}">Notes</label>
                            <input class="form-control" id="notes-{{ $shipment->id }}" type="text"
                                   name="notes" maxlength="500">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="delivered-{{ $shipment->id }}">Delivered at</label>
                            <input class="form-control" id="delivered-{{ $shipment->id }}" type="datetime-local"
                                   name="delivered_at">
                        </div>
                        <div class="col-md-1 d-grid">
                            <button class="btn btn-primary" type="submit">Record</button>
                        </div>
                    </form>
                </div>
            @endforeach
            <p class="small text-muted mb-0">At least one proof is required — receiver name alone is accepted; file content is checked server-side (the extension is never trusted).</p>
        @endif
    </div>

    <div class="erp-card">
        <h2 class="erp-h3">Recent proofs</h2>
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Delivered at</th>
                        <th>Shipment</th>
                        <th>Receiver</th>
                        <th>Signature</th>
                        <th>Photo</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recent as $pod)
                        <tr>
                            <td>{{ $pod->delivered_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>
                                #{{ $pod->shipment_id }}
                                <span class="small text-muted">{{ $pod->shipment?->order?->order_no }}</span>
                            </td>
                            <td>{{ $pod->receiver_name ?? '—' }}</td>
                            <td>
                                @if ($pod->signature_document_id !== null)
                                    <span class="badge text-bg-light border">stored</span>
                                @else
                                    <span class="text-muted small">none</span>
                                @endif
                            </td>
                            <td>
                                @if ($pod->photo_document_id !== null)
                                    <span class="badge text-bg-light border">stored</span>
                                @else
                                    <span class="text-muted small">none</span>
                                @endif
                            </td>
                            <td class="small">{{ $pod->notes ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted">No proof recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
