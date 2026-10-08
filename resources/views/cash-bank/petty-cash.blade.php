@extends('layouts.app')

@section('page_title', 'Petty cash')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Petty cash"
        title="The float in the drawer"
        subtitle="A float is real money in a real place, held by a named person. It is its own account in the chart of accounts, so the balance below is the ledger's figure rather than a number this desk keeps. Above the company's limit a voucher is asked for before it is paid, and whoever asked cannot be the person who approves it."
        :pin="true">
        <x-slot:actions>
            @if ($perm('pettycash.spend'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.petty-cash.expenses') }}">
                    <i class="bi bi-receipt" aria-hidden="true"></i> Vouchers
                </a>
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.petty-cash.requests') }}">
                    <i class="bi bi-question-circle" aria-hidden="true"></i> Requests
                    @if ($summary['pending'] > 0)
                        <span class="erp-chip erp-chip-warn ms-1">{{ $summary['pending'] }} waiting</span>
                    @endif
                </a>
            @endif
            @if ($perm('pettycash.replenish'))
                <a class="btn btn-primary" href="{{ route('cash-bank.petty-cash.replenishments') }}">
                    <i class="bi bi-arrow-down-up" aria-hidden="true"></i> Replenish
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
            label="In the tins"
            value="৳ {{ $summary['float'] }}"
            icon="bi-cash-stack"
            :hint="$summary['active'].' open float(s) of '.$summary['funds'].' declared'" />
        <x-ui.kpi
            label="Meant to be there"
            value="৳ {{ $summary['imprest'] }}"
            icon="bi-bullseye"
            hint="The level the open floats are replenished back to — the imprest amount" />
        <x-ui.kpi
            label="Waiting to be put back"
            value="৳ {{ $summary['shortfall'] }}"
            icon="bi-arrow-down-up"
            hint="What it would take to restore every float to its level" />
        <x-ui.kpi
            label="Asked for, not yet paid"
            value="৳ {{ $summary['pending_value'] }}"
            icon="bi-hourglass-split"
            :hint="$summary['pending'].' request(s) — nothing here has reached the ledger'" />
    </div>

    <div class="erp-note erp-note-info mb-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            @if ((float) $threshold > 0)
                At or above <strong>৳ {{ $threshold }}</strong> a voucher is asked for rather than paid, and the answer
                has to come from somebody other than the person who asked. Below it the custodian pays and records it in one step.
            @else
                Every voucher is the custodian's to pay. Set “Petty cash above this needs approval” in
                <a href="{{ route('settings.show', ['group' => 'cash']) }}">Cash &amp; Bank settings</a>
                to make a signature necessary above an amount.
            @endif
        </div>
    </div>

    <x-ui.table-shell title="The floats" :count="$rows->count().' float(s)'">
        <thead>
            <tr>
                <th>Float</th>
                <th>Custodian</th>
                <th>Where it is kept</th>
                <th class="erp-th-num">Holds</th>
                <th class="erp-th-num">Level</th>
                <th class="erp-th-num">To put back</th>
                <th>State</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php($fund = $row['fund'])
                <tr>
                    <td>
                        <span class="erp-cell-strong">{{ $fund->code }}</span>
                        <div class="erp-td-muted">{{ $fund->name }}</div>
                    </td>
                    <td>
                        {{ $fund->custodian?->name ?? '—' }}
                        <div class="erp-td-muted">answerable for the cash</div>
                    </td>
                    <td>
                        <a href="{{ route('cash-bank.book', $fund->account_id) }}">{{ $fund->accountLabel() }}</a>
                        <div class="erp-td-muted">{{ $fund->branch?->name ?? 'All branches' }}</div>
                    </td>
                    <td class="erp-td-num">
                        ৳ {{ number_format((float) $row['balance'], 2) }}
                        @if ((float) $row['balance'] < 0)
                            <div class="erp-td-muted">spent past its level</div>
                        @endif
                    </td>
                    <td class="erp-td-num">৳ {{ number_format((float) $fund->imprest_amount, 2) }}</td>
                    <td class="erp-td-num">
                        ৳ {{ $row['shortfall'] }}
                        @if ($fund->isActive() && (float) $row['shortfall'] > 0 && $perm('pettycash.replenish'))
                            <div class="erp-td-muted">
                                <a href="{{ route('cash-bank.petty-cash.replenishments', ['fund' => $fund->id]) }}">Put it back</a>
                            </div>
                        @endif
                    </td>
                    <td>
                        <x-ui.status :value="$fund->isActive() ? 'active' : 'closed'" :label="$fund->isActive() ? 'Open' : 'Closed'" />
                        @if (! $fund->isActive() && $fund->closed_on)
                            <div class="erp-td-muted">closed {{ $fund->closed_on->toDateString() }}</div>
                        @endif
                        @if ($fund->pending_requests_count > 0)
                            <div class="erp-td-muted">{{ $fund->pending_requests_count }} request(s) waiting</div>
                        @endif
                    </td>
                    <td class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('cash-bank.petty-cash.expenses', ['fund' => $fund->id]) }}">
                            Vouchers
                        </a>
                        @if ($mayDeclare)
                            <details class="mt-1">
                                <summary class="erp-td-muted">Re-describe</summary>
                                <form class="mt-2" method="POST" action="{{ route('cash-bank.petty-cash.update', $fund) }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="code" value="{{ $fund->code }}">
                                    <div class="mb-2">
                                        <label class="form-label" for="name-{{ $fund->id }}">Name</label>
                                        <input class="form-control form-control-sm" id="name-{{ $fund->id }}" name="name" value="{{ $fund->name }}" required>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label" for="custodian-{{ $fund->id }}">Custodian</label>
                                        <select class="form-select form-select-sm" id="custodian-{{ $fund->id }}" name="custodian_id" required>
                                            @foreach ($custodians as $custodian)
                                                <option value="{{ $custodian->id }}" @selected($fund->custodian_id === $custodian->id)>{{ $custodian->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label" for="imprest-{{ $fund->id }}">Level</label>
                                        <input class="form-control form-control-sm" id="imprest-{{ $fund->id }}" name="imprest_amount" inputmode="decimal" value="{{ $fund->imprest_amount }}" required>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label" for="description-{{ $fund->id }}">What it is for</label>
                                        <input class="form-control form-control-sm" id="description-{{ $fund->id }}" name="description" value="{{ $fund->description }}">
                                    </div>
                                    <input type="hidden" name="is_active" value="0">
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($fund->isActive())>
                                        <span class="form-check-label">Open</span>
                                    </label>
                                    <button class="btn btn-sm btn-primary mt-2" type="submit">Save</button>
                                </form>
                                @if ($fund->isActive())
                                    <form class="mt-2" method="POST" action="{{ route('cash-bank.petty-cash.close', $fund) }}" data-confirm="Close this float? It is refused while it still holds money or while a request is waiting.">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Close the float</button>
                                    </form>
                                @endif
                            </details>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty
                            title="No float has been declared yet"
                            icon="bi-cash-coin"
                            text="A float is small money kept on hand for the things a bank transfer cannot buy: a rickshaw, a courier, a lock for a door." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    @if ($mayDeclare)
        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Declare a float</h2>
                    <p class="erp-card-sub">
                        This creates the float's own account in the chart of accounts under Current Assets and names the
                        person answerable for what is in it. Nothing is posted — declaring a tin is not spending money.
                    </p>
                </div>
            </header>
            <div>
                @if ($custodians->isEmpty() || $branches->isEmpty())
                    <x-ui.empty
                        title="A float needs a custodian"
                        icon="bi-people"
                        text="There is nobody in this company to name as the person answerable for the cash yet. Add the user first, then open the float." />
                @else
                    <form method="POST" action="{{ route('cash-bank.petty-cash.store') }}">
                        @csrf
                        <div class="erp-form-grid">
                            <div class="erp-form-field">
                                <label class="form-label" for="code">Code</label>
                                <input class="form-control" id="code" name="code" value="{{ old('code') }}" maxlength="32" required>
                                @error('code')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="name">Name</label>
                                <input class="form-control" id="name" name="name" value="{{ old('name') }}" maxlength="120" placeholder="Head office tin" required>
                                @error('name')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="custodian_id">Custodian</label>
                                <select class="form-select" id="custodian_id" name="custodian_id" required>
                                    @foreach ($custodians as $custodian)
                                        <option value="{{ $custodian->id }}" @selected(old('custodian_id') == $custodian->id)>{{ $custodian->name }}</option>
                                    @endforeach
                                </select>
                                @error('custodian_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="branch_id">Kept at</label>
                                <select class="form-select" id="branch_id" name="branch_id">
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->name }}{{ $branch->is_default ? ' (head office)' : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="imprest_amount">Level it is meant to hold</label>
                                <input class="form-control" id="imprest_amount" name="imprest_amount" inputmode="decimal" value="{{ old('imprest_amount') }}" required>
                                <small class="form-text">The amount the replenishment screen will offer to put back.</small>
                                @error('imprest_amount')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="account_code">Ledger account code</label>
                                <input class="form-control" id="account_code" name="account_code" value="{{ old('account_code') }}" maxlength="32" placeholder="Filled in for you">
                                <small class="form-text">Leave blank to keep the code derived from the float's own code.</small>
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="opened_on">Opened on</label>
                                <input class="form-control" id="opened_on" name="opened_on" type="date" value="{{ old('opened_on', now()->toDateString()) }}">
                            </div>
                            <div class="erp-form-field erp-form-field-wide">
                                <label class="form-label" for="description">What it is for</label>
                                <input class="form-control" id="description" name="description" value="{{ old('description') }}" maxlength="300" placeholder="Couriers, tea, rickshaws and the small hardware nobody raises a purchase order for">
                            </div>
                        </div>
                        <input type="hidden" name="is_active" value="0">
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', '1') == '1')>
                            <span class="form-check-label">Open it now</span>
                        </label>
                        <div class="mt-2">
                            <button class="btn btn-primary" type="submit">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i> Declare the float
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </section>
    @endif

    @if ($recent->isNotEmpty())
        <x-ui.table-shell class="mt-3" title="Paid out lately" :count="$recent->count().' voucher(s)'">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Voucher</th>
                    <th>Float</th>
                    <th>Payee</th>
                    <th class="erp-th-num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recent as $voucher)
                    <tr>
                        <td>{{ $voucher->occurred_on->toDateString() }}</td>
                        <td>
                            <span class="erp-cell-strong">{{ $voucher->documentNo() ?? '—' }}</span>
                            @if ($voucher->request)
                                <div class="erp-td-muted">approved request #{{ $voucher->request_id }}</div>
                            @endif
                        </td>
                        <td>{{ $voucher->fund?->name }}</td>
                        <td>{{ $voucher->payee ?? '—' }}</td>
                        <td class="erp-td-num">৳ {{ number_format((float) $voucher->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table-shell>
    @endif

    <x-ui.related-pages />
@endsection
