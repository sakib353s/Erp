@extends('layouts.app')

@section('page_title', 'Expiry desk')

@section('content')
    @php($labels = [
        'expired' => ['expired', 'Past their date and still holding stock. Not sellable — but not gone either.'],
        'expiring' => ['expiring', "Inside the alert window, so the goods can still be sold, moved or returned while they are worth something."],
        'undated' => ['undated', 'Nobody recorded a date when these arrived, so nothing can warn about them. Giving them a date is the cheapest fix in the warehouse.'],
    ])

    <x-ui.page-header
        eyebrow="Inventory · Batch & Serial"
        title="Expiry Desk"
        subtitle="Three lists: what has gone off, what is about to, and what nobody dated. Sorting the first list is a decision — move it, return it, or write it off; this screen tracks the dates, it does not write stock off."
        :pin="true">
        <x-slot:actions>
            @if ($perm('inventory.batch.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.batches.index') }}">
                    <i class="bi bi-upc-scan" aria-hidden="true"></i> Batch register
                </a>
            @endif
            @if ($perm('inventory.loss.create'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.damage.index') }}">
                    <i class="bi bi-clipboard-x" aria-hidden="true"></i> Damage &amp; loss
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        @foreach (['expired' => 'bi-calendar-x', 'expiring' => 'bi-hourglass-split', 'undated' => 'bi-question-circle'] as $key => $icon)
            <x-ui.kpi
                :label="ucfirst($labels[$key][0])"
                :value="number_format($buckets[$key]['batches'])"
                :icon="$icon"
                :hint="$buckets[$key]['batches'] > 0
                    ? number_format($buckets[$key]['qty'], 4).' units · '.number_format($buckets[$key]['value'], 2)
                    : 'Nothing in this list'"
                :href="route('inventory.batches.expiry', ['type' => $key, 'days' => $withinDays])" />
        @endforeach
        <x-ui.kpi label="Alert window" :value="$withinDays.' days'" icon="bi-sliders"
                  hint="Settings › Inventory › expiry_alert_days — what counts as 'expiring soon'" />
    </div>

    @if ($type === 'expired' && $buckets['expired']['batches'] > 0)
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-calendar-x" aria-hidden="true"></i>
            <div>
                <strong>{{ number_format($buckets['expired']['value'], 2) }} of stock is past its date.</strong>
                The value is still in inventory until somebody writes it off, so the books say it is worth more than
                the shelf does. Decide per batch: <em>Write off this batch</em> opens the write-off document with the
                product and quantity already filled in (it is approved by somebody else before stock moves), or correct
                the date here if the label was misread.
            </div>
        </div>
    @elseif ($type === 'undated' && $buckets['undated']['batches'] > 0)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-question-circle" aria-hidden="true"></i>
            <div>
                <strong>{{ $buckets['undated']['batches'] }} batch(es) arrived without a date.</strong>
                Future receipts of these batches will not fill the date in on their own — a date that is already blank
                is filled once, but a date that was set is never overwritten. Record the real one below and it starts
                being watched like any other.
            </div>
        </div>
    @endif

    <x-ui.table-shell :count="$rows->count().' '.$labels[$type][1]">
        <x-slot:title>{{ ucfirst($labels[$type][0]) }} batches</x-slot:title>
        <thead>
            <tr>
                <th>Batch</th>
                <th>Product</th>
                <th>Warehouse</th>
                <th>Expires</th>
                <th class="erp-th-num">On hand</th>
                <th class="erp-th-num">Value</th>
                <th>Correct the date</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $batch)
                @php($days = $batch->daysToExpiry())
                <tr>
                    <td data-label="Batch">
                        <span class="erp-cell-strong"><code>{{ $batch->batch_no }}</code></span>
                        @if ($batch->manufactured_on)
                            <span class="d-block erp-td-muted small">made {{ $batch->manufactured_on->format('d M Y') }}</span>
                        @endif
                    </td>
                    <td data-label="Product">
                        {{ $batch->product?->name }}
                        <span class="d-block erp-td-muted small"><code>{{ $batch->product?->sku }}</code></span>
                    </td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $batch->warehouse?->name }}</td>
                    <td data-label="Expires">
                        @if ($batch->expires_on)
                            {{ $batch->expires_on->format('d M Y') }}
                            <span class="d-block erp-td-muted small">
                                @if ($days < 0)
                                    {{ number_format(abs($days)) }} day(s) ago
                                @elseif ($days === 0)
                                    today
                                @else
                                    in {{ number_format($days) }} day(s)
                                @endif
                            </span>
                        @else
                            <span class="erp-td-muted">Not recorded</span>
                        @endif
                    </td>
                    <td data-label="On hand" class="erp-td-num">{{ number_format((float) $batch->remaining_qty, 4) }}</td>
                    <td data-label="Value" class="erp-td-num">{{ number_format((float) $batch->remaining_value, 2) }}</td>
                    <td data-label="Correct the date">
                        @if ($type === 'expired' && $perm('inventory.writeoffs.create'))
                            @php($handOver = [
                                'warehouse' => $batch->warehouse_id,
                                'product' => $batch->product_id,
                                'qty' => (float) $batch->remaining_qty,
                                'source' => 'on_hand',
                                'reason' => 'Expired batch '.$batch->batch_no.' ('.$batch->expires_on?->format('d M Y').')',
                            ])
                            {{-- §04-41: the disposal is the write-off document that
                                 already exists, opened with this batch's context —
                                 not a second way to destroy stock. --}}
                            <a class="btn btn-sm btn-outline-danger mb-2"
                               href="{{ route('inventory.writeoffs.create', $handOver) }}">
                                <i class="bi bi-clipboard-x" aria-hidden="true"></i> Write off this batch
                            </a>
                        @endif
                        @if ($perm('inventory.batch.manage'))
                            <form method="POST" class="d-flex flex-wrap gap-2 align-items-end"
                                  action="{{ route('inventory.batches.expiry.update', $batch) }}">
                                @csrf
                                <div>
                                    <label class="form-label small mb-1" for="expires_on-{{ $batch->id }}">Real expiry date</label>
                                    <input class="form-control form-control-sm" type="date" id="expires_on-{{ $batch->id }}"
                                           name="expires_on" value="{{ $batch->expires_on?->toDateString() }}" style="max-width: 11rem;">
                                </div>
                                <div class="flex-grow-1">
                                    <label class="form-label small mb-1" for="reason-{{ $batch->id }}">Why</label>
                                    <input class="form-control form-control-sm" type="text" id="reason-{{ $batch->id }}"
                                           name="reason" maxlength="500" required placeholder="label misread, supplier confirmed…">
                                </div>
                                <button class="btn btn-sm btn-outline-secondary" type="submit">Record</button>
                            </form>
                            @php($change = $batch->expiryChanges->first())
                            @if ($change)
                                <span class="d-block erp-td-muted small mt-1" title="{{ $change->reason }}">
                                    last corrected by {{ $change->actor?->name ?? 'a user' }}
                                    @if ($change->expires_on_before)
                                        from {{ $change->expires_on_before->format('d M Y') }}
                                    @endif
                                </span>
                            @endif
                        @else
                            <span class="erp-td-muted small">Dates are corrected by somebody who may manage batches</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty icon="bi-calendar-check"
                            :title="'No batch is '.$labels[$type][0]"
                            :text="'Nothing in the register matches this list right now — from here a batch is '.
                                (['expired' => 'past its date', 'expiring' => 'inside the alert window', 'undated' => 'missing its date'][$type]).' with stock still on hand.'"
                            action="Back to the register" :href="route('inventory.batches.index')" />
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if ($rows->isNotEmpty())
            <x-slot:footer>
                <span class="erp-td-muted">
                    {{ number_format((float) $rows->sum('remaining_qty'), 4) }} units ·
                    {{ number_format((float) $rows->sum('remaining_value'), 2) }} of stock in this list
                    @if ($hidden > 0), and {{ number_format($hidden) }} more batch(es) beyond the first 200 @endif
                </span>
                <span class="erp-td-muted">A corrected date keeps its old value and its reason for good.</span>
            </x-slot:footer>
        @endif
    </x-ui.table-shell>

    <p class="erp-td-muted mt-3 mb-0">
        Outbound movements never invent a batch: FEFO decides which batch a sale or an issue takes from, and the
        layer it consumes is the one whose date comes first. This screen keeps the dates honest so that decision is
        made on real ones.
    </p>
@endsection
