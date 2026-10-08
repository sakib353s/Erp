@php
    /* §12-15 — the utility desk: this month's bills, on six shelves. */
    $money = fn ($value): string => '৳'.number_format((float) $value, 2);
    $tiles = $families;
@endphp

<x-ui.page-header
    eyebrow="Business Management · Utility Bills"
    title="What the premises cost, and what is still owed for them"
    subtitle="Every bill in one table, because power, water, gas, connectivity and rent are the same document with a different counterparty behind it. Filing a bill records what is owed; paying it posts one journal entry — the provider's expense account on the debit side, the account the money left on the credit side — and the register keeps the entry number, so “was this paid?” is answered from the ledger rather than from a tick."
    :pin="true">
    <x-slot:actions>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('business.utilities.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> File a bill
            </a>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('business.utilities.renewals') }}">
            <i class="bi bi-calendar2-week" aria-hidden="true"></i> Renewal reminders
            @if ($overdue->isNotEmpty())
                <span class="erp-chip erp-chip-danger ms-1">{{ $overdue->count() }} overdue</span>
            @endif
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('business.utilities.providers') }}">
            <i class="bi bi-plug" aria-hidden="true"></i> Providers
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Billed this month" :value="$money($summary['totals']['billed'])" icon="bi-receipt" hero
              :hint="$summary['totals']['bills'].' bill(s) for '.$summary['period']" />
    <x-ui.kpi label="Paid this month" :value="$money($summary['totals']['paid'])" icon="bi-check2-circle"
              hint="Money that has actually left, by the day it left" />
    <x-ui.kpi label="Still owed" :value="$money($summary['totals']['outstanding'])" icon="bi-hourglass-split"
              hint="Filed and not yet paid — the figure the supplier is holding" />
    <x-ui.kpi label="Overdue" :value="$summary['totals']['overdue']" icon="bi-exclamation-triangle"
              :hint="$summary['totals']['overdue'] > 0 ? 'Past the due date and still unpaid' : 'Nothing has gone past its date'" />
    <x-ui.kpi label="Due within {{ \App\Domain\Business\UtilityBill::DUE_SOON_DAYS }} days"
              :value="$summary['totals']['due_soon']" icon="bi-calendar-check"
              hint="Close enough that paying them now is cheaper than explaining later" />
    <x-ui.kpi label="Approval limit" :value="$money($threshold)" icon="bi-shield-check"
              hint="At or above this a payment waits for a second signature" />
</div>

@if ($overdue->isNotEmpty())
    <div class="erp-note erp-note-warn mb-3">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <div>
            <strong>{{ $overdue->count() }} bill(s) have gone past their due date.</strong>
            A connection that is cut for non-payment costs more than the bill: {{ $overdue->take(3)->map(fn ($bill) => $bill->provider?->name.' ('.$bill->due_date?->format('d M').')')->implode(', ') }}{{ $overdue->count() > 3 ? ', and '.($overdue->count() - 3).' more' : '' }}.
            <a href="{{ route('business.utilities.renewals') }}">Open the reminders lens</a>.
        </div>
    </div>
@endif

<section class="erp-card mb-3">
    <header class="erp-card-head">
        <div>
            <h2 class="erp-card-title">The shelves</h2>
            <p class="erp-card-sub">Each shelf is the same table, filtered by who sends the bill. The account a bill posts to comes from its provider, never from a dropdown on the form.</p>
        </div>
    </header>
    <div class="px-3 pb-2">
        @foreach ($tiles as $key => $tile)
            @php($totals = $summary['families'][$key] ?? ['bills' => 0, 'billed' => 0, 'outstanding' => 0, 'paid' => 0])
            <div class="erp-list-row">
                <div class="erp-list-row-main">
                    <a class="erp-cell-strong" href="{{ route('business.utilities.index', ['family' => $key]) }}">
                        <i class="bi {{ $tile['icon'] }} me-1" aria-hidden="true"></i>{{ $tile['label'] }}
                    </a>
                    <div class="erp-td-muted">
                        {{ $totals['bills'] }} bill(s) this month · billed {{ $money($totals['billed']) }} · still owed {{ $money($totals['outstanding']) }}
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="erp-chip {{ $totals['outstanding'] > 0 ? 'erp-chip-soft' : 'erp-chip-outline' }}">
                        {{ $totals['outstanding'] > 0 ? 'Open balance' : 'Settled' }}
                    </span>
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('business.utilities.index', ['family' => $key]) }}">Open</a>
                </div>
            </div>
        @endforeach
    </div>
