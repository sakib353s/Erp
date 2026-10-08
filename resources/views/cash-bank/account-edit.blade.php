@extends('layouts.app')

@section('page_title', 'Edit '.$position['account']->name)

@section('content')
    <x-ui.page-header
        eyebrow="Cash & bank · Money accounts"
        :title="$position['account']->name"
        subtitle="What can be corrected here is how the account describes itself — its name, the bank or provider it belongs to, its number, and a note. What cannot be corrected is what it is: the code and the instrument are how every report so far has referred to it, and changing either would rewrite history rather than fix a typo."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.accounts') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All money accounts
            </a>
            @if ($perm('bank.view'))
                <a class="btn btn-outline-secondary"
                   href="{{ route('cash-bank.book', ['account' => $position['account']->id]) }}">
                    <i class="bi bi-journal-text" aria-hidden="true"></i> Its book
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    @error('cash_bank')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Balance" :value="'৳ '.number_format((float) $position['balance'], 2)" icon="bi-wallet2"
                  hint="From the posted journal lines" />
        <x-ui.kpi label="Ledger lines" :value="number_format($movements)" icon="bi-list-columns"
                  hint="Movement through this account" />
        <x-ui.kpi label="Kind" :value="$position['label']" icon="bi-bank"
                  hint="Fixed for the life of the account" />
        <x-ui.kpi label="State" :value="$position['account']->is_active ? 'Open' : 'Closed'" icon="bi-shield-check"
                  hint="Whether money may still move through it" />
    </div>

    <div class="erp-split">
        <section class="erp-card">
            <header class="erp-card-head">
                <h2 class="erp-card-title">How the account describes itself</h2>
            </header>

            <form method="POST" action="{{ route('cash-bank.accounts.update', ['account' => $position['account']->id]) }}">
                @csrf
                @method('PUT')

                <div class="erp-form-grid">
                    <div class="erp-form-field">
                        <label class="form-label" for="code">Code</label>
                        <input class="form-control font-monospace" type="text" id="code"
                               value="{{ $position['account']->code }}" readonly disabled>
                        <div class="form-text">Fixed: the ledger, the reports and the audit trail refer to it by this.</div>
                    </div>

                    <div class="erp-form-field">
                        <label class="form-label" for="instrument">Type</label>
                        <input class="form-control" type="text" id="instrument" value="{{ $position['label'] }}" readonly disabled>
                        <div class="form-text">Fixed: changing it would regroup every report ever printed.</div>
                    </div>

                    <div class="erp-form-field erp-form-field-wide">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control @error('name') is-invalid @enderror" type="text"
                               id="name" name="name" value="{{ old('name', $position['account']->name) }}" maxlength="191" required>
                        @error('name') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    <div class="erp-form-field">
                        <label class="form-label" for="bank_name">Bank</label>
                        <input class="form-control @error('bank_name') is-invalid @enderror" type="text"
                               id="bank_name" name="bank_name" maxlength="80"
                               value="{{ old('bank_name', $position['account']->bank_name) }}"
                               @if ($position['instrument'] !== 'bank') placeholder="—" @endif>
                        @error('bank_name') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    <div class="erp-form-field">
                        <label class="form-label" for="account_number">Account / wallet number</label>
                        <input class="form-control font-monospace @error('account_number') is-invalid @enderror" type="text"
                               id="account_number" name="account_number" maxlength="48"
                               value="{{ old('account_number', $position['account']->account_number) }}">
                        @error('account_number') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    @if ($position['instrument'] === 'wallet')
                        <div class="erp-form-field">
                            <label class="form-label" for="wallet_provider">Mobile provider</label>
                            <select class="form-select @error('wallet_provider') is-invalid @enderror" id="wallet_provider" name="wallet_provider">
                                @foreach ($providers as $value => $label)
                                    <option value="{{ $value }}" @selected(old('wallet_provider', $position['account']->wallet_provider) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('wallet_provider') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                    @endif

                    <div class="erp-form-field erp-form-field-wide">
                        <label class="form-label" for="description">Note</label>
                        <input class="form-control @error('description') is-invalid @enderror" type="text"
                               id="description" name="description" maxlength="500"
                               value="{{ old('description', $position['account']->description) }}">
                        @error('description') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check2" aria-hidden="true"></i> Save
                    </button>
                    <a class="btn btn-outline-secondary" href="{{ route('cash-bank.accounts') }}">Cancel</a>
                </div>
            </form>
        </section>

        <aside>
            <div class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Closing this account</h2>
                </header>

                @if (! $position['account']->is_active)
                    <p class="text-muted mb-0">
                        This account is already closed to new movement. Its history stays in the books and on every
                        report it has ever appeared in.
                    </p>
                @elseif ((float) $position['balance'] != 0.0)
                    <p class="text-muted mb-2">
                        An account still holding money cannot be closed. Move
                        <strong>৳ {{ number_format((float) $position['balance'], 2) }}</strong> out of
                        {{ $position['account']->name }} first — deactivating an account that still holds a balance takes
                        it off this desk while leaving the money in the ledger, and that is the one state from which
                        nobody can say where the cash went.
                    </p>
                    @if ($perm('cash.transfers'))
                        <a class="btn btn-outline-secondary" href="{{ route('cash-bank.transfer') }}">
                            <i class="bi bi-arrow-left-right" aria-hidden="true"></i> Move the money out
                        </a>
                    @endif
                @else
                    <form method="POST" action="{{ route('cash-bank.accounts.close', ['account' => $position['account']->id]) }}"
                          data-confirm="Close {{ $position['account']->name }} to new movement? Its history stays.">
                        @csrf
                        <p class="text-muted">
                            The balance is zero, so nothing is stranded by closing it. New receipts, payments and
                            transfers will refuse this account from then on.
                        </p>
                        <button class="btn btn-outline-danger" type="submit">
                            <i class="bi bi-lock" aria-hidden="true"></i> Close the account
                        </button>
                    </form>
                @endif
            </div>
        </aside>
    </div>

    <x-ui.table-shell :title="'Most recent lines on this account'" :count="min(count($recent), 12).' line(s)'" class="mt-3">
        <thead>
            <tr>
                <th>Date</th>
                <th>Entry</th>
                <th>What it was</th>
                <th class="erp-th-num">In</th>
                <th class="erp-th-num">Out</th>
                <th class="erp-th-num">Balance</th>
            </tr>
        </thead>
        <tbody>
            @foreach (array_slice(array_reverse($recent), 0, 12) as $row)
                <tr>
                    <td data-label="Date" class="erp-td-muted">{{ $row['entry_date'] ?? '—' }}</td>
                    <td data-label="Entry" class="font-monospace">{{ $row['entry_no'] ?? 'Opening' }}</td>
                    <td data-label="What it was">{{ $row['description'] }}</td>
                    <td data-label="In" class="erp-td-num erp-money-in">
                        {{ ($row['debit'] ?? '0.0000') + 0 > 0 ? number_format((float) $row['debit'], 2) : '—' }}
                    </td>
                    <td data-label="Out" class="erp-td-num erp-money-out">
                        {{ ($row['credit'] ?? '0.0000') + 0 > 0 ? number_format((float) $row['credit'], 2) : '—' }}
                    </td>
                    <td data-label="Balance" class="erp-td-num erp-money-flat">{{ number_format((float) $row['running_balance'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table-shell>
@endsection
