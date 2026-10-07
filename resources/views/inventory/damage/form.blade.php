@extends('layouts.app')

@php
    $isLoss = $kind === \App\Domain\Inventory\StockDamageEntry::KIND_LOSS;
    $title = $isLoss ? 'Record a loss' : 'Record damage';
    $subtitle = $isLoss
        ? 'The goods are gone: this entry consumes the valuation layers and books the cost to Inventory Loss & Damage.'
        : 'The goods stay ours: this entry moves them out of sellable stock into the damaged compartment, valued at cost, and you can release them later.';
    $storeRoute = $isLoss ? 'inventory.loss.store' : 'inventory.damage.store';
    $backRoute = $isLoss ? 'inventory.loss.index' : 'inventory.damage.index';
@endphp

@section('page_title', $title)

@section('content')
    <x-ui.page-header eyebrow="Inventory · Damage & loss" :title="$title" :subtitle="$subtitle" :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route($backRoute) }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to the register
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-note erp-note-info mb-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            <strong>Values come from the valuation layers.</strong>
            Every line is valued at the cost the ledger holds for that product in that warehouse (the product's own
            cost method decides which layers), so nothing here is an estimate — and a {{ $isLoss ? 'loss' : 'damage entry' }}
            with no stock behind it is refused rather than posted.
        </div>
    </div>

    <form method="POST" action="{{ route($storeRoute) }}">
        @csrf

        <div class="erp-card mb-3">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label" for="warehouse_id">Warehouse</label>
                    <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                        <option value="">— select —</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected((int) old('warehouse_id') === $warehouse->id)>
                                {{ $warehouse->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('warehouse_id') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="entry_date">Date</label>
                    <input type="date" class="form-control" id="entry_date" name="entry_date"
                           value="{{ old('entry_date', now()->toDateString()) }}" required>
                    @error('entry_date') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="reason_code">Cause</label>
                    <select class="form-select" id="reason_code" name="reason_code">
                        <option value="">— not classified —</option>
                        @foreach ($reasons as $key => $label)
                            <option value="{{ $key }}" @selected(old('reason_code') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">The cause is what the damage report groups by.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="reason">Reason</label>
                    <input class="form-control" id="reason" name="reason" value="{{ old('reason') }}"
                           required maxlength="500" placeholder="{{ $isLoss ? 'Pallet missing at cycle count' : 'Crushed carton in aisle 4' }}">
                    @error('reason') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        @error('lines') <div class="alert alert-danger">{{ $message }}</div> @enderror

        <x-ui.table-shell title="Lines">
            <thead>
                <tr>
                    <th style="width: 40%">Product</th>
                    <th style="width: 15%">Quantity</th>
                    <th style="width: 30%">Note</th>
                    <th style="width: 56px"></th>
                </tr>
            </thead>
            <tbody id="lines-body">
                @for ($i = 0; $i < 2; $i++)
                    <tr class="line-row">
                        <td>
                            <select class="form-select" name="lines[{{ $i }}][product_id]" required>
                                <option value="">— select —</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}" @selected((int) old("lines.$i.product_id") === $product->id)>
                                        {{ $product->sku }} · {{ $product->name }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <input type="number" step="0.0001" min="0.0001" class="form-control"
                                   name="lines[{{ $i }}][qty]" required value="{{ old("lines.$i.qty") }}" placeholder="1">
                        </td>
                        <td>
                            <input class="form-control" name="lines[{{ $i }}][narration]" maxlength="500"
                                   value="{{ old("lines.$i.narration") }}" placeholder="Optional detail">
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-danger remove-line" aria-label="Remove line">&times;</button>
                        </td>
                    </tr>
                @endfor
            </tbody>
        </x-ui.table-shell>

        <div class="d-flex gap-2 mt-3">
            <button class="btn btn-outline-secondary btn-sm" id="add-line" type="button">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add line
            </button>
            <div class="ms-auto d-flex gap-2">
                <a class="btn btn-outline-secondary" href="{{ route($backRoute) }}">Cancel</a>
                <button class="btn btn-primary" type="submit">
                    {{ $isLoss ? 'Record the loss' : 'Record the damage' }}
                </button>
            </div>
        </div>
    </form>

    @push('scripts')
    <script>
        (function () {
            const tbody = document.getElementById('lines-body');
            const addBtn = document.getElementById('add-line');

            function reindex() {
                tbody.querySelectorAll('.line-row').forEach(function (row, i) {
                    row.querySelectorAll('[name]').forEach(function (el) {
                        el.name = el.name.replace(/lines\[\d+\]/, 'lines[' + i + ']');
                    });
                });
            }

            addBtn.addEventListener('click', function () {
                const clone = tbody.querySelector('.line-row').cloneNode(true);
                clone.querySelectorAll('select, input').forEach(function (el) {
                    if (el.tagName === 'SELECT') el.selectedIndex = 0;
                    else el.value = '';
                });
                tbody.appendChild(clone);
                reindex();
            });

            tbody.addEventListener('click', function (e) {
                if (e.target.classList.contains('remove-line') && tbody.querySelectorAll('.line-row').length > 1) {
                    e.target.closest('tr').remove();
                    reindex();
                }
            });
        })();
    </script>
    @endpush
@endsection
