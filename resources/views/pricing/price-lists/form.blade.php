@extends('layouts.app')

@section('page_title', $mode === 'create' ? 'Add Price List' : 'Edit Price List')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $mode === 'create' ? 'Add Price List' : 'Edit Price List' }}</h1>
            <p class="erp-page-sub">Code unique per company · exactly one default list feeds PricingService.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('pricing.price-lists.index') }}">Back</a>
    </div>

    <div class="erp-card" style="max-width: 960px">
        <form method="POST"
              action="{{ $mode === 'create' ? route('pricing.price-lists.store') : route('pricing.price-lists.update', $list) }}">
            @csrf
            @if ($mode === 'edit')
                @method('PUT')
            @endif

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label" for="code">Code</label>
                    <input class="form-control" id="code" name="code" value="{{ old('code', $list->code) }}"
                           required maxlength="32">
                    @error('code') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" value="{{ old('name', $list->name) }}"
                           required maxlength="128">
                    @error('name') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1"
                               @checked(old('is_default', $list->is_default))>
                        <label class="form-check-label" for="is_default">Default list</label>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label" for="valid_from">Valid from</label>
                    <input class="form-control" id="valid_from" name="valid_from" type="date"
                           value="{{ old('valid_from', $list->valid_from?->toDateString()) }}">
                    @error('valid_from') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="valid_to">Valid to</label>
                    <input class="form-control" id="valid_to" name="valid_to" type="date"
                           value="{{ old('valid_to', $list->valid_to?->toDateString()) }}">
                    @error('valid_to') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               @checked(old('is_active', $list->exists ? $list->is_active : true))>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <h2 class="h5 mb-2">Price rows</h2>
            <p class="text-muted small">Product prices on this list — PricingService resolves these before falling back to standard cost.</p>

            @error('items') <div class="text-danger small mb-2">{{ $message }}</div> @enderror

            <div class="table-responsive mb-2">
                <table class="table erp-table align-middle mb-0" id="price-rows">
                    <thead>
                        <tr>
                            <th style="width: 65%">Product</th>
                            <th>Price</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $rows = old('items', $mode === 'edit'
                                ? $list->items->map(fn ($item) => ['product_id' => $item->product_id, 'price' => $item->price])->all()
                                : []);
                        @endphp
                        @foreach ($rows as $index => $row)
                            @include('pricing.price-lists._row', ['index' => $index, 'row' => $row, 'products' => $products])
                        @endforeach
                    </tbody>
                </table>
            </div>

            <button class="btn btn-outline-secondary btn-sm mb-4" type="button" id="add-price-row">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add price row
            </button>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">{{ $mode === 'create' ? 'Create price list' : 'Save changes' }}</button>
                <a class="btn btn-outline-secondary" href="{{ route('pricing.price-lists.index') }}">Cancel</a>
            </div>
        </form>
    </div>

    <template id="price-row-template">
        @include('pricing.price-lists._row', ['index' => '__INDEX__', 'row' => ['product_id' => '', 'price' => ''], 'products' => $products])
    </template>

    <script>
        (function () {
            let index = {{ count($rows) }};
            const body = document.querySelector('#price-rows tbody');
            const template = document.getElementById('price-row-template').innerHTML;

            document.getElementById('add-price-row').addEventListener('click', function () {
                body.insertAdjacentHTML('beforeend', template.replaceAll('__INDEX__', index));
                index += 1;
            });

            body.addEventListener('click', function (event) {
                const button = event.target.closest('[data-remove-row]');
                if (button) {
                    button.closest('tr').remove();
                }
            });
        })();
    </script>
@endsection
