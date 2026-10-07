@extends('layouts.app')

@section('page_title', 'Record a supplier payment')

@section('content')
    <x-ui.page-header
        eyebrow="Purchase · Supplier payments"
        title="Record a supplier payment"
        subtitle="A payment settles a posted bill: accounts payable is debited, cash or bank is credited, and the bill's balance moves in the same transaction."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('purchase.payments.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Payment history
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @error('payment')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    @unless ($bill)
        @if ($payableBills->isNotEmpty())
            <form class="erp-inline-form" method="GET" action="{{ route('purchase.payments.create') }}">
                <label class="form-label" for="bill">Which bill are we paying?</label>
                <div class="d-flex gap-2">
                    <select class="form-select" id="bill" name="bill" required>
                        <option value="">Choose an open bill…</option>
                        @foreach ($payableBills as $payable)
                            <option value="{{ $payable->id }}">
                                {{ $payable->code }} · {{ $payable->supplier?->name }} ·
                                ৳ {{ number_format((float) $payable->due_amount, 2) }} due
                                @if ($payable->due_date) · due {{ $payable->due_date->format('d M Y') }}@endif
                            </option>
                        @endforeach
                    </select>
                    <button class="btn btn-outline-secondary" type="submit">Open the bill</button>
                </div>
                <div class="erp-help mt-1">Only posted bills with a balance are listed — a draft bill is not a liability yet.</div>
            </form>
        @else
            <x-ui.empty icon="bi-receipt" title="Nothing is payable right now"
                        text="Every posted bill is settled. New payments appear here as soon as a bill is approved." />
        @endif
    @endunless

    @if ($bill)
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                <strong>Paying {{ $bill->code }}</strong> from {{ $bill->supplier?->name }}.
                The balance is ৳ {{ number_format((float) $bill->due_amount, 2) }} —
                a payment above that is refused rather than silently turned into a credit.
            </div>
        </div>

        <div class="erp-split">
            <div class="erp-card">
                <div class="erp-card-head">
                    <h2 class="erp-card-title">The bill</h2>
                    <div class="erp-card-actions"><x-ui.status :value="$bill->status" /></div>
                </div>
                <dl class="erp-dl erp-dl-tight px-3 pb-3">
                    <dt>Bill</dt>
                    <dd><a href="{{ route('purchase.bills.show', $bill) }}">{{ $bill->code }}</a></dd>
                    <dt>Their reference</dt>
                    <dd>{{ $bill->supplier_bill_no ?: '—' }}</dd>
                    <dt>Bill date</dt>
                    <dd>{{ $bill->bill_date?->format('d M Y') }}</dd>
                    <dt>Due</dt>
                    <dd>{{ $bill->due_date?->format('d M Y') ?? 'On demand' }}</dd>
                    <dt>Bill total</dt>
                    <dd>৳ {{ number_format((float) $bill->total, 2) }}</dd>
                    <dt>Already paid</dt>
                    <dd>৳ {{ number_format((float) $bill->paid_amount, 2) }}</dd>
                    <dt>Still owed</dt>
                    <dd><span class="erp-cell-strong">৳ {{ number_format((float) $bill->due_amount, 2) }}</span></dd>
                </dl>
            </div>

            <div class="erp-card">
                <div class="erp-card-head">
                    <h2 class="erp-card-title">The payment</h2>
                </div>
                <form method="POST" action="{{ route('purchase.payments.store') }}">
                    @csrf
                    <input type="hidden" name="bill_id" value="{{ $bill->id }}">

                    <div class="erp-form-grid px-3 pb-3">
                        <div class="erp-form-field">
                            <label class="form-label" for="amount">Amount <span class="text-danger">*</span></label>
                            <input class="form-control @error('amount') is-invalid @enderror" type="number" step="0.01" min="0.01"
                                   max="{{ (float) $bill->due_amount }}" id="amount" name="amount"
                                   value="{{ old('amount', number_format((float) $bill->due_amount, 2, '.', '')) }}" required>
                            <div class="erp-help">Defaults to the full balance; change it for a part payment.</div>
                            @error('amount')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="method">Paid by <span class="text-danger">*</span></label>
                            <select class="form-select" id="method" name="method" required>
                                <option value="cash" @selected(old('method') === 'cash')>Cash</option>
                                <option value="bank" @selected(old('method') === 'bank')>Bank transfer</option>
                                <option value="cheque" @selected(old('method') === 'cheque')>Cheque</option>
                                <option value="mobile" @selected(old('method') === 'mobile')>Mobile banking</option>
                            </select>
                            <div class="erp-help">Cash credits Cash in Hand; every other method credits the bank account.</div>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="paid_at">Paid on <span class="text-danger">*</span></label>
                            <input class="form-control @error('paid_at') is-invalid @enderror" type="date" id="paid_at" name="paid_at"
                                   value="{{ old('paid_at', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                            @error('paid_at')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="reference">Reference</label>
                            <input class="form-control" id="reference" name="reference" maxlength="64" value="{{ old('reference') }}"
                                   placeholder="Cheque no, bank ref, bKash TrxID…">
                        </div>
                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="narration">Narration</label>
                            <textarea class="form-control" id="narration" name="narration" rows="2" maxlength="500">{{ old('narration') }}</textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2 px-3 pb-3">
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-check-lg" aria-hidden="true"></i> Record the payment
                        </button>
                        <a class="btn btn-outline-secondary" href="{{ route('purchase.bills.show', $bill) }}">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <x-ui.related-pages />
@endsection