</section>

<form class="erp-filterbar" method="GET" action="{{ route('business.utilities.index') }}">
    <div class="erp-filter">
        <label class="form-label" for="family">Shelf</label>
        <select class="form-select" name="family" id="family" data-erp-autosubmit>
            <option value="">Every provider</option>
            @foreach ($families as $key => $tile)
                <option value="{{ $key }}" @selected($family === $key)>{{ $tile['label'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="period">Month</label>
        <select class="form-select" name="period" id="period" data-erp-autosubmit>
            <option value="">Every month</option>
            @foreach ($periods as $option)
                <option value="{{ $option }}" @selected(request('period') === $option)>{{ $option }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="status">Status</label>
        <select class="form-select" name="status" id="status" data-erp-autosubmit>
            <option value="">Every status</option>
            @foreach ([\App\Domain\Business\UtilityBill::STATUS_RECORDED, \App\Domain\Business\UtilityBill::STATUS_PENDING, \App\Domain\Business\UtilityBill::STATUS_PAID, \App\Domain\Business\UtilityBill::STATUS_VOID] as $option)
                <option value="{{ $option }}" @selected(request('status') === $option)>{{ str_replace('_', ' ', ucfirst($option)) }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <a class="btn btn-link" href="{{ route('business.utilities.index') }}">Reset</a>
    </div>
</form>

<x-ui.table-shell title="{{ $family ? $families[$family]['label'].' bills' : 'Every bill' }}" :count="$bills->total().' bill(s)'">
    <thead>
        <tr>
            <th>Bill</th>
            <th>Provider</th>
            <th>For the month</th>
            <th>Due</th>
            <th class="text-end">Amount</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($bills as $bill)
            <tr>
                <td data-label="Bill">
                    <a class="erp-cell-strong" href="{{ route('business.utilities.show', $bill) }}">{{ $bill->bill_no }}</a>
                    <div class="erp-td-muted">{{ $bill->branch?->name ?? 'Company-wide' }}</div>
                </td>
                <td data-label="Provider">
                    {{ $bill->provider?->name ?? '—' }}
                    <div class="erp-td-muted">
                        {{ $bill->provider?->familyLabel() ?? '' }}
                        @if ($bill->consumption !== null)
                            · {{ number_format((float) $bill->consumption, 2) }} {{ $bill->consumption_unit }}
                        @endif
                    </div>
                </td>
                <td data-label="For the month">{{ $bill->periodLabel() }}</td>
                <td data-label="Due">
                    {{ $bill->due_date?->format('d M Y') }}
                    @if ($bill->isOverdue())
                        <div class="erp-td-muted text-danger">{{ abs((int) $bill->daysToDue()) }} day(s) past</div>
                    @elseif ($bill->isOpen() && $bill->daysToDue() !== null)
                        <div class="erp-td-muted">in {{ $bill->daysToDue() }} day(s)</div>
                    @endif
                </td>
                <td data-label="Amount" class="erp-td-num text-end">{{ $money($bill->amount) }}</td>
                <td data-label="Status"><x-ui.status :value="$bill->state()" :label="$bill->stateLabel()" /></td>
                <td class="erp-td-actions">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('business.utilities.show', $bill) }}">Open</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <x-ui.empty
                        title="No bills on this shelf yet"
                        text="File the bill when it arrives: the provider, the month it covers, what it asks for and when it is due. Nothing touches the ledger until the money actually leaves, and then it does so with one entry you can point at."
                        icon="bi-lightning-charge"
                        :action="$canManage ? 'File a bill' : null"
                        :href="$canManage ? route('business.utilities.create') : null" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($bills->hasPages())
        <x-slot:footer>{{ $bills->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<x-ui.related-pages />
