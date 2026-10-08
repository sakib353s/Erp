@extends('layouts.app')

@section('page_title', 'Cash transfer')

@section('content')
    <x-ui.page-header
        eyebrow="Cash & bank · Cash transfer"
        title="Move money between accounts"
        subtitle="Cash into the bank at the end of the day, bank to bank, wallet to bank — one document, two journal lines, posted together. If the second line cannot be posted the first is not either, because a transfer that leaves one side of the books standing is not half a transfer, it is a hole."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.index') }}">
                <i class="bi bi-wallet2" aria-hidden="true"></i> Where the money is
            </a>
            @if ($perm('bank.accounts'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.accounts') }}">
                    <i class="bi bi-bank" aria-hidden="true"></i> Money accounts
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

    @if ($moneyAccounts->count() < 2)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-signpost-split" aria-hidden="true"></i>
            <div>
                A transfer needs two accounts and only {{ $moneyAccounts->count() }} is declared. Declare the second
                one — the bank the cash is carried to, or the wallet it is cashed out of.
            </div>
        </div>
    @endif

    <section class="erp-card">
        <header class="erp-card-head">
            <h2 class="erp-card-title">Record a transfer</h2>
            <div class="erp-card-actions">
                <span class="erp-chip erp-chip-outline">numbering series CT</span>
            </div>
        </header>

        <form method="POST" action="{{ route('cash-bank.transfer.store') }}">
            @csrf
            <div class="erp-form-grid">
                <div class="erp-form-field">
                    <label class="form-label" for="from_account_id">From</label>
                    <select class="form-select @error('from_account_id') is-invalid @enderror" id="from_account_id" name="from_account_id" required>
                        <option value="">— the account the money left —</option>
                        @foreach ($moneyAccounts as $account)
                            <option value="{{ $account->id }}" @selected((int) old('from_account_id') === $account->id)>
                                {{ $account->code }} — {{ $account->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('from_account_id') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field">
                    <label class="form-label" for="to_account_id">To</label>
                    <select class="form-select @error('to_account_id') is-invalid @enderror" id="to_account_id" name="to_account_id" required>
                        <option value="">— the account the money landed in —</option>
                        @foreach ($moneyAccounts as $account)
                            <option value="{{ $account->id }}" @selected((int) old('to_account_id') === $account->id)>
                                {{ $account->code }} — {{ $account->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('to_account_id') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field">
                    <label class="form-label" for="amount">Amount</label>
                    <div class="erp-input-group">
                        <i class="bi bi-currency-exchange" aria-hidden="true"></i>
                        <input class="form-control erp-num @error('amount') is-invalid @enderror" type="number" step="0.01" min="0.01"
                               id="amount" name="amount" value="{{ old('amount') }}" required>
                    </div>
                    @error('amount') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field">
                    <label class="form-label" for="transferred_on">Date</label>
                    <input class="form-control @error('transferred_on') is-invalid @enderror" type="date"
                           id="transferred_on" name="transferred_on" value="{{ old('transferred_on', now()->toDateString()) }}">
                    @error('transferred_on') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field">
                    <label class="form-label" for="reference">Reference</label>
                    <input class="form-control font-monospace" type="text" id="reference" name="reference"
                           value="{{ old('reference') }}" maxlength="64" placeholder="Pay-in slip no., cheque no…">
                </div>

                <div class="erp-form-field erp-form-field-wide">
                    <label class="form-label" for="narration">Why the money moved</label>
                    <input class="form-control" type="text" id="narration" name="narration" value="{{ old('narration') }}"
                           maxlength="500" placeholder="End-of-day banking, cash for the counter…">
                </div>
            </div>

            <div class="erp-note erp-note-info mt-3">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <div>
                    A transfer is not a receipt and not a payment: nothing is earned and nothing is spent, only the
                    account the money sits in changes. Moving money to somebody else is a payment — record it there, so
                    the cost lands under an expense.
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-arrow-left-right" aria-hidden="true"></i> Post the transfer
                </button>
            </div>
        </form>
    </section>

    <x-ui.table-shell :title="'Transfers'" :count="$transfers->count().' shown'" class="mt-3">
        <thead>
            <tr>
                <th>Transfer</th>
                <th>Date</th>
                <th>From</th>
                <th>To</th>
                <th class="erp-th-num">Amount</th>
                <th>By</th>
                <th>Entry</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($transfers as $transfer)
                <tr>
                    <td data-label="Transfer">
                        <span class="erp-cell-strong font-monospace">{{ $transfer->transfer_no }}</span>
                        @if ($transfer->reference)
                            <span class="d-block erp-td-muted font-monospace">{{ $transfer->reference }}</span>
                        @endif
                    </td>
                    <td data-label="Date" class="erp-td-muted">{{ $transfer->transferred_on?->format('d M Y') }}</td>
                    <td data-label="From">{{ $transfer->fromAccount?->name ?? '—' }}</td>
                    <td data-label="To">{{ $transfer->toAccount?->name ?? '—' }}</td>
                    <td data-label="Amount" class="erp-td-num">
                        <span class="erp-money-flat">{{ number_format((float) $transfer->amount, 2) }}</span>
                    </td>
                    <td data-label="By" class="erp-td-muted">{{ $transfer->creator?->name ?? '—' }}</td>
                    <td data-label="Entry" class="erp-td-muted font-monospace">
                        @if ($transfer->journalEntry && $perm('accounting.journals.view'))
                            <a href="{{ route('accounting.journals.show', ['journal' => $transfer->journalEntry->id]) }}">
                                {{ $transfer->journalEntry->entry_no }}
                            </a>
                        @else
                            {{ $transfer->journalEntry?->entry_no ?? '—' }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty icon="bi-arrow-left-right" title="No transfer posted yet"
                                    text="Money moved between the company's own accounts appears here with both ends named, in the order they were posted." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
