@extends('layouts.app')

@section('page_title', 'Sales Orders')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Sales Orders</h1>
            <p class="erp-page-sub">Commitment documents — reservation on confirm only.</p>
        </div>
        @if ($perm('sales.orders.create'))
            <a class="btn btn-primary" href="{{ route('sales.orders.index') }}#new">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New order
            </a>
        @endif
        @if ($perm('sales.orders.export'))
            <a class="btn btn-outline-secondary" href="{{ route('sales.orders.export') }}">
                <i class="bi bi-download" aria-hidden="true"></i> Export CSV
            </a>
        @endif
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            @if ($flag !== null)
                <input type="hidden" name="flag" value="{{ $flag }}">
            @endif
            <div class="col-md-4">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" value="{{ $q }}" placeholder="Order number">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    @foreach ($statusOptions as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" type="submit">Filter</button>
            </div>
            @if ($perm('sales.orders.view') && $perm('sales.orders.review'))
                <div class="col-md-3">
                    <a class="btn {{ $flag === 'suspicious' ? 'btn-danger' : 'btn-outline-secondary' }} w-100"
                       href="{{ route('sales.orders.index', ['flag' => 'suspicious']) }}">
                        Fake / Suspicious Orders
                    </a>
                </div>
            @endif
        </form>
    </div>

    @php
        $canConfirm = $perm('sales.orders.confirm');
        $canCancel = $perm('sales.orders.cancel');
        $canAssign = $perm('sales.delivery.assign');
        $canPrint = $perm('sales.invoices.print');
        $canPrintSlip = $perm('sales.orders.print');
        $canPrintLabel = $perm('sales.delivery.print');
        $canNotify = $perm('sales.orders.notify');
        $bulkAllowed = $canConfirm || $canCancel || $canAssign || $canPrint || $canPrintSlip || $canPrintLabel || $canNotify;
    @endphp

    <form method="POST" id="bulk-order-form">
        @csrf
        @if ($bulkAllowed)
            <div class="erp-card mb-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label" for="reason">Bulk cancel reason (optional)</label>
                        <input class="form-control" id="reason" name="reason" value="{{ old('reason') }}"
                            maxlength="500" placeholder="Shown on the order audit trail">
                    </div>
                    @if ($canAssign)
                        <div class="col-md-3">
                            <label class="form-label" for="courier_id">Courier</label>
                            <select class="form-select" id="courier_id" name="courier_id">
                                <option value="">Choose a courier…</option>
                                @foreach ($couriers as $courier)
                                    <option value="{{ $courier->id }}" @selected((string) old('courier_id') === (string) $courier->id)>
                                        {{ $courier->code }} — {{ $courier->name }}
                                        {{ $courier->configuration_status === 'configured' ? '' : '(not configured)' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="rider_name">Rider (optional)</label>
                            <input class="form-control" id="rider_name" name="rider_name"
                                value="{{ old('rider_name') }}" maxlength="64" placeholder="Rider name">
                        </div>
                    @endif
                    @if ($canNotify)
                        <div class="col-md-3">
                            <label class="form-label" for="template_id">Message template</label>
                            <select class="form-select" id="template_id" name="template_id">
                                <option value="">Choose a template…</option>
                                @foreach (['sms', 'whatsapp', 'email'] as $notifyChannel)
                                    @php $channelTemplates = $notifyTemplates->where('channel', $notifyChannel); @endphp
                                    @if ($channelTemplates->isNotEmpty())
                                        <optgroup label="{{ ucfirst($notifyChannel) }}">
                                            @foreach ($channelTemplates as $notifyTemplate)
                                                <option value="{{ $notifyTemplate->id }}" @selected((string) old('template_id') === (string) $notifyTemplate->id)>
                                                    {{ $notifyTemplate->name }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="col-md-4 text-md-end">
                        @if ($canConfirm)
                            <button class="btn btn-outline-primary" type="submit"
                                formaction="{{ route('sales.orders.bulk.confirm') }}">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i> Confirm selected
                            </button>
                        @endif
                        @if ($canCancel)
                            <button class="btn btn-outline-danger" type="submit"
                                formaction="{{ route('sales.orders.bulk.cancel') }}">
                                <i class="bi bi-x-circle" aria-hidden="true"></i> Cancel selected
                            </button>
                        @endif
                        @if ($canAssign)
                            <button class="btn btn-outline-secondary" type="submit"
                                formaction="{{ route('sales.orders.bulk.assign-courier') }}">
                                <i class="bi bi-truck" aria-hidden="true"></i> Assign courier
                            </button>
                        @endif
                        @if ($canPrint)
                            <button class="btn btn-outline-dark" type="submit"
                                formaction="{{ route('sales.orders.bulk.print-invoice') }}">
                                <i class="bi bi-printer" aria-hidden="true"></i> Print invoice
                            </button>
                        @endif
                        @if ($canPrintSlip)
                            <button class="btn btn-outline-dark" type="submit"
                                formaction="{{ route('sales.orders.bulk.print-packing-slip') }}">
                                <i class="bi bi-printer" aria-hidden="true"></i> Print packing slip
                            </button>
                        @endif
                        @if ($canPrintLabel)
                            <button class="btn btn-outline-dark" type="submit"
                                formaction="{{ route('sales.orders.bulk.print-shipping-label') }}">
                                <i class="bi bi-printer" aria-hidden="true"></i> Print shipping label
                            </button>
                        @endif
                        @if ($canNotify)
                            <button class="btn btn-outline-success" type="submit"
                                formaction="{{ route('sales.orders.bulk.sms') }}">
                                <i class="bi bi-chat-dots" aria-hidden="true"></i> Send SMS
                            </button>
                            <button class="btn btn-outline-success" type="submit"
                                formaction="{{ route('sales.orders.bulk.whatsapp') }}">
                                <i class="bi bi-whatsapp" aria-hidden="true"></i> Send WhatsApp
                            </button>
                            <button class="btn btn-outline-success" type="submit"
                                formaction="{{ route('sales.orders.bulk.email') }}">
                                <i class="bi bi-envelope" aria-hidden="true"></i> Send email
                            </button>
                        @endif
                    </div>
                </div>
                @if (session('bulk_messages'))
                    <ul class="list-unstyled mb-0 mt-2 small text-danger">
                        @foreach (session('bulk_messages') as $bulkMessage)
                            <li>{{ $bulkMessage }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        <div class="erp-card">
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            @if ($bulkAllowed)
                                <th style="width: 2rem;">
                                    <span class="visually-hidden">Select orders</span>
                                </th>
                            @endif
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th>Reserved</th>
                            <th class="text-end">Total</th>
                            @if ($flag === 'suspicious')
                                <th>Score</th>
                                <th>Review</th>
                            @endif
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($orders as $order)
                            <tr>
                                @if ($bulkAllowed)
                                    <td>
                                        <input class="form-check-input" type="checkbox" name="order_ids[]"
                                            value="{{ $order->id }}" aria-label="Select {{ $order->order_no }}">
                                    </td>
                                @endif
                                <td>
                                    @if ($perm('sales.orders.view'))
                                        <a class="fw-semibold text-decoration-none" href="{{ route('sales.orders.show', $order) }}">
                                            {{ $order->order_no }}
                                        </a>
                                    @else
                                        <span class="fw-semibold">{{ $order->order_no }}</span>
                                    @endif
                                </td>
                                <td>{{ optional($order->order_date)->toDateString() }}</td>
                                <td>{{ $order->customer?->name ?? '—' }}</td>
                                <td><span class="erp-status erp-status-active">{{ $order->status }}</span></td>
                                <td>{{ $order->stock_reserved ? 'Yes' : 'No' }}</td>
                                <td class="text-end">{{ number_format((float) $order->grand_total, 2) }}</td>
                                @if ($flag === 'suspicious')
                                    <td>
                                        @php $suspicion = $order->suspiciousFlag; @endphp
                                        @if ($suspicion)
                                            <span class="badge {{ $suspicion->level === 'high' ? 'text-bg-danger' : 'text-bg-warning' }}">
                                                {{ $suspicion->score }} · {{ $suspicion->level }}
                                            </span>
                                            <details class="small mt-1">
                                                <summary>Why</summary>
                                                <ul class="mb-0 ps-3">
                                                    @foreach ($suspicion->rules ?? [] as $rule)
                                                        <li>{{ $rule['evidence'] ?? '' }} (+{{ $rule['weight'] ?? 0 }})</li>
                                                    @endforeach
                                                </ul>
                                            </details>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($suspicion && $suspicion->status === 'reviewed')
                                            <span class="badge text-bg-secondary">
                                                {{ $suspicion->decision === 'confirmed_legitimate' ? 'Legitimate' : 'Confirmed suspicious' }}
                                            </span>
                                            <div class="small text-muted">
                                                {{ $suspicion->reviewed_at?->format('Y-m-d H:i') }}
                                                @if ($suspicion->review_notes)
                                                    <br>{{ $suspicion->review_notes }}
                                                @endif
                                            </div>
                                        @elseif ($suspicion && $perm('sales.orders.review'))
                                            {{-- The table sits inside the bulk form; nested forms are invalid HTML,
                                                 so the real form lives after it and the controls associate via the
                                                 HTML5 form attribute. --}}
                                            <span class="d-inline-flex gap-1 align-items-center">
                                                <input form="review-{{ $order->id }}" class="form-control form-control-sm"
                                                       style="width: 9rem" name="notes" maxlength="500"
                                                       placeholder="Notes (optional)" aria-label="Review notes">
                                                <button form="review-{{ $order->id }}" class="btn btn-sm btn-outline-secondary"
                                                        name="decision" value="confirmed_legitimate">Legit</button>
                                                <button form="review-{{ $order->id }}" class="btn btn-sm btn-outline-danger"
                                                        name="decision" value="confirmed_suspicious">Fake</button>
                                            </span>
                                        @else
                                            <span class="text-muted small">Awaiting review</span>
                                        @endif
                                    </td>
                                @endif
                                <td class="text-end">
                                    @if ($order->status === 'pending' && $perm('sales.orders.edit'))
                                        <a class="btn btn-sm btn-light"
                                            href="{{ route('sales.orders.edit', $order) }}">Edit</a>
                                    @endif
                                    @if ($perm('sales.orders.view'))
                                        <a class="btn btn-sm btn-light" href="{{ route('sales.orders.show', $order) }}">View</a>
                                    @endif
                                    @if (in_array($order->status, ['return_requested', 'return_approved', 'returned', 'refunded'], true)
                                        && $perm('returns.view'))
                                        <a class="btn btn-sm btn-light" href="{{ route('sales.returns.index') }}">Returns</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ 7 + ($bulkAllowed ? 1 : 0) + ($flag === 'suspicious' ? 2 : 0) }}" class="text-center text-muted py-4">
                                    @if ($flag === 'suspicious')
                                        No orders flagged as suspicious — nothing scored at or above 40 points in the last 90 days.
                                    @elseif ($status !== null && $status !== '')
                                        No orders with status [{{ $status }}] yet.
                                    @else
                                        No sales orders yet.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $orders->links() }}</div>
        </div>
    </form>

    @if ($flag === 'suspicious')
        @foreach ($orders as $order)
            @if ($order->suspiciousFlag && $order->suspiciousFlag->status !== 'reviewed' && $perm('sales.orders.review'))
                <form id="review-{{ $order->id }}" method="POST"
                      action="{{ route('sales.orders.suspicious-review', $order) }}" hidden>
                    @csrf
                </form>
            @endif
        @endforeach
    @endif
@endsection
