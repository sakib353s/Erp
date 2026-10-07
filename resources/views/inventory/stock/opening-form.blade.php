@extends('layouts.app')

@section('page_title', 'Opening Stock')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Opening Stock</h1>
            <p class="erp-page-sub">Posts OPENING movements through StockLedgerService. Quantities must be positive.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('inventory.stock') }}">Back to stock</a>
    </div>

    <div class="erp-card" style="max-width: 960px">
        <form method="POST" action="{{ route('inventory.stock.opening.store') }}" id="opening-form">
            @csrf

            <div class="row g-3 mb-4">
                <div class="col-md-5">
                    <label class="form-label" for="warehouse_id">Warehouse</label>
                    <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                        <option value="">— select —</option>
                        @foreach ($warehouses as $wh)
                            <option value="{{ $wh->id }}" @selected(old('warehouse_id') == $wh->id)>
                                {{ $wh->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('warehouse_id') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-7">
                    <label class="form-label" for="narration">Narration</label>
                    <input class="form-control" id="narration" name="narration" value="{{ old('narration') }}"
                           maxlength="500" placeholder="Opening balance note">
                </div>
                <input type="hidden" name="idempotency_suffix" value="{{ old('idempotency_suffix', uniqid()) }}">
            </div>

            @error('lines') <div class="alert alert-danger">{{ $message }}</div> @enderror

            <div class="table-responsive mb-3">
                <table class="table erp-table" id="lines-table">
                    <thead>
                        <tr>
                            <th style="width: 50%">Product</th>
                            <th style="width: 20%">Quantity</th>
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
                                           name="lines[{{ $i }}][qty]" required
                                           value="{{ old("lines.$i.qty") }}" placeholder="0.00">
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

            <div>
                <button class="btn btn-primary" type="submit">Post opening stock</button>
            </div>
        </form>
    </div>

    @push('scripts')
    <script>
        (function () {
            let idx = 2;
            const tbody = document.querySelector('#lines-table tbody');
            const products = @json($products->map(fn ($p) => ['id' => $p->id, 'label' => $p->sku.' · '.$p->name])->values());

            document.getElementById('add-line').addEventListener('click', function () {
                const row = document.createElement('tr');
                row.className = 'line-row';
                let options = '<option value="">— select —</option>';
                products.forEach(function (p) {
                    options += '<option value="' + p.id + '">' + p.label + '</option>';
                });
                row.innerHTML =
                    '<td><select class="form-select" name="lines[' + idx + '][product_id]" required>' + options + '</select></td>' +
                    '<td><input type="number" step="0.0001" min="0.0001" class="form-control" name="lines[' + idx + '][qty]" required></td>' +
                    '<td><input type="number" step="0.0001" min="0" class="form-control" name="lines[' + idx + '][unit_cost]"></td>' +
                    '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-line">&times;</button></td>';
                tbody.appendChild(row);
                idx++;
            });

            tbody.addEventListener('click', function (e) {
                if (e.target.classList.contains('remove-line')) {
                    const rows = tbody.querySelectorAll('.line-row');
                    if (rows.length > 1) {
                        e.target.closest('tr').remove();
                    }
                }
            });
        })();
    </script>
    @endpush
@endsection
