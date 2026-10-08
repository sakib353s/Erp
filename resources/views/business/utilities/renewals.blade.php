@php
    /* §12-15 — the renewal lens: what has gone past, what is coming, what is next. */
    $money = fn ($value): string => '৳'.number_format((float) $value, 2);
@endphp

<x-ui.page-header
    eyebrow="Business Management · Utility Bills · Renewal Reminders"
    title="What falls due, and when"
    subtitle="A utility bill is the one creditor that arrives on a date whether or not anybody opens the post. This is the same table sorted by the clock instead of by the month: what has already gone past its date, what is due inside the next two weeks, and the day each provider's next bill is expected — so the payment run is planned rather than reacted to. The daily watch notifies the desk at 07:00 with the same two lists."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('business.utilities.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> The desk
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Overdue" :value="$overdue->count()" icon="bi-exclamation-triangle" hero
              :hint="$overdue->isNotEmpty()
                  ? $money($overdue->sum('amount')).' has gone past its date'
                  : 'Nothing is past its due date'" />
    <x-ui.kpi label="Due within {{ \App\Domain\Business\UtilityBill::DUE_SOON_DAYS }} days"
              :value="$due->count()" icon="bi-calendar-check"
              :hint="$due->isNotEmpty() ? $money($due->sum('amount')).' to find' : 'Nothing falls due this fortnight'" />
    <x-ui.kpi label="Still owed in total" :value="$money($summary['totals']['outstanding'])" icon="bi-hourglass-split"
              hint="Every unpaid bill on the register" />
    <x-ui.kpi label="Approval limit" :value="$money($threshold)" icon="bi-shield-check"
              hint="Payments at or above this wait for a second signature" />
</div>

@if ($overdue->isEmpty() && $due->isEmpty())
    <x-ui.empty
        icon="bi-emoji-smile"
        title="Nothing is due"
        text="Every bill on the register is either paid or comfortably in the future. The daily watch will say the same thing at 07:00." />
@endif

@if ($overdue->isNotEmpty())
    <section class="erp-card mb-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Past their date</h2>
                <p class="erp-card-sub">Paying these is cheaper than explaining a disconnection to the branch that lost power.</p>
            </div>
            <span class="erp-chip erp-chip-danger">{{ $money($overdue->sum('amount')) }}</span>
        </header>
        <div class="table-responsive">
            <table class="table erp-table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Bill</th>
                        <th>Provider</th>
                        <th>Month</th>
                        <th>Was due</th>
                        <th class="text-end">Amount</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($overdue as $bill)
                        <tr>
                            <td><a class="erp-cell-strong" href="{{ route('business.utilities.show', $bill) }}">{{ $bill->bill_no }}</a></td>
                            <td>
                                {{ $bill->provider?->name ?? '—' }}
                                <div class="erp-td-muted">{{ $bill->provider?->familyLabel() }}</div>
                            </td>
                            <td>{{ $bill->periodLabel() }}</td>
                            <td class="text-danger">
                                {{ $bill->due_date?->format('d M Y') }}
                                <div class="erp-td-muted">{{ abs((int) $bill->daysToDue()) }} day(s) ago</div>
                            </td>
                            <td class="erp-td-num text-end">{{ $money($bill->amount) }}</td>
                            <td class="erp-td-actions">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('business.utilities.show', $bill) }}">Pay it</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

@if ($due->isNotEmpty())
    <section class="erp-card mb-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Coming inside {{ \App\Domain\Business\UtilityBill::DUE_SOON_DAYS }} days</h2>
                <p class="erp-card-sub">Still time, and this is when paying is cheap.</p>
            </div>
            <span class="erp-chip erp-chip-soft">{{ $money($due->sum('amount')) }}</span>
        </header>
        <div class="table-responsive">
            <table class="table erp-table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Bill</th>
                        <th>Provider</th>
                        <th>Due on</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($due as $bill)
                        <tr>
                            <td><a class="erp-cell-strong" href="{{ route('business.utilities.show', $bill) }}">{{ $bill->bill_no }}</a></td>
                            <td>
                                {{ $bill->provider?->name ?? '—' }}
                                <div class="erp-td-muted">{{ $bill->periodLabel() }}</div>
                            </td>
                            <td>
                                {{ $bill->due_date?->format('d M Y') }}
                                <div class="erp-td-muted">in {{ $bill->daysToDue() }} day(s)</div>
                            </td>
                            <td class="erp-td-num text-end">{{ $money($bill->amount) }}</td>
                            <td><x-ui.status :value="$bill->state()" :label="$bill->stateLabel()" /></td>
                            <td class="erp-td-actions">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('business.utilities.show', $bill) }}">Open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<section class="erp-card">
    <header class="erp-card-head">
        <div>
            <h2 class="erp-card-title">The standing charges</h2>
            <p class="erp-card-sub">Providers that bill on a day of the month, and the day it is. A bill that has not arrived by then is worth chasing — the provider's system does not forget, and a late payment surcharge is the most avoidable money on this page.</p>
        </div>
    </header>
    <div class="table-responsive">
        <table class="table erp-table mb-0 align-middle">
            <thead>
                <tr>
                    <th>Provider</th>
                    <th>Shelf</th>
                    <th>Premises</th>
                    <th>Bills on</th>
                    <th>Posts to</th>
                    <th class="text-end">Last bill</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($next as $provider)
                    @php($last = $provider->bills()->orderByDesc('period_month')->first())
                    <tr>
                        <td>
                            <span class="erp-cell-strong">{{ $provider->name }}</span>
                            <div class="erp-td-muted">{{ $provider->consumer_no ?? 'no consumer number on file' }}</div>
                        </td>
                        <td>{{ $provider->familyLabel() }}</td>
                        <td class="erp-td-muted">{{ $provider->premises ?? '—' }}</td>
                        <td>
                            <span class="erp-chip erp-chip-outline">day {{ $provider->due_day }}</span>
                        </td>
                        <td class="erp-td-muted">{{ $provider->account?->code ?? '—' }} {{ $provider->account?->name ?? '' }}</td>
                        <td class="erp-td-num text-end">
                            {{ $last !== null ? $money($last->amount) : '—' }}
                            @if ($last !== null)
                                <div class="erp-td-muted">{{ $last->periodLabel() }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-ui.empty
                                icon="bi-plug"
                                title="No provider bills on a fixed day yet"
                                text="Record the day each provider sends its bill and this list becomes the payment calendar." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<x-ui.related-pages />
