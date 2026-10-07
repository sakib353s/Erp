@extends('layouts.app')

@section('page_title', $mode === 'create' ? 'Add Product' : 'Edit Product')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $mode === 'create' ? 'Add Product' : 'Edit Product' }}</h1>
            <p class="erp-page-sub">SKU unique per company. Cost method change never rewrites posted valuation layers.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('inventory.products.index') }}">Back</a>
    </div>

    <div class="erp-card" style="max-width: 860px">
        <form method="POST"
              action="{{ $mode === 'create' ? route('inventory.products.store') : route('inventory.products.update', $product) }}">
            @csrf
            @if ($mode === 'edit')
                @method('PUT')
            @endif

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label" for="code">Code</label>
                    <input class="form-control" id="code" name="code" value="{{ old('code', $product->code) }}"
                           required maxlength="32">
                    @error('code') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="sku">SKU</label>
                    <input class="form-control" id="sku" name="sku" value="{{ old('sku', $product->sku) }}"
                           required maxlength="64">
                    @error('sku') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="barcode">Barcode</label>
                    <input class="form-control" id="barcode" name="barcode" value="{{ old('barcode', $product->barcode) }}"
                           maxlength="64">
                </div>
                <div class="col-12">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" value="{{ old('name', $product->name) }}"
                           required maxlength="191">
                    @error('name') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-12">
                    <label class="form-label" for="description">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="2"
                              maxlength="500">{{ old('description', $product->description) }}</textarea>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label" for="product_category_id">Category</label>
                    <select class="form-select" id="product_category_id" name="product_category_id">
                        <option value="">— none —</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}"
                                @selected((int) old('product_category_id', $product->product_category_id) === $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="brand_id">Brand</label>
                    <select class="form-select" id="brand_id" name="brand_id">
                        <option value="">— none —</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->id }}"
                                @selected((int) old('brand_id', $product->brand_id) === $brand->id)>
                                {{ $brand->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="unit_id">Unit</label>
                    <select class="form-select" id="unit_id" name="unit_id">
                        <option value="">— none —</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}"
                                @selected((int) old('unit_id', $product->unit_id) === $unit->id)>
                                {{ $unit->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="cost_method">Cost method</label>
                    <select class="form-select" id="cost_method" name="cost_method" required>
                        @foreach ($costMethods as $method)
                            <option value="{{ $method }}"
                                @selected(old('cost_method', $product->cost_method) === $method)>
                                {{ strtoupper($method) }}
                            </option>
                        @endforeach
                    </select>
                    @error('cost_method') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="standard_cost">Standard cost</label>
                    <input type="number" step="0.0001" min="0" class="form-control" id="standard_cost"
                           name="standard_cost" value="{{ old('standard_cost', $product->standard_cost ?? 0) }}">
                </div>
            </div>

            <div class="d-flex flex-wrap gap-3 mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_stocked" value="1" id="is_stocked"
                        @checked(old('is_stocked', $product->is_stocked ?? true))>
                    <label class="form-check-label" for="is_stocked">Stock-managed</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="track_batch" value="1" id="track_batch"
                        @checked(old('track_batch', $product->track_batch ?? false))>
                    <label class="form-check-label" for="track_batch">Track batch</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="track_serial" value="1" id="track_serial"
                        @checked(old('track_serial', $product->track_serial ?? false))>
                    <label class="form-check-label" for="track_serial">Track serial</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
                        @checked(old('is_active', $product->is_active ?? true))>
                    <label class="form-check-label" for="is_active">Active</label>
                </div>
            </div>

            <button class="btn btn-primary" type="submit">
                {{ $mode === 'create' ? 'Create product' : 'Save changes' }}
            </button>
        </form>
    </div>
@endsection
