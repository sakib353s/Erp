@extends('layouts.app')

@section('page_title', 'Add expense')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Expenses"
        title="Record an expense"
        subtitle="Two things have to be true before this can post: the category must be a real ledger account, and the money must have either left an account of this company or be owed to somebody by name."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.expenses') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> The register
            </a>
            @if ($perm('expenses.categories'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.expense-categories') }}">
                    <i class="bi bi-diagram-3" aria-hidden="true"></i> Categories
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @error('expense')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    @if ($categories->isEmpty())
        <div class="erp-card p-3">
            <x-ui.empty
                title="No expense category is configured yet"
                icon="bi-diagram-3"
                text="A category is the ledger account an expense is booked to. Without one there is nowhere to put this money, so nothing is guessed on your behalf."
                :action="$perm('expenses.categories') ? 'Configure categories' : null"
                :href="$perm('expenses.categories') ? route('cash-bank.expense-categories') : null" />
        </div>
    @else
        <form method="POST" action="{{ route('cash-bank.expenses.store') }}" enctype="multipart/form-data">
            @csrf

            <div class="erp-split">
                <section class="erp-card">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">The expense</h2>
                    </header>

                    <div class="erp-form-grid">
                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="category_id">Category</label>
                            <select class="form-select @error('category_id') is-invalid @enderror" id="category_id" name="category_id" required>
                                <option value="">— choose what this money was for —</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}"
                                            @selected((string) old('category_id', $preset) === (string) $category->id)>
                                        {{ $category->name }} — books to {{ $category->accountLabel() }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">The category is the ledger account. Nothing here guesses which account an expense belongs on.</div>
                            @error('category_id') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>

                        <div class="erp-form-field">
                            <label class="form-label" for="expense_date">Date</label>
                            <input class="form-control @error('expense_date') is-invalid @enderror" type="date"
                                   id="expense_date" name="expense_date" value="{{ old('expense_date', now()->toDateString()) }}" required>
                            <div class="form-text">The day the money moved, or the day the bill is for.</div>
                            @error('expense_date') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>

                        <div class="erp-form-field">
                            <label class="form-label" for="payee">Paid to</label>
                            <input class="form-control @error('payee') is-invalid @enderror" type="text"
                                   id="payee" name="payee" value="{{ old('payee') }}" maxlength="160" required
                                   placeholder="Dhaka WASA, or the name on the receipt">
                            @error('payee') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>

                        <div class="erp-form-field">
                            <label class="form-label" for="amount">Amount</label>
                            <div class="erp-input-group">
                                <span class="input-group-text">৳</span>
                                <input class="form-control @error('amount') is-invalid @enderror erp-num" type="number" step="0.01" min="0.01"
                                       id="amount" name="amount" value="{{ old('amount') }}" required>
                            </div>
                            @error('amount') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>

                        <div class="erp-form-field">
                            <label class="form-label" for="currency">Currency</label>
                            <input class="form-control @error('currency') is-invalid @enderror font-monospace" type="text"
                                   id="currency" name="currency" value="{{ old('currency', 'BDT') }}" maxlength="3">
                            @error('currency') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>

                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="narration">What it was</label>
                            <input class="form-control @error('narration') is-invalid @enderror" type="text"
                                   id="narration" name="narration" value="{{ old('narration') }}" maxlength="500"
                                   placeholder="March water bill for the Uttara warehouse">
                            <div class="form-text">Kept with the posting — a receipt with a line of context is worth ten without one.</div>
                            @error('narration') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </section>

                <aside>
                    <div class="erp-card">
                        <header class="erp-card-head">
                            <h2 class="erp-card-title">Where the money went</h2>
                        </header>

                        <div class="erp-form-grid">
                            <div class="erp-form-field erp-form-field-wide">
                                <label class="form-label" for="settled_with">Paid, or owed?</label>
                                <select class="form-select @error('settled_with') is-invalid @enderror" id="settled_with" name="settled_with" required>
                                    @foreach ($settledWith as $value => $label)
                                        <option value="{{ $value }}" @selected(old('settled_with', 'money') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">Paid: the money left one of the accounts below. Owed: it has not, and the books carry a liability instead.</div>
                                @error('settled_with') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>

                            <div class="erp-form-field erp-form-field-wide">
                                <label class="form-label" for="money_account_id">Paid from</label>
                                <select class="form-select @error('money_account_id') is-invalid @enderror" id="money_account_id" name="money_account_id">
                                    <option value="">— required when the money has been paid —</option>
                                    @foreach ($moneyAccounts as $account)
                                        <option value="{{ $account->id }}" @selected((string) old('money_account_id') === (string) $account->id)>
                                            {{ $account->code }} — {{ $account->name }}@if ($account->instrument) ({{ $account->instrument }})@endif
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text">Cash, a bank account or a mobile wallet — the account the money actually left.</div>
                                @error('money_account_id') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>

                            <div class="erp-form-field erp-form-field-wide">
                                <label class="form-label" for="supplier_id">Owed to (optional)</label>
                                <select class="form-select @error('supplier_id') is-invalid @enderror" id="supplier_id" name="supplier_id">
                                    <option value="">— no supplier on the books —</option>
                                    @foreach ($suppliers as $supplier)
                                        <option value="{{ $supplier->id }}" @selected((string) old('supplier_id') === (string) $supplier->id)>{{ $supplier->name }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">Naming the supplier puts the liability on their account, where the ageing report can find it.</div>
                                @error('supplier_id') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>

                            <div class="erp-form-field erp-form-field-wide">
                                <label class="form-label" for="receipt">The receipt, if there is one</label>
                                <input class="form-control @error('receipt') is-invalid @enderror" type="file"
                                       id="receipt" name="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf">
                                <div class="form-text">Optional on purpose: a rickshaw fare has no paper. A photograph or a PDF, up to 8 MB.</div>
                                @error('receipt') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>

                    <div class="erp-card mt-3">
                        <header class="erp-card-head"><h2 class="erp-card-title">How this will post</h2></header>
                        <div class="p-3">
                            <div class="erp-dl erp-dl-tight">
                                <dt>Debit</dt>
                                <dd>the category's account — nothing else</dd>
                                <dt>Credit</dt>
                                <dd>the account it was paid from, or payables if it is owed</dd>
                            </div>

                            @if ((float) $threshold > 0)
                                <div class="erp-note erp-note-info mt-3">
                                    <i class="bi bi-sliders" aria-hidden="true"></i>
                                    <div>An expense of <strong>৳ {{ number_format((float) $threshold, 2) }}</strong> or more waits for somebody else's approval and does not touch the books while it waits.</div>
                                </div>
                            @else
                                <div class="erp-note erp-note-info mt-3">
                                    <i class="bi bi-lightning-charge" aria-hidden="true"></i>
                                    <div>No approval limit is configured, so this posts the moment it is recorded. The limit is a setting: an amount at or above it makes the desk wait for a signature.</div>
                                </div>
                            @endif

                            <p class="erp-td-muted mt-3 mb-0">Recording an expense twice for the same money is the one error this desk cannot detect for you — the category and the payee are what you will have to recognise it by.</p>
                        </div>
                    </div>
                </aside>
            </div>

            <div class="erp-card erp-card-tight mt-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="erp-td-muted">Nothing is written until this is submitted. If it needs approval, the number is allocated and the ledger is left alone.</span>
                    <span class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="{{ route('cash-bank.expenses') }}">Cancel</a>
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-check2" aria-hidden="true"></i> Record the expense
                        </button>
                    </span>
                </div>
            </div>
        </form>
    @endif
@endsection
