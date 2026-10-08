@extends('layouts.app')

@section('page_title', 'Money accounts')

@section('content')
    <x-ui.page-header
        eyebrow="Cash & bank · Bank accounts & mobile banking"
        title="Every account money moves through"
        subtitle="Declaring a money account opens a real leaf in the chart of accounts under Current Assets — the registry and the ledger are the same list, so no balance here is a number typed on a screen. A bank account says which bank holds it; a wallet says which provider it is. Neither can be re-described into the other once money has moved."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.index') }}">
                <i class="bi bi-wallet2" aria-hidden="true"></i> Where the money is
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    @error('instrument')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Accounts declared" :value="count($positions)" icon="bi-bank"
                  hint="Cash drawers, bank accounts and wallets" />
        <x-ui.kpi label="Cash" :value="'৳ '.number_format((float) $totals['cash'], 2)" icon="bi-cash-stack"
                  hint="Held in tills" />
        <x-ui.kpi label="Bank" :value="'৳ '.number_format((float) $totals['bank'], 2)" icon="bi-bank2"
                  hint="Held at banks" />
        <x-ui.kpi label="Wallets" :value="'৳ '.number_format((float) $totals['wallet'], 2)" icon="bi-phone"
                  hint="Held with mobile providers" />
    </div>

    <x-ui.table-shell :title="'Money accounts'" :count="count($positions).' account(s)'">
        <thead>
            <tr>
                <th>Account</th>
                <th>Kind</th>
                <th>Where</th>
                <th class="erp-th-num">Balance</th>
                <th>State</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($positions as $row)
                @php($account = $row['account'])
                <tr>
                    <td data-label="Account">
                        <span class="erp-cell-strong">{{ $account->name }}</span>
                        <span class="d-block erp-td-muted font-monospace">{{ $account->code }}</span>
                    </td>
                    <td data-label="Kind">{{ $instruments[$row['instrument']] ?? '—' }}</td>
                    <td data-label="Where" class="erp-td-muted">
                        @if ($account->bank_name)
                            {{ $account->bank_name }}
                            <span class="d-block font-monospace">{{ $account->account_number ?: 'number not recorded' }}</span>
                        @elseif ($account->wallet_provider)
                            {{ $providers[$account->wallet_provider] ?? $account->wallet_provider }}
                            <span class="d-block font-monospace">{{ $account->account_number ?: 'number not recorded' }}</span>
                        @else
                            the drawer in the office
                        @endif
                    </td>
                    <td data-label="Balance" class="erp-td-num">
                        <span class="erp-cell-strong @if ($row['is_negative']) erp-money-out @endif">
                            ৳ {{ number_format((float) $row['balance'], 2) }}
                        </span>
                    </td>
                    <td data-label="State">
                        <x-ui.status :value="$account->is_active ? 'active' : 'inactive'"
                                     :label="$account->is_active ? 'Open to movement' : 'Closed'" />
                        <span class="d-block erp-td-muted">{{ number_format($row['movements']) }} ledger line(s)</span>
                    </td>
                    <td data-label="" class="erp-td-actions">
                        <div class="d-flex flex-wrap gap-1 justify-content-end">
                            @if ($perm('bank.view'))
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="{{ route('cash-bank.book', ['account' => $account->id]) }}">
                                    <i class="bi bi-journal-text" aria-hidden="true"></i> Book
                                </a>
                            @endif
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('cash-bank.accounts.edit', ['account' => $account->id]) }}">
                                <i class="bi bi-pencil" aria-hidden="true"></i> Rename
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty icon="bi-bank" title="Nothing declared yet"
                                    text="The chart ships with one cash account and one bank account. Real operations need the accounts money actually moves through — add the first one below." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <h2 class="erp-card-title">Declare a money account</h2>
            <div class="erp-card-actions">
                <span class="erp-chip erp-chip-outline">opens a leaf under Current Assets</span>
            </div>
        </header>

        <form method="POST" action="{{ route('cash-bank.accounts.store') }}">
            @csrf
            <div class="erp-form-grid">
                <div class="erp-form-field">
                    <label class="form-label" for="instrument">Type</label>
                    <select class="form-select @error('instrument') is-invalid @enderror" id="instrument" name="instrument" required>
                        @foreach ($instruments as $value => $label)
                            @continue($value === 'wallet' && ! $canManageWallets)
                            <option value="{{ $value }}" @selected(old('instrument') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Cash, a bank account, or a mobile wallet — each posts differently.</div>
                    @error('instrument') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field">
                    <label class="form-label" for="code">Account code</label>
                    <input class="form-control font-monospace @error('code') is-invalid @enderror" type="text"
                           id="code" name="code" value="{{ old('code') }}" maxlength="32" placeholder="1121" required>
                    <div class="form-text">The code the ledger and the reports will use, never changed afterwards.</div>
                    @error('code') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field erp-form-field-wide">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control @error('name') is-invalid @enderror" type="text"
                           id="name" name="name" value="{{ old('name') }}" maxlength="191"
                           placeholder="Islami Bank — current account (Gulshan branch)" required>
                    @error('name') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field">
                    <label class="form-label" for="bank_name">Bank</label>
                    <input class="form-control @error('bank_name') is-invalid @enderror" type="text"
                           id="bank_name" name="bank_name" value="{{ old('bank_name') }}" maxlength="80"
                           placeholder="Islami Bank Bangladesh">
                    <div class="form-text">Required for a bank account: without it this is a ledger entry, not a bank.</div>
                    @error('bank_name') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field">
                    <label class="form-label" for="account_number">Account / wallet number</label>
                    <input class="form-control font-monospace @error('account_number') is-invalid @enderror" type="text"
                           id="account_number" name="account_number" value="{{ old('account_number') }}" maxlength="48"
                           placeholder="2050 4471 8830 116">
                    @error('account_number') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field">
                    <label class="form-label" for="wallet_provider">Mobile provider</label>
                    <select class="form-select @error('wallet_provider') is-invalid @enderror" id="wallet_provider" name="wallet_provider">
                        <option value="">— not a wallet —</option>
                        @foreach ($providers as $value => $label)
                            <option value="{{ $value }}" @selected(old('wallet_provider') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Required for a wallet. Reconciliation is manual until a provider API is configured — nothing here pretends to have one.</div>
                    @error('wallet_provider') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field">
                    <label class="form-label" for="currency">Currency</label>
                    <input class="form-control @error('currency') is-invalid @enderror" type="text"
                           id="currency" name="currency" value="{{ old('currency', 'BDT') }}" maxlength="3">
                    @error('currency') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field erp-form-field-wide">
                    <label class="form-label" for="description">Note</label>
                    <input class="form-control @error('description') is-invalid @enderror" type="text"
                           id="description" name="description" value="{{ old('description') }}" maxlength="500"
                           placeholder="Who signs on it, what it is used for…">
                    @error('description') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
            </div>

            @unless ($canManageWallets)
                <div class="erp-note erp-note-warn mt-3">
                    <i class="bi bi-shield-lock" aria-hidden="true"></i>
                    <div>
                        You can open cash and bank accounts. Opening a <strong>mobile wallet</strong> needs the wallet
                        permission — money leaves a wallet through a different door than it leaves a bank.
                    </div>
                </div>
            @endunless

            <div class="d-flex flex-wrap gap-2 mt-3">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Declare the account
                </button>
            </div>
        </form>
    </section>

    <div class="erp-note erp-note-info mt-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            An account can be renamed and re-described at any time, and closed once its balance is zero. Its
            <strong>code</strong> and its <strong>type</strong> cannot change: a bank account that becomes a cash account
            overnight does not re-describe yesterday's postings, it makes every report grouped by type quietly wrong.
            Open a new account instead and move the money.
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
