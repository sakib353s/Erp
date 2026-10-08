{{--
    One movement of money: a receipt (direction in) or a payment (direction out).

    The two screens differ only in which side of the entry is the money account
    and who the party is likely to be, so they share one form. The account the
    money moved *through* is asked for first and is never defaulted: the operator
    holding the bank SMS knows which account the money landed in, and a screen
    that guesses is a screen that mis-files one deposit a month.
--}}
@php
    $isReceipt = $direction === 'in';
    $parties = $isReceipt ? $customers : $suppliers;
    $action = $isReceipt ? route('cash-bank.receipts.store') : route('cash-bank.payments.store');
    $dateField = $isReceipt ? 'received_on' : 'paid_on';
@endphp

<form method="POST" action="{{ $action }}">
    @csrf
    <input type="hidden" name="direction" value="{{ $direction }}">

    <div class="erp-form-grid">
        <div class="erp-form-field erp-form-field-wide">
            <label class="form-label" for="money_account_id">
                {{ $isReceipt ? 'Money came into' : 'Money went out of' }}
            </label>
            <select class="form-select @error('money_account_id') is-invalid @enderror" id="money_account_id" name="money_account_id" required>
                <option value="">— pick the cash, bank or wallet account —</option>
                @foreach ($moneyAccounts as $account)
                    <option value="{{ $account->id }}" @selected((int) old('money_account_id') === $account->id)>
                        {{ $account->code }} — {{ $account->name }}
                        @if ($account->bank_name)· {{ $account->bank_name }}@endif
                        @if ($account->wallet_provider)· {{ $account->wallet_provider }}@endif
                    </option>
                @endforeach
            </select>
            @error('money_account_id') <div class="text-danger small">{{ $message }}</div> @enderror
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
            <label class="form-label" for="moved_on">Date</label>
            <input class="form-control @error('moved_on') is-invalid @enderror" type="date"
                   id="moved_on" name="moved_on" value="{{ old('moved_on', now()->toDateString()) }}">
            <div class="form-text">The date the money moved, not the date it is typed in. A closed period refuses it.</div>
            @error('moved_on') <div class="text-danger small">{{ $message }}</div> @enderror
        </div>

        <div class="erp-form-field">
            <label class="form-label" for="counter_account_id">
                {{ $isReceipt ? 'This money is for' : 'This money was spent on' }}
            </label>
            <select class="form-select @error('counter_account_id') is-invalid @enderror" id="counter_account_id" name="counter_account_id" required>
                <option value="">— pick the account on the other side —</option>
                @foreach ($counterAccounts as $option)
                    <option value="{{ $option['id'] }}" @selected((int) old('counter_account_id') === $option['id'])>
                        {{ $option['label'] }}
                    </option>
                @endforeach
            </select>
            <div class="form-text">
                {{ $isReceipt
                    ? 'Income, a customer\'s account, a deposit taken, a loan received — the account the sales or the ledger will show it against.'
                    : 'An expense, a supplier\'s account, an asset bought — the account the cost will appear under.' }}
            </div>
            @error('counter_account_id') <div class="text-danger small">{{ $message }}</div> @enderror
        </div>

        <div class="erp-form-field">
            <label class="form-label" for="party_name">{{ $isReceipt ? 'Received from' : 'Paid to' }}</label>
            <input class="form-control @error('party_name') is-invalid @enderror" type="text"
                   id="party_name" name="party_name" value="{{ old('party_name') }}" maxlength="191"
                   placeholder="{{ $isReceipt ? 'Walk-in customer, rider, bank…' : 'Landlord, courier, supplier…' }}">
            <div class="form-text">Prints on the voucher. Leave it blank when the account below already names the party.</div>
            @error('party_name') <div class="text-danger small">{{ $message }}</div> @enderror
        </div>

        <div class="erp-form-field">
            <label class="form-label" for="{{ $isReceipt ? 'customer_id' : 'supplier_id' }}">
                {{ $isReceipt ? 'Customer on the books' : 'Supplier on the books' }}
            </label>
            <select class="form-select" id="{{ $isReceipt ? 'customer_id' : 'supplier_id' }}"
                    name="{{ $isReceipt ? 'customer_id' : 'supplier_id' }}">
                <option value="">— nobody on the books —</option>
                @foreach ($parties as $party)
                    <option value="{{ $party->id }}" @selected((int) old($isReceipt ? 'customer_id' : 'supplier_id') === $party->id)>
                        {{ $party->name }} ({{ $party->code }})
                    </option>
                @endforeach
            </select>
            <div class="form-text">Naming a party puts the movement on their statement as well as in the books.</div>
        </div>

        <div class="erp-form-field">
            <label class="form-label" for="reference">Reference</label>
            <input class="form-control font-monospace" type="text" id="reference" name="reference"
                   value="{{ old('reference') }}" maxlength="64"
                   placeholder="{{ $isReceipt ? 'Deposit slip no., txn id…' : 'Cheque no., bill no…' }}">
        </div>

        <div class="erp-form-field erp-form-field-wide">
            <label class="form-label" for="narration">What it was</label>
            <input class="form-control" type="text" id="narration" name="narration" value="{{ old('narration') }}"
                   maxlength="500" placeholder="Shown on the voucher and on the ledger line">
        </div>
    </div>

    <div class="erp-note erp-note-info mt-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            <strong class="d-block mb-1">{{ $isReceipt ? 'A receipt here is money the ledger has to see' : 'A payment here is money the ledger has to see' }}</strong>
            Settling one invoice or one bill is done on <em>that document</em> — the invoice screen and the bill screen
            move their own balances, and a settlement recorded in two places is a customer balance nobody can
            reconcile. Use this desk for {{ $isReceipt ? 'walk-in income, deposits, other income, and a customer\'s payment on account' : 'expenses, advances, rent, and a supplier\'s payment that no single bill owns' }}.
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-3">
        <button class="btn btn-primary" type="submit">
            <i class="bi {{ $isReceipt ? 'bi-box-arrow-in-down' : 'bi-box-arrow-up' }}" aria-hidden="true"></i>
            {{ $isReceipt ? 'Record the receipt' : 'Record the payment' }}
        </button>
    </div>
</form>
