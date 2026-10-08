@php
    /* §16-16 — registering cover by hand. */
    $selected = old('product_id', $preselected);
@endphp

<x-ui.page-header
    eyebrow="Sales · Warranty Register"
    title="Register cover by hand"
    subtitle="For goods that went out before this register existed, or for a unit whose serial is being written down at the counter. Deliveries register their own cover — this form is for everything the delivery event could not see. If the product carries a policy its months are used; if not, state them here and say so."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('sales.warranties.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Register
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('sales.warranties.policies') }}">
            <i class="bi bi-clipboard-check" aria-hidden="true"></i> Policies
        </a>
    </x-slot:actions>
</x-ui.page-header>

@if ($errors->any())
    <div class="erp-note erp-note-warn mb-3">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <div>
            <strong>Nothing was registered.</strong>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

<form method="POST" action="{{ route('sales.warranties.store') }}" data-confirm="Register this cover? The dates are fixed from this moment and cannot be edited afterwards.">
    @csrf

    <section class="erp-card mb-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">What is covered</h2>
                <p class="erp-card-sub">A product with a policy uses its months; leaving the months blank does exactly that.</p>
            </div>
        </header>
        <div class="px-3 pb-3">
            <div class="erp-form-grid">
                <div class="erp-filter">
                    <label class="form-label" for="product_id">Product</label>
                    <select class="form-select @error('product_id') is-invalid @enderror" name="product_id" id="product_id" required>
                        <option value="">Choose a product…</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((int) $selected === $product->id)>
                                {{ $product->name }} ({{ $product->code }})
                            </option>
                        @endforeach
                    </select>
                    @error('product_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="erp-filter">
                    <label class="form-label" for="customer_id">Customer</label>
                    <select class="form-select" name="customer_id" id="customer_id">
                        <option value="">Counter / unknown</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" @selected((int) old('customer_id') === $customer->id)>{{ $customer->name }}</option>
                        @endforeach
                    </select>
                    <small class="erp-td-muted">Optional — but a cover with no name on it cannot be found at the counter.</small>
                </div>

                <div class="erp-filter">
                    <label class="form-label" for="serial_no">Serial number</label>
                    <input class="form-control" type="text" name="serial_no" id="serial_no" maxlength="64"
                           value="{{ old('serial_no') }}" placeholder="The unit's own number, if it has one">
                </div>

                <div class="erp-filter">
                    <label class="form-label" for="qty">Quantity covered</label>
                    <input class="form-control" type="number" step="0.0001" min="0.0001" name="qty" id="qty"
                           value="{{ old('qty', '1') }}" required>
                </div>

                <div class="erp-filter">
                    <label class="form-label" for="starts_on">Cover starts</label>
                    <input class="form-control @error('starts_on') is-invalid @enderror" type="date" name="starts_on" id="starts_on"
                           value="{{ old('starts_on', $today) }}" max="{{ $today }}" required>
                    <small class="erp-td-muted">The day the customer received the goods — not today, if those differ.</small>
                    @error('starts_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="erp-filter">
                    <label class="form-label" for="months">Months of cover</label>
                    <input class="form-control" type="number" name="months" id="months" min="1" max="600"
                           value="{{ old('months') }}" placeholder="Leave blank to use the product's policy">
                    <small class="erp-td-muted">Stating months here overrides the policy for this cover only.</small>
                </div>
            </div>

            <div class="erp-filter mt-3">
                <label class="form-label" for="notes">Note</label>
                <input class="form-control" type="text" name="notes" id="notes" maxlength="500"
                       value="{{ old('notes') }}" placeholder="e.g. registered from the paper invoice after migration">
            </div>
        </div>
    </section>

    <div class="erp-form-actions">
        <button class="btn btn-primary" type="submit">
            <i class="bi bi-shield-check" aria-hidden="true"></i> Register cover
        </button>
        <a class="btn btn-link" href="{{ route('sales.warranties.index') }}">Cancel</a>
    </div>
</form>
