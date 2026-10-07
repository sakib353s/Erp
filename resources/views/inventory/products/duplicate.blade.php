@extends('layouts.app')

@section('page_title', 'Copy Product')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Copy product</h1>
            <p class="erp-page-sub">
                A copy of the catalogue row — configuration, not inventory. The new product starts with
                <strong>zero stock</strong>, its own valuation layers and no barcodes.
            </p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('inventory.products.index') }}">Back to products</a>
    </div>

    <div class="erp-note erp-note-info mb-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span>
            Stock belongs to the product that received it. Copying balances or layers would invent inventory
            from a button press, so nothing of the sort happens here: receive opening stock on the new product
            when you actually have it.
        </span>
    </div>

    <div class="erp-split">
        <section class="erp-card">
            <header class="erp-card-head">
                <h2 class="erp-card-title">The copy</h2>
            </header>

            <form method="POST" action="{{ route('inventory.products.duplicate', $product) }}">
                @csrf

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="code">Product code</label>
                        <input class="form-control text-uppercase @error('code') is-invalid @enderror" id="code"
                               name="code" value="{{ old('code', $suggestedCode) }}" maxlength="32" required>
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Unique per company. Suggested from {{ $product->code }}.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="sku">SKU</label>
                        <input class="form-control @error('sku') is-invalid @enderror" id="sku" name="sku"
                               value="{{ old('sku', $suggestedSku) }}" maxlength="64" required>
                        @error('sku')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Unique per company, and the label the ledger will show.</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                               value="{{ old('name') }}" maxlength="191"
                               placeholder="Copy of {{ $product->name }}">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Leave blank to keep the default.</div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-files" aria-hidden="true"></i> Create the copy
                    </button>
                    <a class="btn btn-outline-secondary" href="{{ route('inventory.products.edit', $product) }}">
                        Cancel
                    </a>
                </div>
            </form>
        </section>

        <aside class="erp-card">
            <header class="erp-card-head">
                <h2 class="erp-card-title">What carries over</h2>
            </header>

            <dl class="erp-dl erp-dl-tight erp-dl-striped">
                <dt>Source</dt>
                <dd>
                    <a class="text-decoration-none" href="{{ route('inventory.products.ledger', $product) }}">
                        {{ $product->sku }} — {{ $product->name }}
                    </a>
                </dd>
                <dt>Category</dt>
                <dd>{{ $product->category?->name ?? '—' }}</dd>
                <dt>Brand</dt>
                <dd>{{ $product->brand?->name ?? '—' }}</dd>
                <dt>Unit</dt>
                <dd>{{ $product->unit?->name ?? '—' }}</dd>
                <dt>Cost method</dt>
                <dd><span class="erp-chip erp-chip-soft">{{ strtoupper($product->cost_method) }}</span></dd>
                <dt>Standard cost</dt>
                <dd>{{ number_format((float) $product->standard_cost, 2) }}</dd>
                <dt>Batch / serial tracking</dt>
                <dd>
                    {{ $product->track_batch ? 'batch' : 'no batch' }}
                    ·
                    {{ $product->track_serial ? 'serial' : 'no serial' }}
                </dd>
            </dl>

            <div class="erp-note erp-note-warn mt-3">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                <span>
                    <strong>Not copied:</strong> {{ number_format($sourceStock, 4) }} on hand, the valuation
                    layers behind it, barcodes, cost history and the reorder policy.
                </span>
            </div>
        </aside>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
