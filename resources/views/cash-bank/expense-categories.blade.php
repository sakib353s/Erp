@extends('layouts.app')

@section('page_title', 'Expense categories')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Expenses"
        title="What an expense books to"
        subtitle="A category is a general-ledger account with a name a human uses. The person recording an expense picks the name; the ledger gets the account code. Nothing in this module guesses an account, and this is the screen that decides which accounts exist to be picked."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.expenses') }}">
                <i class="bi bi-receipt" aria-hidden="true"></i> The register
            </a>
            @if ($perm('expenses.create'))
                <a class="btn btn-primary" href="{{ route('cash-bank.expenses.create') }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Add expense
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

    @error('category')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    @if ($expenseAccounts->isEmpty())
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">No expense account exists to book to</strong>
                A category has to point at a real ledger account of this company — a postable account under Expenses. Add one on the chart of accounts first; nothing here invents an account for you.
            </div>
        </div>
    @endif

    @if ($categories->isEmpty())
        <div class="erp-card p-3">
            <x-ui.empty
                title="No category is configured yet"
                icon="bi-diagram-3"
                text="Until at least one category exists, no expense can be recorded — there would be no account to book it to."
                :action="$expenseAccounts->isEmpty() ? null : 'Add the first category below'"
                :href="$expenseAccounts->isEmpty() ? null : '#add-category'" />
        </div>
    @else
        <x-ui.table-shell title="Categories" :count="$categories->count().' category(ies)'">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Books to</th>
                    <th class="erp-th-num">Expenses</th>
                    <th>Order</th>
                    <th>On</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($categories as $category)
                    <tr>
                        <td>
                            <form id="category-{{ $category->id }}" method="POST" action="{{ route('cash-bank.expense-categories.update', ['category' => $category->id]) }}">
                                @csrf
                                @method('PUT')
                                {{-- The code is this category's identity in every report ever
                                     printed from it, so the row saves the name, the account
                                     and the switch — never the identity. --}}
                                <input type="hidden" name="code" value="{{ $category->code }}">
                                {{-- A switch that is off sends nothing at all, which would read
                                     as "leave it alone" — so the row says 0 in words. --}}
                                <input type="hidden" name="is_active" value="0">
                            </form>
                            <span class="erp-cell-strong font-monospace">{{ $category->code }}</span>
                        </td>
                        <td>
                            <input class="form-control form-control-sm" type="text" name="name" form="category-{{ $category->id }}"
                                   value="{{ $category->name }}" maxlength="120" required aria-label="Name of {{ $category->code }}">
                            @if ($category->description)
                                <span class="d-block erp-td-muted mt-1">{{ $category->description }}</span>
                            @endif
                        </td>
                        <td>
                            <select class="form-select form-select-sm" name="account_id" form="category-{{ $category->id }}" required aria-label="Account for {{ $category->code }}">
                                @foreach ($expenseAccounts as $account)
                                    <option value="{{ $account->id }}" @selected($category->account_id === $account->id)>
                                        {{ $account->code }} — {{ $account->name }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td class="erp-td-num">
                            @if ($category->expenses_count > 0)
                                <a href="{{ route('cash-bank.expenses', ['category' => $category->id]) }}">{{ number_format($category->expenses_count) }}</a>
                            @else
                                <span class="erp-td-muted">0</span>
                            @endif
                        </td>
                        <td>
                            <input class="form-control form-control-sm erp-num" type="number" min="0" max="9999" step="1"
                                   name="sort_order" form="category-{{ $category->id }}" value="{{ $category->sort_order }}"
                                   aria-label="Sort order for {{ $category->code }}">
                        </td>
                        <td>
                            <span class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" value="1" name="is_active"
                                       form="category-{{ $category->id }}" @checked($category->isActive())
                                       aria-label="Whether {{ $category->code }} is active">
                            </span>
                        </td>
                        <td class="erp-td-actions">
                            <button class="btn btn-sm btn-outline-secondary" type="submit" form="category-{{ $category->id }}">
                                <i class="bi bi-check2" aria-hidden="true"></i> Save
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table-shell>

        <div class="erp-filter-note mt-2">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <span>Changing an account here changes where expenses recorded <em>from now on</em> are booked. Anything already posted stays where it was posted, because it happened.</span>
        </div>
    @endif

    <section class="erp-card mt-3" id="add-category">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Add a category</h2>
                <p class="erp-card-sub">Point it at a postable expense account of this company — a group account is refused, because nothing can be posted to a group.</p>
            </div>
        </header>

        <form method="POST" action="{{ route('cash-bank.expense-categories.store') }}">
            @csrf
            <input type="hidden" name="is_active" value="0">
            <div class="erp-form-grid">
                <div class="erp-form-field">
                    <label class="form-label" for="code">Code</label>
                    <input class="form-control font-monospace @error('code') is-invalid @enderror" type="text"
                           id="code" name="code" value="{{ old('code') }}" maxlength="32" required placeholder="OFFICE-RENT">
                    <div class="form-text">Capitals, digits and . _ - — it appears in reports and in file names.</div>
                    @error('code') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control @error('name') is-invalid @enderror" type="text"
                           id="name" name="name" value="{{ old('name') }}" maxlength="120" required placeholder="Office rent">
                    @error('name') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field">
                    <label class="form-label" for="account_id">Books to</label>
                    <select class="form-select @error('account_id') is-invalid @enderror" id="account_id" name="account_id" required>
                        <option value="">— choose the ledger account —</option>
                        @foreach ($expenseAccounts as $account)
                            <option value="{{ $account->id }}" @selected((string) old('account_id') === (string) $account->id)>
                                {{ $account->code }} — {{ $account->name }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">Only this company's own postable expense accounts are listed.</div>
                    @error('account_id') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field">
                    <label class="form-label" for="sort_order">Order</label>
                    <input class="form-control erp-num @error('sort_order') is-invalid @enderror" type="number" min="0" max="9999" step="1"
                           id="sort_order" name="sort_order" value="{{ old('sort_order', 0) }}">
                    <div class="form-text">Where it sits in the picker. Low numbers first.</div>
                    @error('sort_order') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field erp-form-field-wide">
                    <label class="form-label" for="description">What belongs here</label>
                    <input class="form-control @error('description') is-invalid @enderror" type="text"
                           id="description" name="description" value="{{ old('description') }}" maxlength="300"
                           placeholder="Monthly rent for every premises; do not put utility bills here">
                    @error('description') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="erp-form-field">
                    <span class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" value="1" id="is_active" name="is_active" @checked(old('is_active', true))>
                        <label class="form-check-label" for="is_active">Available to record against</label>
                    </span>
                    <div class="form-text">Switching a category off keeps every expense already filed under it; it only stops new ones.</div>
                </div>
            </div>

            <div class="erp-card-tight d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 pb-3">
                <span class="erp-td-muted">A category is never deleted — last year's expenses still have to be able to name it.</span>
                <button class="btn btn-primary" type="submit" @disabled($expenseAccounts->isEmpty())>
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Add category
                </button>
            </div>
        </form>
    </section>
@endsection
