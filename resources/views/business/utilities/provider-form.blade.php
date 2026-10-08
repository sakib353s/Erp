@php
    /* §12-15 — one provider, added or edited. */
    $editing = $provider !== null;
@endphp

<x-ui.page-header
    eyebrow="Business Management · Utility Bills · Providers · {{ $editing ? 'Edit' : 'Add' }}"
    title="{{ $editing ? $provider->name : 'Add a provider' }}"
    subtitle="{{ $editing
        ? 'Every field is editable, including the account: a bill that has been posted keeps its entry, and moving a provider to a different expense line changes where future bills land, not where past ones did.'
        : 'The account is the point of this registry. Leave it blank and the family decides — 5230 utilities for power, water, gas and connectivity, 5220 rent for the landlord — or point it somewhere deliberate.' }}"
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('business.utilities.providers') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> The registry
        </a>
    </x-slot:actions>
</x-ui.page-header>

<form method="POST"
      action="{{ $editing ? route('business.utilities.providers.update', $provider) : route('business.utilities.providers.store') }}"
      data-confirm="{{ $editing ? 'Save these changes to '.$provider->name.'?' : 'Add this provider to the registry?' }}">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    <div class="erp-card mb-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">The counterparty</h2>
                <p class="erp-card-sub">A provider the desk cannot identify — no consumer number, no premises — is a bill nobody can query when the figure looks wrong.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-2">
                <label class="form-label" for="code">Code</label>
                <input class="form-control @error('code') is-invalid @enderror" type="text" maxlength="40"
                       id="code" name="code" value="{{ old('code', $editing ? $provider->code : '') }}" placeholder="DESCO" required>
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-5">
                <label class="form-label" for="name">Name</label>
                <input class="form-control @error('name') is-invalid @enderror" type="text" maxlength="160"
                       id="name" name="name" value="{{ old('name', $editing ? $provider->name : '') }}"
                       placeholder="DESCO — Dhaka Electric Supply Company" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label class="form-label" for="family">Shelf</label>
                <select class="form-select @error('family') is-invalid @enderror" id="family" name="family" required>
                    @foreach ($families as $key => $tile)
                        <option value="{{ $key }}" @selected(old('family', $editing ? $provider->family : null) === $key)>{{ $tile['label'] }}</option>
                    @endforeach
                </select>
                @error('family')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label class="form-label" for="expense_account_id">Bills post to</label>
                <select class="form-select @error('expense_account_id') is-invalid @enderror" id="expense_account_id" name="expense_account_id">
                    <option value="">By family (5230 utilities / 5220 rent)</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}"
                                @selected((int) old('expense_account_id', $editing ? $provider->expense_account_id : 0) === $account->id)>
                            {{ $account->code }} — {{ $account->name }}
                        </option>
                    @endforeach
                </select>
                @error('expense_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-3">
                <label class="form-label" for="consumer_no">Consumer number</label>
                <input class="form-control" type="text" maxlength="64" id="consumer_no" name="consumer_no"
                       value="{{ old('consumer_no', $editing ? $provider->consumer_no : '') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="meter_no">Meter number</label>
                <input class="form-control" type="text" maxlength="64" id="meter_no" name="meter_no"
                       value="{{ old('meter_no', $editing ? $provider->meter_no : '') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="premises">Premises</label>
                <input class="form-control" type="text" maxlength="191" id="premises" name="premises"
                       value="{{ old('premises', $editing ? $provider->premises : '') }}" placeholder="Uttara office">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="due_day">Bill lands on</label>
                <input class="form-control @error('due_day') is-invalid @enderror" type="number" min="1" max="31"
                       id="due_day" name="due_day" value="{{ old('due_day', $editing ? $provider->due_day : '') }}"
                       placeholder="Day of month">
                @error('due_day')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-3">
                <label class="form-label" for="branch_id">Branch</label>
                <select class="form-select" id="branch_id" name="branch_id">
                    <option value="">Company-wide</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}"
                                @selected((int) old('branch_id', $editing ? $provider->branch_id : 0) === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="notes">Notes</label>
                <input class="form-control" type="text" maxlength="500" id="notes" name="notes"
                       value="{{ old('notes', $editing ? $provider->notes : '') }}">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="is_active" name="is_active"
                           @checked(old('is_active', $editing ? $provider->is_active : true))>
                    <label class="form-check-label" for="is_active">Active — bills can be filed against it</label>
                </div>
            </div>
        </div>
    </div>

    <div class="erp-actions">
        <button class="btn btn-primary" type="submit">
            <i class="bi bi-check2" aria-hidden="true"></i> {{ $editing ? 'Save the provider' : 'Add the provider' }}
        </button>
        <a class="btn btn-link" href="{{ route('business.utilities.providers') }}">Cancel</a>
    </div>
</form>

@if ($editing && $provider->bills()->exists())
    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Bills already filed against this provider</h2>
                <p class="erp-card-sub">Switching the account changes where the <em>next</em> bill posts. The ones already posted keep the entry they were written with.</p>
            </div>
        </header>
        <div class="table-responsive">
            <table class="table erp-table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Bill</th>
                        <th>Month</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($provider->bills()->orderByDesc('period_month')->limit(8)->get() as $bill)
                        <tr>
                            <td><a class="erp-cell-strong" href="{{ route('business.utilities.show', $bill) }}">{{ $bill->bill_no }}</a></td>
                            <td>{{ $bill->periodLabel() }}</td>
                            <td class="erp-td-num text-end">৳{{ number_format((float) $bill->amount, 2) }}</td>
                            <td><x-ui.status :value="$bill->state()" :label="$bill->stateLabel()" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<x-ui.related-pages />
