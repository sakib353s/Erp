@extends('layouts.app')

@section('page_title', 'Bulk Price Update')

@php
    $value = fn (string $key, $default = '') => old($key, $form[$key] ?? $default);
@endphp

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Bulk Price Update</h1>
            <p class="erp-page-sub">
                Preview is pure — nothing is written until you confirm · changes at or above
                {{ $threshold }}% are held for workflow approval · every applied line is written to price history
            </p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('pricing.price-lists.index') }}">Price lists</a>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="alert">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="erp-card mb-3">
        <form method="POST" action="{{ route('pricing.bulk-update.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="price_list_id">Price list</label>
                    <select class="form-select" id="price_list_id" name="price_list_id" required>
                        <option value="">— choose —</option>
                        @foreach ($lists as $list)
                            <option value="{{ $list->id }}" @selected((string) $value('price_list_id') === (string) $list->id)>
                                {{ $list->name }} ({{ $list->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="change_type">Change</label>
                    <select class="form-select" id="change_type" name="change_type" required>
                        <option value="percent" @selected($value('change_type', 'percent') === 'percent')>Adjust by percent</option>
                        <option value="set" @selected($value('change_type') === 'set')">Set fixed price</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="percent">Percent (±)</label>
                    <input class="form-control" id="percent" name="percent" type="number" step="0.01"
                           min="-99.99" max="1000" value="{{ $value('percent') }}" placeholder="e.g. -10">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="set_price">Set price</label>
                    <input class="form-control" id="set_price" name="set_price" type="number" step="0.01"
                           min="0" value="{{ $value('set_price') }}" placeholder="e.g. 199.00">
                </div>

                <div class="col-md-5">
                    <label class="form-label" for="product_codes">Product codes <span class="text-muted">(comma separated; blank = every product in the list)</span></label>
                    <textarea class="form-control" id="product_codes" name="product_codes" rows="2"
                              placeholder="TSH-1, TSH-2">{{ old('product_codes', $preview['codes_text'] ?? '') }}</textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="category_id">Category</label>
                    <select class="form-select" id="category_id" name="category_id">
                        <option value="">All categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) $value('category_id') === (string) $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="brand_id">Brand</label>
                    <select class="form-select" id="brand_id" name="brand_id">
                        <option value="">All brands</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->id }}" @selected((string) $value('brand_id') === (string) $brand->id)>
                                {{ $brand->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="note">Note</label>
                    <input class="form-control" id="note" name="note" maxlength="255"
                           value="{{ $value('note') }}">
                </div>
            </div>

            <div class="d-flex gap-2 mt-3">
                <button class="btn btn-outline-primary" type="submit" name="mode" value="preview">Preview</button>
                <button class="btn btn-primary" type="submit" name="mode" value="confirm">Confirm update</button>
            </div>
        </form>
    </div>

    @if ($preview !== null)
        <div class="erp-card mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="erp-h3 mb-0">Preview — {{ $preview['result']['summary']['matched'] }} matched</h2>
                <span class="text-muted small">
                    {{ $preview['result']['summary']['changed'] }} price(s) would change ·
                    largest change {{ number_format($preview['result']['summary']['max_abs_pct'], 2) }}%
                    @if ($preview['result']['summary']['approval_required'])
                        · <strong>≥ {{ $threshold }}% → workflow approval required</strong>
                    @else
                        · below the {{ $threshold }}% threshold
                    @endif
                </span>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Product</th>
                            <th class="text-end">Old price</th>
                            <th class="text-end">New price</th>
                            <th class="text-end">Δ</th>
                            <th class="text-end">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($preview['result']['rows'] as $row)
                            <tr class="{{ $row['changed'] ? '' : 'text-muted' }}">
                                <td class="fw-semibold">{{ $row['product_code'] }}</td>
                                <td>{{ $row['product_name'] }}</td>
                                <td class="text-end">{{ number_format($row['old_price'], 2) }}</td>
                                <td class="text-end">{{ number_format($row['new_price'], 2) }}</td>
                                <td class="text-end">{{ ($row['delta'] >= 0 ? '+' : '').number_format($row['delta'], 2) }}</td>
                                <td class="text-end">
                                    {{ $row['percent_change'] === null ? '—' : (($row['percent_change'] >= 0 ? '+' : '').number_format($row['percent_change'], 2).'%') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    No products in this price list match the selection.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="text-muted small mt-2 mb-0">
                Nothing has been written yet — press <strong>Confirm update</strong> to queue this exact change.
                Confirming recomputes from the source tables, so the applied result always matches the current data.
            </p>
        </div>
    @endif

    <div class="erp-card">
        <h2 class="erp-h3">Recent batches</h2>
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Price list</th>
                        <th>Change</th>
                        <th>Note</th>
                        <th>Status</th>
                        <th class="text-end">Rows</th>
                        <th>By</th>
                        <th>Applied</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recent as $batch)
                        <tr>
                            <td>{{ $batch->id }}</td>
                            <td>{{ $batch->priceList?->name ?? '—' }}</td>
                            <td>
                                @if ($batch->change_type === 'percent')
                                    {{ (float) $batch->percent > 0 ? '+' : '' }}{{ number_format((float) $batch->percent, 2) }}%
                                @else
                                    set {{ number_format((float) $batch->set_price, 2) }}
                                @endif
                            </td>
                            <td>{{ $batch->note ?? '—' }}</td>
                            <td>
                                @php
                                    $badge = match ($batch->status) {
                                        'applied' => 'success',
                                        'pending_approval' => 'warning',
                                        'failed' => 'danger',
                                        default => 'secondary',
                                    };
                                @endphp
                                <span class="text-bg-{{ $badge }} badge">{{ $batch->status }}</span>
                            </td>
                            <td class="text-end">{{ $batch->row_count }}</td>
                            <td>{{ $batch->creator?->name ?? '—' }}</td>
                            <td>{{ $batch->applied_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No batches yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
