@extends('layouts.app')

@section('page_title', 'Petty cash replenishment')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Petty cash · Replenishment"
        title="Putting the float back"
        subtitle="A replenishment is a transfer, not an expense: the spending was recorded voucher by voucher, when each was paid, so recording it again here would count every rickshaw twice. This screen puts the float back to the level it is meant to hold — and nothing else."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.petty-cash') }}">
                <i class="bi bi-cash-coin" aria-hidden="true"></i> The floats
            </a>
            @if ($perm('pettycash.spend'))
                <a class="btn btn-primary" href="{{ route('cash-bank.petty-cash.expenses') }}">
                    <i class="bi bi-receipt" aria-hidden="true"></i> Vouchers
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="erp-note erp-note-ok mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    @error('petty_cash')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Put back this month"
            value="৳ {{ $summary['replenished_this_month'] }}"
            icon="bi-arrow-down-up"
            hint="Transfers into the floats — each one a real move of money" />
        <x-ui.kpi
            label="In the tins now"
            value="৳ {{ $summary['float'] }}"
            icon="bi-cash-stack"
            :hint="'Across '.$summary['active'].' open float(s)'" />
        <x-ui.kpi
            label="Meant to be there"
            value="৳ {{ $summary['imprest'] }}"
            icon="bi-bullseye"
            hint="The levels the floats are replenished back to" />
        <x-ui.kpi
            label="Short of their level"
            value="৳ {{ $summary['shortfall'] }}"
            icon="bi-exclamation-circle"
            hint="Put the whole of this back and every tin is level again" />
    </div>

    <x-ui.table-shell title="Where each float stands" :count="$funds->count().' float(s)'">
        <thead>
            <tr>
                <th>Float</th>
                <th>Custodian</th>
                <th class="erp-th-num">Holds</th>
                <th class="erp-th-num">Level</th>
                <th class="erp-th-num">To put back</th>
                <th>State</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($funds as $row)
                <tr>
                    <td>
                        <span class="erp-cell-strong">{{ $row['fund']->code }}</span>
                        <div class="erp-td-muted">{{ $row['fund']->name }}</div>
                    </td>
                    <td>{{ $row['fund']->custodian?->name ?? '—' }}</td>
                    <td class="erp-td-num">৳ {{ number_format((float) $row['balance'], 2) }}</td>
                    <td class="erp-td-num">৳ {{ number_format((float) $row['fund']->imprest_amount, 2) }}</td>
                    <td class="erp-td-num">
                        <span class="erp-cell-strong">৳ {{ $row['shortfall'] }}</span>
                    </td>
                    <td>
                        <x-ui.status :value="$row['fund']->isActive() ? 'active' : 'closed'" :label="$row['fund']->isActive() ? 'Open' : 'Closed'" />
                    </td>
                    <td class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary"
                           href="{{ route('cash-bank.petty-cash.replenishments', ['fund' => $row['fund']->id]) }}">
                            Look at it
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty
                            title="No float to replenish"
                            icon="bi-arrow-down-up"
                            text="A replenishment needs a float: declare one, pay a few vouchers out of it, and this screen will say what it takes to make the tin level again." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    @php
        // Which money account belongs to a float — so the source list can say
        // out loud that a tin is not a place to top a tin up from.
        $floatAccounts = $funds->mapWithKeys(fn ($row) => [$row['fund']->account_id => $row['fund']->name]);
    @endphp

    @if ($perm('pettycash.replenish') && $funds->isNotEmpty())
        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Put money back into a float</h2>
                    <p class="erp-card-sub">
                        The money comes out of one of the company's own accounts and goes into the float's — a transfer
                        between two accounts the company holds, which is why it never touches the expense reports.
                    </p>
                </div>
            </header>
            <div>
                @if ($sources->isEmpty())
                    <x-ui.empty
                        title="No money account to top the float up from"
                        icon="bi-bank"
                        text="Replenishment moves money from a cash, bank or wallet account. Declare one on the cash & bank desk first." />
                @else
                    <form method="POST" action="{{ route('cash-bank.petty-cash.replenishments.store') }}">
                        @csrf
                        <div class="erp-form-grid">
                            <div class="erp-form-field">
                                <label class="form-label" for="fund_id">Float</label>
                                <select class="form-select" id="fund_id" name="fund_id" required>
                                    @foreach ($funds as $row)
                                        <option value="{{ $row['fund']->id }}" @selected(old('fund_id', $fund?->id) == $row['fund']->id)>
                                            {{ $row['fund']->name }} — holds {{ $row['balance'] }} of {{ number_format((float) $row['fund']->imprest_amount, 2) }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('fund_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="source_account_id">Money comes from</label>
                                <select class="form-select" id="source_account_id" name="source_account_id" required>
                                    @foreach ($sources as $source)
                                        <option value="{{ $source->id }}" @selected(old('source_account_id') == $source->id)>
                                            {{ $source->code }} — {{ $source->name }}@if ($floatAccounts->has($source->id)) (this is a float's own account)@endif
                                        </option>
                                    @endforeach
                                </select>
                                @error('source_account_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="amount">Amount</label>
                                <input class="form-control" id="amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" required>
                                @error('amount')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="replenished_on">Date</label>
                                <input class="form-control" id="replenished_on" name="replenished_on" type="date" value="{{ old('replenished_on', now()->toDateString()) }}" required>
                                @error('replenished_on')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field erp-form-field-wide">
                                <label class="form-label" for="narration">Note</label>
                                <input class="form-control" id="narration" name="narration" value="{{ old('narration') }}" maxlength="300" placeholder="October top-up of the head office tin">
                            </div>
                        </div>
                        <button class="btn btn-primary mt-2" type="submit">
                            <i class="bi bi-arrow-down-up" aria-hidden="true"></i> Put it back
                        </button>
                    </form>
                @endif
            </div>
        </section>
    @endif

    <x-ui.table-shell class="mt-3" title="Replenishments{{ $fund ? ' — '.$fund->name : '' }}" :count="$replenishments->count().' top-up(s) shown'">
        <thead>
            <tr>
                <th>Date</th>
                <th>Transfer</th>
                <th>Float</th>
                <th>From</th>
                <th class="erp-th-num">Amount</th>
                <th>Note</th>
                <th>Recorded by</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($replenishments as $topUp)
                <tr>
                    <td>{{ $topUp->occurred_on->toDateString() }}</td>
                    <td><span class="erp-cell-strong">{{ $topUp->documentNo() ?? '—' }}</span></td>
                    <td>{{ $topUp->fund?->name }}</td>
                    <td>
                        {{ $topUp->transfer?->fromAccount?->name ?? '—' }}
                        <div class="erp-td-muted">{{ $topUp->transfer?->fromAccount?->code }}</div>
                    </td>
                    <td class="erp-td-num">৳ {{ number_format((float) $topUp->amount, 2) }}</td>
                    <td>{{ $topUp->narration ?? '—' }}</td>
                    <td>{{ $topUp->creator?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty
                            title="Nothing has been put back yet"
                            icon="bi-arrow-down-up"
                            text="Replenishments appear here as they are made, each one carrying the transfer number the cash & bank desk issued." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <x-ui.related-pages />
@endsection
