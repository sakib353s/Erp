@php
    /* §12-15 — file a bill, or correct one that has not been paid. */
    $editing = $bill !== null;
    $selectedProvider = old('provider_id', $editing ? $bill->provider_id : null);
@endphp

<x-ui.page-header
    eyebrow="Business Management · Utility Bills · {{ $editing ? 'Edit' : 'File' }}"
    title="{{ $editing ? 'Correct bill '.$bill->bill_no : 'File a utility bill' }}"
    subtitle="{{ $editing
        ? 'A bill that has not been paid can be corrected — the reading can be re-checked, the amount fixed, the due date moved. A bill that has been paid cannot: the ledger has that figure, and rewriting it would leave the accounts describing something that never happened.'
        : 'Record what the provider asks for, for which month, and by when. Nothing goes to the ledger now — the entry is written on the day the money actually leaves, from the pay button on the bill itself.' }}"
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ $editing ? route('business.utilities.show', $bill) : route('business.utilities.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to the desk
        </a>
    </x-slot:actions>
</x-ui.page-header>

@if ($providers->isEmpty())
    <x-ui.empty
        icon="bi-plug"
        title="No providers in the registry yet"
        text="A bill needs a counterparty, and the counterparty is what decides which ledger account it posts to. Add the suppliers of your premises first."
        action="Open the provider registry"
        :href="route('business.utilities.providers')" />
@else
    <form method="POST"
          action="{{ $editing ? route('business.utilities.update', $bill) : route('business.utilities.store') }}"
          data-confirm="{{ $editing ? 'Save these changes to '.$bill->bill_no.'?' : 'File this bill?' }}">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <div class="erp-card mb-3">
            <div class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">The bill</h2>
                    <p class="erp-card-sub">The account it will post to comes from the provider — which shelf it sits on is the registry's business, not this form's.</p>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="provider_id">Provider</label>
                    <select class="form-select @error('provider_id') is-invalid @enderror" id="provider_id" name="provider_id" required>
                        <option value="">Choose the provider…</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider->id }}" @selected((int) $selectedProvider === $provider->id)>
                                {{ $provider->familyLabel() }} · {{ $provider->name }}{{ $provider->consumer_no ? ' ('.$provider->consumer_no.')' : '' }}
                            </option>
                        @endforeach
                    </select>
                    @error('provider_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="period_month">Month it covers</label>
                    <input class="form-control @error('period_month') is-invalid @enderror" type="month"
                           id="period_month" name="period_month"
                           value="{{ old('period_month', $editing ? $bill->period_month : $period) }}" required>
                    @error('period_month')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="amount">Amount</label>
                    <input class="form-control erp-num @error('amount') is-invalid @enderror" type="number" step="0.01" min="0.01"
                           id="amount" name="amount" value="{{ old('amount', $editing ? $bill->amount : '') }}" required>
                    @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="issue_date">Bill date</label>
                    <input class="form-control @error('issue_date') is-invalid @enderror" type="date"
                           id="issue_date" name="issue_date"
                           value="{{ old('issue_date', $editing ? $bill->issue_date?->toDateString() : now()->toDateString()) }}" required>
                    @error('issue_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="due_date">Due on</label>
                    <input class="form-control @error('due_date') is-invalid @enderror" type="date"
                           id="due_date" name="due_date"
                           value="{{ old('due_date', $editing ? $bill->due_date?->toDateString() : now()->addDays(10)->toDateString()) }}" required>
                    @error('due_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="meter_reading">Meter reading</label>
                    <input class="form-control erp-num @error('meter_reading') is-invalid @enderror" type="number" step="0.001" min="0"
                           id="meter_reading" name="meter_reading" value="{{ old('meter_reading', $editing ? $bill->meter_reading : '') }}"
                           placeholder="Leave blank for a rent bill">
                    @error('meter_reading')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="consumption">Consumption</label>
                    <input class="form-control erp-num @error('consumption') is-invalid @enderror" type="number" step="0.0001" min="0"
                           id="consumption" name="consumption" value="{{ old('consumption', $editing ? $bill->consumption : '') }}"
                           placeholder="kWh / m³ / MB">
                    @error('consumption')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label" for="narration">What this bill is</label>
                    <input class="form-control @error('narration') is-invalid @enderror" type="text" maxlength="500"
                           id="narration" name="narration" value="{{ old('narration', $editing ? $bill->narration : '') }}"
                           placeholder="Uttara office, September reading">
                    @error('narration')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="erp-form-actions">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-check2" aria-hidden="true"></i> {{ $editing ? 'Save the correction' : 'File the bill' }}
            </button>
            <a class="btn btn-link" href="{{ $editing ? route('business.utilities.show', $bill) : route('business.utilities.index') }}">Cancel</a>
        </div>
    </form>
@endif

<x-ui.related-pages />
