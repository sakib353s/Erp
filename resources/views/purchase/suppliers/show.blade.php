@extends('layouts.app')

@section('page_title', $supplier->name)

@section('content')
    <x-ui.page-header
        eyebrow="Purchase · Supplier · {{ $supplier->code }}"
        :title="$supplier->name"
        :subtitle="($supplier->category ? ucfirst($supplier->category).' · ' : '').($supplier->payment_terms_days > 0 ? $supplier->payment_terms_days.'-day terms' : 'Cash terms')"
        :pin="true">
        <x-slot:actions>
            @if ($perm('purchase.orders.create') && ! $supplier->is_blacklisted && $supplier->is_active)
                <a class="btn btn-primary" href="{{ route('purchase.orders.create', ['supplier' => $supplier->id]) }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> New purchase order
                </a>
            @endif
            @if ($perm('purchase.receipts.create') && ! $supplier->is_blacklisted && $supplier->is_active)
                <a class="btn btn-outline-secondary" href="{{ route('purchase.receipts.create', ['supplier' => $supplier->id]) }}">
                    <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Receive goods
                </a>
            @endif
            @if ($perm('suppliers.edit'))
                <a class="btn btn-outline-secondary" href="{{ route('suppliers.edit', $supplier) }}">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                </a>
            @endif
            <a class="btn btn-outline-secondary" href="{{ route('suppliers.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All suppliers
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($supplier->is_blacklisted)
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-slash-circle" aria-hidden="true"></i>
            <div>
                <strong>Blacklisted since {{ $supplier->blacklisted_at?->format('d M Y') }}.</strong>
                {{ $supplier->blacklist_reason }}
                @if ($supplier->blacklistedBy) <span class="erp-td-muted">— recorded by {{ $supplier->blacklistedBy->name }}</span>@endif
                No new purchase order or goods receipt can be raised while this stands.
            </div>
            @if ($perm('suppliers.blacklist'))
                <form method="POST" action="{{ route('suppliers.reinstate', $supplier) }}" class="ms-auto">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Reinstate</button>
                </form>
            @endif
        </div>
    @endif

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Orders raised" :value="number_format($supplier->orders_count)" icon="bi-clipboard-check" hint="All statuses" />
        <x-ui.kpi label="Goods receipts" :value="number_format($supplier->receipts_count)" icon="bi-box-arrow-in-down" hint="Posted and draft" />
        <x-ui.kpi label="Line items still short" :value="number_format($openLines->count())" icon="bi-hourglass-split" hint="From approved open orders" />
        <x-ui.kpi label="Spend this year" value="৳ {{ number_format(array_sum($spend), 2) }}" icon="bi-cash-stack" hint="Posted receipts only" />
        <x-ui.kpi label="Owed to them" value="৳ {{ number_format($payables['due'], 2) }}" icon="bi-receipt"
                  :hint="$payables['rows']->count().' open bill(s)'" />
        <x-ui.kpi label="Past due" value="৳ {{ number_format($payables['overdue'], 2) }}" icon="bi-exclamation-triangle"
                  hint="From approved bills and their due dates" />
    </div>

    @if ($payables['rows']->isNotEmpty())
        <div class="erp-card mb-3">
            <div class="erp-card-head">
                <h2 class="erp-card-title">
                    Bills we still owe
                    <span class="erp-chip erp-chip-outline">{{ $payables['rows']->count() }}</span>
                </h2>
                <div class="erp-card-actions">
                    @if ($perm('purchase.bills.view'))
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('purchase.bills.index', ['supplier' => $supplier->id]) }}">
                            <i class="bi bi-receipt" aria-hidden="true"></i> All their bills
                        </a>
                    @endif
                </div>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Bill</th>
                            <th>Bill date</th>
                            <th>Due</th>
                            <th class="erp-th-num">Total</th>
                            <th class="erp-th-num">Still owed</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payables['rows'] as $bill)
                            <tr>
                                <td data-label="Bill">
                                    <a class="erp-row-link" href="{{ route('purchase.bills.show', $bill) }}">{{ $bill->code }}</a>
                                    @if ($bill->supplier_bill_no)
                                        <span class="erp-td-muted d-block small">their ref {{ $bill->supplier_bill_no }}</span>
                                    @endif
                                </td>
                                <td data-label="Bill date">{{ $bill->bill_date?->format('d M Y') }}</td>
                                <td data-label="Due">
                                    @if ($bill->due_date)
                                        {{ $bill->due_date->format('d M Y') }}
                                        @php($late = $bill->daysOverdue())
                                        @if ($late > 0)
                                            <span class="erp-td-muted d-block small">{{ $late }} day(s) late</span>
                                        @endif
                                    @else
                                        <span class="erp-td-muted">On demand</span>
                                    @endif
                                </td>
                                <td data-label="Total" class="erp-td-num">৳ {{ number_format((float) $bill->total, 2) }}</td>
                                <td data-label="Still owed" class="erp-td-num erp-cell-strong">৳ {{ number_format((float) $bill->due_amount, 2) }}</td>
                                <td data-label="Status"><x-ui.status :value="$bill->status" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="erp-help px-3 pb-3">
                Ageing follows the bill's own due date: {{ $payables['buckets']['current']['count'] }} current,
                {{ $payables['buckets']['d1_30']['count'] }} 1–30 days late,
                {{ $payables['buckets']['d31_60']['count'] }} 31–60,
                {{ $payables['buckets']['d61_90']['count'] }} 61–90,
                {{ $payables['buckets']['d90_plus']['count'] }} over 90.
            </div>
        </div>
    @endif

    <div class="erp-split">
        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Party details</h2>
            </div>
            <dl class="erp-dl erp-dl-tight px-3 pb-3">
                <dt>Code</dt>
                <dd>{{ $supplier->code }}</dd>
                <dt>Status</dt>
                <dd><x-ui.status :value="$supplier->status()" /></dd>
                <dt>Contact person</dt>
                <dd>{{ $supplier->contact_person ?: '—' }}</dd>
                <dt>Phone</dt>
                <dd>{{ $supplier->phone ?: '—' }}@if ($supplier->phone_alt) <span class="erp-td-muted">· {{ $supplier->phone_alt }}</span>@endif</dd>
                <dt>E-mail</dt>
                <dd>{{ $supplier->email ?: '—' }}</dd>
                <dt>District</dt>
                <dd>{{ $supplier->district?->name ?: '—' }}</dd>
                <dt>Address</dt>
                <dd>{{ $supplier->address_line1 ?: '—' }}</dd>
                <dt>BIN / TIN</dt>
                <dd>{{ $supplier->bin ?: '—' }} / {{ $supplier->tin ?: '—' }}</dd>
                <dt>Terms</dt>
                <dd>{{ $supplier->payment_terms_days > 0 ? $supplier->payment_terms_days.' days' : 'Cash' }}</dd>
                <dt>Credit limit</dt>
                <dd>৳ {{ number_format((float) $supplier->credit_limit, 2) }}</dd>
                <dt>Bank</dt>
                <dd>{{ $supplier->bank_name ? $supplier->bank_name.' · '.$supplier->bank_account_no : 'Not on file' }}</dd>
                <dt>Wallet</dt>
                <dd>{{ $supplier->mobile_wallet ?: 'Not on file' }}</dd>
            </dl>

            @if ($perm('suppliers.blacklist') && ! $supplier->is_blacklisted)
                <div class="erp-inline-form mx-3 mb-3">
                    <form method="POST" action="{{ route('suppliers.blacklist', $supplier) }}">
                        @csrf
                        <label class="form-label" for="reason">Blacklist this supplier</label>
                        <div class="d-flex gap-2">
                            <input class="form-control" id="reason" name="reason" maxlength="500" required
                                   placeholder="Why — this is recorded against your name">
                            <button class="btn btn-outline-danger" type="submit">Blacklist</button>
                        </div>
                        @error('reason')<div class="erp-field-error">{{ $message }}</div>@enderror
                        <div class="erp-help mt-1">
                            Refused while open purchase orders reference this supplier — close or cancel them first.
                        </div>
                    </form>
                </div>
            @endif
        </div>

        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Outstanding deliveries</h2>
                <span class="erp-chip erp-chip-outline">{{ $openLines->count() }}</span>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Item</th>
                            <th class="erp-th-num">Ordered</th>
                            <th class="erp-th-num">Received</th>
                            <th class="erp-th-num">Short</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($openLines as $row)
                            <tr>
                                <td data-label="Order">
                                    <a class="erp-row-link" href="{{ route('purchase.orders.show', $row['order']) }}">{{ $row['order']->code }}</a>
                                    <span class="erp-td-muted d-block small">{{ $row['order']->order_date->format('d M Y') }}</span>
                                </td>
                                <td data-label="Item">
                                    {{ $row['line']->product?->name ?? $row['line']->description }}
                                    <span class="erp-td-muted d-block small">{{ $row['line']->product?->sku }}</span>
                                </td>
                                <td data-label="Ordered" class="erp-td-num">{{ number_format((float) $row['line']->qty_ordered, 2) }}</td>
                                <td data-label="Received" class="erp-td-num">{{ number_format((float) $row['line']->qty_received, 2) }}</td>
                                <td data-label="Short" class="erp-td-num">
                                    <span class="erp-amount">{{ number_format($row['line']->outstandingQty(), 2) }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <x-ui.empty icon="bi-check2-circle" title="Nothing outstanding"
                                                text="Every approved order from this supplier has been received in full." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="erp-card-head">
                <h2 class="erp-card-title">Recent orders</h2>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th class="erp-th-num">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentOrders as $order)
                            <tr>
                                <td data-label="Order">
                                    <a class="erp-row-link" href="{{ route('purchase.orders.show', $order) }}">{{ $order->code }}</a>
                                </td>
                                <td data-label="Date" class="erp-td-muted">{{ $order->order_date->format('d M Y') }}</td>
                                <td data-label="Status"><x-ui.status :value="$order->status" /></td>
                                <td data-label="Value" class="erp-td-num">৳ {{ number_format((float) $order->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><x-ui.empty icon="bi-clipboard" title="No orders yet" text="Raise the first purchase order from here." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="erp-card-head">
                <h2 class="erp-card-title">Recent receipts</h2>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Receipt</th>
                            <th>Date</th>
                            <th>Against</th>
                            <th>Status</th>
                            <th class="erp-th-num">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentReceipts as $receipt)
                            <tr>
                                <td data-label="Receipt">
                                    <a class="erp-row-link" href="{{ route('purchase.receipts.show', $receipt) }}">{{ $receipt->code }}</a>
                                </td>
                                <td data-label="Date" class="erp-td-muted">{{ $receipt->received_date->format('d M Y') }}</td>
                                <td data-label="Against" class="erp-td-muted">{{ $receipt->order?->code ?? 'Direct receipt' }}</td>
                                <td data-label="Status"><x-ui.status :value="$receipt->status" /></td>
                                <td data-label="Value" class="erp-td-num">৳ {{ number_format((float) $receipt->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-ui.empty icon="bi-box" title="No goods received yet" text="Receipts appear here the moment they are drafted." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-ui.related-pages />
@endsection
