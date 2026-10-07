@extends('layouts.app')

@section('page_title', 'New Stock Transfer')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Stock Transfer</h1>
            <p class="erp-page-sub">Creates a draft. Dispatch then receive across two warehouses. Quantities must be positive.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('inventory.transfers.index') }}">Back</a>
    </div>

    <div class="erp-card" style="max-width: 960px">
        <form method="POST" action="{{ route('inventory.transfers.store') }}">
            @csrf

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label" for="from_warehouse_id">From warehouse</label>
                    <select class="form-select" id="from_warehouse_id" name="from_warehouse_id" required>
                        <option value="">— select —</option>
                        @foreach ($warehouses as $wh)
                            <option value="{{ $wh->id }}" @selected(old('from_warehouse_id') == $wh->id)>
                                {{ $wh->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('from_warehouse_id') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="to_warehouse_id">To warehouse</label>
                    <select class="form-select" id="to_warehouse_id" name="to_warehouse_id" required>
                        <option value="">— select —</option>
                        @foreach ($warehouses as $wh)
                            <option value="{{ $wh->id }}" @selected(old('to_warehouse_id') == $wh->id)>
                                {{ $wh->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('to_warehouse_id') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="transfer_date">Date</label>
                    <input type="date" class="form-control" id="transfer_date" name="transfer_date"
                           value="{{ old('transfer_date', now()->toDateString()) }}" required>
                    @error('transfer_date') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-12">
                    <label class="form-label" for="narration">Narration</label>
                    <input class="form-control" id="narration" name="narration" value="{{ old('narration') }}"
                           maxlength="500" placeholder="Replenish branch store">
                </div>
            </div>

            @error('lines') <div class="alert alert-danger">{{ $message }}</div> @enderror

            <div class="table-responsive mb-3">
                <table class="table erp-table" id="lines-table">
                    <thead>
                        <tr>
                            <th style="width: 45%">Product</th>
                            <th style="width: 20%">Qty sent</th>
                            <th style="width: 20%">Unit cost</th>
                            <th style="width: 56px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @for ($i = 0; $i < 2; $i++)
                            <tr class="line-row">
                                <td>
                                    <select class="form-select" name="lines[{{ $i }}][product_id]" required>
                                        <option value="">— select —</option>
                                        @foreach ($products as $product)
                                            <option value="{{ $product->id }}"
                                                @selected((int) old("lines.$i.product_id") === $product->id)>
                                                {{ $product->sku }} · {{ $product->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <input type="number" step="0.0001" min="0.0001" class="form-control"
                                           name="lines[{{ $i }}][qty_sent]" required
                                           value="{{ old("lines.$i.qty_sent") }}" placeholder="0.00">
                                </td>
                                <td>
                                    <input type="number" step="0.0001" min="0" class="form-control"
                                           name="lines[{{ $i }}][unit_cost]"
                                           value="{{ old("lines.$i.unit_cost") }}" placeholder="0.0000">
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-line"
                                            aria-label="Remove line">&times;</button>
                                </td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>

            <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="add-line">+ Add line</button>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Create transfer</button>
                <a class="btn btn-outline-secondary" href="{{ route('inventory.transfers.index') }}">Cancel</a>
            </div>
        </form>
    </div>

    @push('scripts')
    <script>
        (function () {
            const tbody = document.querySelector('#lines-table tbody');
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
                if (e.target.classList.contains('remove-line')) {
                    if (tbody.querySelectorAll('.line-row').length > 1) {
                        e.target.closest('tr').remove();
                        reindex();
                    }
                }
            });
        })();
    </script>
    @endpush

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
