@extends('layouts.app')

@section('page_title', 'Open a count sheet')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Stock"
        title="Open a count sheet"
        subtitle="The sheet freezes the balance every product shows right now. Nothing is corrected until somebody counts the shelf and a manager posts the difference."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.counts.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to counts
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-note erp-note-info mb-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            <strong>A full count lists every stocked product in the warehouse</strong> — including products the ledger
            believes are at zero, because finding stock nobody thought was there is half the point. A
            <strong>cycle count</strong> asks about the products you choose: one aisle, one supplier, one line at a time.
        </div>
    </div>

    <form method="POST" action="{{ route('inventory.counts.store') }}">
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
                    <label class="form-label" for="count_date">Count date</label>
                    <input type="date" class="form-control" id="count_date" name="count_date"
                           value="{{ old('count_date', now()->toDateString()) }}" required>
                    @error('count_date') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="scope">Scope</label>
                    <select class="form-select" id="scope" name="scope" required>
                        @foreach ($scopes as $key => $label)
                            <option value="{{ $key }}" @selected(old('scope', $scope) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('scope') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="notes">Notes</label>
                    <input class="form-control" id="notes" name="notes" maxlength="500" value="{{ old('notes') }}"
                           placeholder="Who is counting, and what">
                    @error('notes') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        <div id="cycle-scope" class="d-none">
            @error('products') <div class="alert alert-danger">{{ $message }}</div> @enderror

            <section class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Products to count</h2>
                    <span class="erp-chip erp-chip-outline">{{ $products->count() }} stocked product(s)</span>
                </header>
                <p class="erp-td-muted small mb-2">
                    Hold Ctrl (or ⌘) to pick more than one. Only active, stocked products are listed — a service item
                    has no shelf to count.
                </p>
                <select class="form-select" name="products[]" id="products" multiple size="14">
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected(in_array($product->id, array_map('intval', old('products', [])), true))>
                            {{ $product->sku }} · {{ $product->name }}
                        </option>
                    @endforeach
                </select>
            </section>
        </div>

        <div class="d-flex gap-2 mt-3">
            <a class="btn btn-outline-secondary" href="{{ route('inventory.counts.index') }}">Cancel</a>
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-clipboard-check" aria-hidden="true"></i> Open the sheet
            </button>
        </div>
    </form>

    @push('scripts')
    <script>
        (function () {
            const scope = document.getElementById('scope');
            const cycle = document.getElementById('cycle-scope');

            function sync() {
                const isCycle = scope.value === 'cycle';
                cycle.classList.toggle('d-none', !isCycle);
                document.getElementById('products').required = isCycle;
            }

            scope.addEventListener('change', sync);
            sync();
        })();
    </script>
    @endpush
@endsection
