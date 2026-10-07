@extends('layouts.app')

@section('page_title', 'Write-offs')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Damage & loss"
        title="Stock write-offs"
        subtitle="The document that decides value leaves the company. Nothing moves until someone other than the person who raised it approves it — and a rejection moves nothing at all."
        :pin="true">
        <x-slot:actions>
            @if ($perm('inventory.writeoffs.create'))
                <a class="btn btn-primary" href="{{ route('inventory.writeoffs.create') }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Raise a write-off
                </a>
            @endif
            @if ($perm('inventory.stock.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.damage.index') }}">
                    <i class="bi bi-clipboard-x" aria-hidden="true"></i> Damage records
                </a>
            @endif
            @if ($perm('inventory.reports.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.reports.damage') }}">
                    <i class="bi bi-graph-down" aria-hidden="true"></i> Damage analytics
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Pending approval" :value="number_format($counts['pending'])" icon="bi-hourglass-split"
                  :hint="$status === 'pending_approval' ? 'Currently shown below' : 'Waiting for a second person'"
                  :href="route('inventory.writeoffs.index', ['status' => 'pending_approval'])" />
        <x-ui.kpi label="Approved" :value="number_format($counts['approved'])" icon="bi-check2-circle"
                  hint="Value has left stock and the books"
                  :href="route('inventory.writeoffs.index', ['status' => 'approved'])" />
        <x-ui.kpi label="Rejected" :value="number_format($counts['rejected'])" icon="bi-x-octagon"
                  hint="Nothing moved — the stock stayed put"
                  :href="route('inventory.writeoffs.index', ['status' => 'rejected'])" />
        <x-ui.kpi label="Held as damaged" icon="bi-shield-exclamation"
                  :value="number_format($holdings->sum('qty'), 4)"
                  :hint="'Valued at '.number_format($holdings->sum('value'), 2).' — what a write-off would remove'" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.writeoffs.index') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Any status</option>
                @foreach ([
                    \App\Domain\Inventory\StockWriteoff::STATUS_PENDING => 'Pending approval',
                    \App\Domain\Inventory\StockWriteoff::STATUS_APPROVED => 'Approved',
                    \App\Domain\Inventory\StockWriteoff::STATUS_REJECTED => 'Rejected',
                    \App\Domain\Inventory\StockWriteoff::STATUS_CANCELLED => 'Cancelled',
                ] as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="warehouse">Warehouse</label>
            <select class="form-select" id="warehouse" name="warehouse">
                <option value="">All warehouses</option>
                @foreach ($warehouses as $option)
                    <option value="{{ $option->id }}" @selected($warehouse === $option->id)>{{ $option->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if ($status !== '' || $warehouse !== null)
                <a class="btn btn-link" href="{{ route('inventory.writeoffs.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    @if ($holdings->isNotEmpty())
        <x-ui.table-shell title="In the damaged compartment right now" :count="$holdings->count().' row(s)'">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Warehouse</th>
                    <th class="erp-th-num">Damaged qty</th>
                    <th class="erp-th-num">Unit cost</th>
                    <th class="erp-th-num">Value</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($holdings as $row)
                    <tr>
                        <td data-label="Product">
                            <span class="erp-cell-strong">{{ $row['product']?->name }}</span>
                            <span class="d-block erp-td-muted">{{ $row['product']?->sku }}</span>
                        </td>
                        <td data-label="Warehouse" class="erp-td-muted">{{ $row['warehouse']?->name }}</td>
                        <td data-label="Damaged qty" class="erp-td-num">{{ number_format($row['qty'], 4) }}</td>
                        <td data-label="Unit cost" class="erp-td-num erp-td-muted">{{ number_format($row['unit_cost'], 4) }}</td>
                        <td data-label="Value" class="erp-td-num">{{ number_format($row['value'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2">Total held as damaged</th>
                    <th class="erp-th-num">{{ number_format($holdings->sum('qty'), 4) }}</th>
                    <th></th>
                    <th class="erp-th-num">{{ number_format($holdings->sum('value'), 2) }}</th>
                </tr>
            </tfoot>
        </x-ui.table-shell>
    @endif

    <x-ui.table-shell class="mt-3" title="Write-offs" :count="$writeoffs->total().' document(s)'">
        <thead>
            <tr>
                <th>Document</th>
                <th>Date</th>
                <th>Warehouse</th>
                <th>From</th>
                <th>Reason</th>
                <th class="erp-th-num">Value</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($writeoffs as $writeoff)
                <tr>
                    <td data-label="Document">
                        <code>{{ $writeoff->code }}</code>
                        <span class="d-block erp-td-muted small">
                            Raised by {{ $writeoff->creator?->name ?? '—' }}
                            @if ($writeoff->approver) · decided by {{ $writeoff->approver->name }} @endif
                        </span>
                    </td>
                    <td data-label="Date">{{ $writeoff->writeoff_date?->format('d M Y') }}</td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $writeoff->warehouse?->name }}</td>
                    <td data-label="From" class="erp-td-muted">{{ $writeoff->sourceLabel() }}</td>
                    <td data-label="Reason" class="erp-td-muted">
                        <span title="{{ $writeoff->reason }}">{{ \Illuminate\Support\Str::limit($writeoff->reason, 60) }}</span>
                        @if ($writeoff->decision_note)
                            <span class="d-block small">Decision: {{ \Illuminate\Support\Str::limit($writeoff->decision_note, 60) }}</span>
                        @endif
                    </td>
                    <td data-label="Value" class="erp-td-num">{{ number_format((float) $writeoff->total_value, 2) }}</td>
                    <td data-label="Status"><x-ui.status :value="$writeoff->status" /></td>
                    <td data-label="" class="erp-td-actions">
                        @if ($writeoff->isPending() && $perm('inventory.writeoffs.approve'))
                            <div class="d-flex flex-column gap-1 align-items-end">
                                <form method="POST" action="{{ route('inventory.writeoffs.approve', $writeoff) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-primary" type="submit">
                                        <i class="bi bi-check2" aria-hidden="true"></i> Approve
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('inventory.writeoffs.reject', $writeoff) }}"
                                      class="d-flex gap-1" data-confirm="Rejecting this write-off moves no stock at all. Continue?">
                                    @csrf
                                    <input class="form-control form-control-sm" name="decision_note" required maxlength="500"
                                           placeholder="Why not" aria-label="Reason for rejecting">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Reject</button>
                                </form>
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty icon="bi-file-earmark-x" title="No write-off here"
                                    text="A write-off is raised against a compartment (damaged, quarantined, or sellable stock) and needs a second person to approve it before any value leaves." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">{{ $writeoffs->links() }}</div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
