@extends('layouts.app')

@section('page_title', 'Sales orders')

@section('content')
    @php
        $canConfirm = $perm('sales.orders.confirm');
        $canCancel = $perm('sales.orders.cancel');
        $canAssign = $perm('sales.orders.assign') || $perm('sales.delivery.assign');
        $canPrint = $perm('sales.invoices.print');
        $canPrintSlip = $perm('sales.orders.print');
        $canPrintLabel = $perm('sales.delivery.print');
        $canNotify = $perm('sales.orders.notify');
        $canReview = $perm('sales.orders.review');
        $bulkAllowed = $canConfirm || $canCancel || $canAssign || $canPrint || $canPrintSlip || $canPrintLabel || $canNotify;
        $columns = 6 + ($bulkAllowed ? 1 : 0) + ($flag === 'suspicious' ? 2 : 0);
    @endphp

    {{-- PageHeader: identity, context, primary actions (§18.2) --}}
    <x-ui.page-header
        eyebrow="Sell · Sales"
        title="Sales orders"
        subtitle="Commitment documents. Stock is reserved on confirm only — the ledger moves at invoice, never at order."
        :pin="true">
        <x-slot:actions>
            @if ($perm('sales.orders.export'))
                <a class="btn btn-outline-secondary" href="{{ route('sales.orders.export') }}">
                    <i class="bi bi-download" aria-hidden="true"></i> Export CSV
                </a>
            @endif
            @if ($canReview)
                <a class="btn {{ $flag === 'suspicious' ? 'btn-danger' : 'btn-outline-secondary' }}"
                   href="{{ route('sales.orders.index', array_filter(['flag' => $flag === 'suspicious' ? null : 'suspicious', 'status' => $status])) }}">
                    <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
                    {{ $flag === 'suspicious' ? 'Back to all orders' : 'Suspicious orders' }}
                </a>
            @endif
            @if ($perm('sales.orders.create'))
                <a class="btn btn-primary" href="{{ route('sales.orders.index') }}#new">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> New order
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @php
        /*
         | Catalog-declared saved views (`/app/sales/orders?status=…`) that the
         | status ladder does not already cover: the §47 tree describes them as
         | separate pages, the UI serves them as views of ONE page (§18.3).
         */
        $catalogViews = collect(app(\App\Domain\Foundation\Services\NavigationBuilder::class)
            ->viewsForPath('/app/sales/orders', auth()->user()))
            ->reject(function (array $view) use ($statusOptions) {
                $query = [];
                parse_str((string) parse_url($view['url'], PHP_URL_QUERY), $query);

                return isset($query['status']) && in_array($query['status'], $statusOptions, true);
            })
            ->reject(fn (array $view) => str_contains((string) $view['url'], 'flag='));
    @endphp

    {{-- Saved views: the status ladder lives on the page, not as 18 sidebar rows --}}
    <nav class="erp-segmented mb-3" aria-label="Order status views">
        <a class="{{ $status === null || $status === '' ? 'active' : '' }}"
           href="{{ route('sales.orders.index', array_filter(['q' => $q, 'flag' => $flag])) }}">All</a>
        @foreach ($statusOptions as $option)
            <a class="{{ $status === $option ? 'active' : '' }}"
               href="{{ route('sales.orders.index', array_filter(['status' => $option, 'q' => $q, 'flag' => $flag])) }}">
                {{ ucfirst(str_replace('_', ' ', $option)) }}
            </a>
        @endforeach
        @foreach ($catalogViews as $view)
            <a href="{{ $view['url'] }}">{{ $view['label'] }}</a>
        @endforeach
    </nav>

    {{-- FilterBar (§18.2): every control labelled, reset always reachable --}}
    <form class="erp-filterbar" method="GET" action="{{ route('sales.orders.index') }}" role="search">
        @if ($flag !== null)
            <input type="hidden" name="flag" value="{{ $flag }}">
        @endif

        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Order number</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" id="q" name="q" value="{{ $q }}" placeholder="SO-2026-01845"
                       data-erp-search autocomplete="off">
            </div>
        </div>

        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Any status</option>
                @foreach ($statusOptions as $option)
                    <option value="{{ $option }}" @selected($status === $option)>{{ str_replace('_', ' ', $option) }}</option>
                @endforeach
            </select>
        </div>

        <div class="erp-filterbar-actions">
            @if ($q || ($status !== null && $status !== ''))
                <a class="btn btn-link" href="{{ route('sales.orders.index', array_filter(['flag' => $flag])) }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-funnel" aria-hidden="true"></i> Apply
            </button>
        </div>
    </form>

    @if ($q || ($status !== null && $status !== '') || $flag !== null)
        <div class="erp-active-filters">
            @if ($q)
                <span class="erp-chip erp-chip-soft">Search: {{ $q }}</span>
            @endif
            @if ($status)
                <span class="erp-chip erp-chip-soft">Status: {{ str_replace('_', ' ', $status) }}</span>
            @endif
            @if ($flag)
                <span class="erp-chip erp-chip-warn">Flag: {{ $flag }}</span>
            @endif
        </div>
    @endif

    <form method="POST" id="bulk-order-form" data-no-submit-once>
        @csrf

        <div class="erp-table-shell" data-erp-table>
            {{-- One bulk surface, revealed on selection — replaces a 10-button wall --}}
            @if ($bulkAllowed)
                <div class="erp-bulkbar" data-erp-bulkbar>
                    <i class="bi bi-check2-square" aria-hidden="true"></i>
                    <strong><span data-erp-bulk-count>0</span></strong> selected
                    <div class="erp-bulkbar-actions">
                        @if ($canConfirm)
                            <button class="btn btn-sm btn-outline-light" type="submit"
                                    formaction="{{ route('sales.orders.bulk.confirm') }}">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i> Confirm
                            </button>
                        @endif
                        @if ($canAssign)
                            <button class="btn btn-sm btn-outline-light" type="submit"
                                    formaction="{{ route('sales.orders.bulk.assign-courier') }}">
                                <i class="bi bi-truck" aria-hidden="true"></i> Assign courier
                            </button>
                        @endif
                        @if ($canPrint)
                            <button class="btn btn-sm btn-outline-light" type="submit"
                                    formaction="{{ route('sales.orders.bulk.print-invoice') }}">
                                <i class="bi bi-printer" aria-hidden="true"></i> Invoice
                            </button>
                        @endif
                        @if ($canPrintSlip)
                            <button class="btn btn-sm btn-outline-light" type="submit"
                                    formaction="{{ route('sales.orders.bulk.print-packing-slip') }}">
                                <i class="bi bi-box" aria-hidden="true"></i> Packing slip
                            </button>
                        @endif
                        @if ($canPrintLabel)
                            <button class="btn btn-sm btn-outline-light" type="submit"
                                    formaction="{{ route('sales.orders.bulk.print-shipping-label') }}">
                                <i class="bi bi-upc" aria-hidden="true"></i> Label
                            </button>
                        @endif
                        @if ($canNotify)
                            <button class="btn btn-sm btn-outline-light" type="submit"
                                    formaction="{{ route('sales.orders.bulk.sms') }}">
                                <i class="bi bi-chat-dots" aria-hidden="true"></i> SMS
                            </button>
                            <button class="btn btn-sm btn-outline-light" type="submit"
                                    formaction="{{ route('sales.orders.bulk.whatsapp') }}">
                                <i class="bi bi-whatsapp" aria-hidden="true"></i> WhatsApp
                            </button>
                            <button class="btn btn-sm btn-outline-light" type="submit"
                                    formaction="{{ route('sales.orders.bulk.email') }}">
                                <i class="bi bi-envelope" aria-hidden="true"></i> Email
                            </button>
                        @endif
                        @if ($canCancel)
                            <button class="btn btn-sm btn-outline-danger" type="submit"
                                    formaction="{{ route('sales.orders.bulk.cancel') }}"
                                    formnovalidate>
                                <i class="bi bi-x-circle" aria-hidden="true"></i> Cancel
                            </button>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Bulk inputs (reason / courier / rider / template) collapse into a
                 disclosure so the table stays the first thing the eye meets. --}}
            @if ($bulkAllowed)
                <details class="px-3 pt-3">
                    <summary class="erp-summary">Bulk action settings</summary>
                    <div class="row g-2 align-items-end mt-1">
                        <div class="col-md-3">
                            <label class="form-label" for="reason">Cancel reason (optional)</label>
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
                            <div class="col-md-4">
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
                    </div>

                    @if (session('bulk_messages'))
                        <ul class="list-unstyled mb-0 mt-2 small text-danger">
                            @foreach (session('bulk_messages') as $bulkMessage)
                                <li>{{ $bulkMessage }}</li>
                            @endforeach
                        </ul>
                    @endif
                </details>
            @endif

            <div class="erp-table-scroll">
                <table class="table erp-table erp-table-stack">
                    <thead>
                        <tr>
                            @if ($bulkAllowed)
                                <th style="width: 2.4rem;">
                                    <input class="form-check-input" type="checkbox" data-erp-select-all
                                           aria-label="Select all orders on this page">
                                </th>
                            @endif
                            <th>Order</th>
                            <th>Placed</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th>Stock</th>
                            <th class="erp-th-num">Total (BDT)</th>
                            @if ($flag === 'suspicious')
                                <th>Risk</th>
                                <th>Review</th>
                            @endif
                            <th class="erp-th-actions">Open</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($orders as $order)
                            <tr>
                                @if ($bulkAllowed)
                                    <td data-label="Select">
                                        <input class="form-check-input" type="checkbox" name="order_ids[]"
                                               value="{{ $order->id }}" data-erp-row-select
                                               aria-label="Select {{ $order->order_no }}">
                                    </td>
                                @endif
                                <td data-label="Order">
                                    @if ($perm('sales.orders.view'))
                                        <a class="erp-row-link" href="{{ route('sales.orders.show', $order) }}">{{ $order->order_no }}</a>
                                    @else
                                        <span class="fw-semibold">{{ $order->order_no }}</span>
                                    @endif
                                </td>
                                <td data-label="Placed" class="erp-td-muted">{{ optional($order->order_date)->toDateString() }}</td>
                                <td data-label="Customer">{{ $order->customer?->name ?? '—' }}</td>
                                <td data-label="Status"><span class="erp-status erp-status-{{ str_replace('_', '-', $order->status) }}">{{ str_replace('_', ' ', $order->status) }}</span></td>
                                <td data-label="Stock">
                                    @if ($order->stock_reserved)
                                        <span class="erp-chip erp-chip-ok">reserved</span>
                                    @else
                                        <span class="erp-chip erp-chip-outline">not reserved</span>
                                    @endif
                                </td>
                                <td data-label="Total" class="erp-td-num erp-amount">{{ number_format((float) $order->grand_total, 2) }}</td>
                                @if ($flag === 'suspicious')
                                    @php $suspicion = $order->suspiciousFlag; @endphp
                                    <td data-label="Risk">
                                        @if ($suspicion)
                                            <span class="badge {{ $suspicion->level === 'high' ? 'text-bg-danger' : 'text-bg-warning' }}">
                                                {{ $suspicion->score }} · {{ $suspicion->level }}
                                            </span>
                                            <details class="small mt-1">
                                                <summary class="erp-summary">Why</summary>
                                                <ul class="mb-0 ps-3">
                                                    @foreach ($suspicion->rules ?? [] as $rule)
                                                        <li>{{ $rule['evidence'] ?? '' }} (+{{ $rule['weight'] ?? 0 }})</li>
                                                    @endforeach
                                                </ul>
                                            </details>
                                        @else
                                            <span class="text-body-secondary">—</span>
                                        @endif
                                    </td>
                                    <td data-label="Review">
                                        @if ($suspicion && $suspicion->status === 'reviewed')
                                            <span class="badge text-bg-secondary">
                                                {{ $suspicion->decision === 'confirmed_legitimate' ? 'Legitimate' : 'Confirmed suspicious' }}
                                            </span>
                                            <div class="small text-body-secondary">
                                                {{ $suspicion->reviewed_at?->format('Y-m-d H:i') }}
                                                @if ($suspicion->review_notes)
                                                    <br>{{ $suspicion->review_notes }}
                                                @endif
                                            </div>
                                        @elseif ($suspicion && $canReview)
                                            {{-- The table sits inside the bulk form; nested forms are invalid HTML,
                                                 so the real form lives after it and the controls associate via the
                                                 HTML5 form attribute. --}}
                                            <span class="d-inline-flex gap-1 align-items-center">
                                                <input form="review-{{ $order->id }}" class="form-control form-control-sm"
                                                       name="notes" maxlength="500"
                                                       placeholder="Notes (optional)" aria-label="Review notes">
                                                <button form="review-{{ $order->id }}" class="btn btn-sm btn-outline-secondary"
                                                        name="decision" value="confirmed_legitimate">Legit</button>
                                                <button form="review-{{ $order->id }}" class="btn btn-sm btn-outline-danger"
                                                        name="decision" value="confirmed_suspicious">Fake</button>
                                            </span>
                                        @else
                                            <span class="text-body-secondary small">Awaiting review</span>
                                        @endif
                                    </td>
                                @endif
                                <td data-label="Open" class="erp-td-actions">
                                    @if ($perm('sales.orders.view'))
                                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('sales.orders.show', $order) }}">
                                            View
                                        </a>
                                    @endif
                                    @if ($order->status === 'pending' && $perm('sales.orders.edit'))
                                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('sales.orders.edit', $order) }}">
                                            Edit
                                        </a>
                                    @endif
                                    @if (in_array($order->status, ['return_requested', 'return_approved', 'returned', 'refunded'], true)
                                        && $perm('returns.view'))
                                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('sales.returns.index') }}">Returns</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $columns }}" class="p-0">
                                    <div class="erp-empty">
                                        <span class="erp-empty-icon"><i class="bi bi-receipt" aria-hidden="true"></i></span>
                                        <p>
                                            @if ($flag === 'suspicious')
                                                Nothing is flagged as suspicious
                                            @elseif ($status !== null && $status !== '')
                                                No orders with status “{{ str_replace('_', ' ', $status) }}”
                                            @else
                                                No sales orders yet
                                            @endif
                                        </p>
                                        <small>
                                            @if ($flag === 'suspicious')
                                                Nothing scored at or above 40 points in the last 90 days — that is the desired state.
                                            @elseif ($q || $status)
                                                Clear the filters to widen the search, or create the order the customer is asking about.
                                            @else
                                                The first order you take will appear here with its reservation and payment state.
                                            @endif
                                        </small>
                                        @if ($q || $status)
                                            <div class="erp-empty-actions">
                                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('sales.orders.index') }}">Clear filters</a>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="erp-table-foot">
                <span>
                    @if ($orders->total() > 0)
                        Showing <strong>{{ $orders->firstItem() }}–{{ $orders->lastItem() }}</strong>
                        of <strong>{{ number_format($orders->total()) }}</strong> orders
                        · amounts in BDT
                    @else
                        No rows to show
                    @endif
                </span>
                {{ $orders->links() }}
            </div>
        </div>
    </form>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

    @if ($flag === 'suspicious')
        @foreach ($orders as $order)
            @if ($order->suspiciousFlag && $order->suspiciousFlag->status !== 'reviewed' && $canReview)
                <form id="review-{{ $order->id }}" method="POST"
                      action="{{ route('sales.orders.suspicious-review', $order) }}" hidden>
                    @csrf
                </form>
            @endif
        @endforeach
    @endif
@endsection
