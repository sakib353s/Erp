@extends('layouts.app')

@section('page_title', 'Reorder levels')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Stock"
        title="Minimum / maximum stock levels"
        subtitle="What counts as too low and too much, per product and per warehouse. These rows are the only reason a stock alert exists — a product without a policy is never flagged."
        :pin="true">
        <x-slot:actions>
            @if ($perm('inventory.stock.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.stock') }}">
                    <i class="bi bi-boxes" aria-hidden="true"></i> Stock overview
                </a>
            @endif
            @if ($perm('inventory.reorder.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.stock.alerts') }}">
                    <i class="bi bi-bell" aria-hidden="true"></i> Alerts
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @error('reorder')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.reorder.index') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="SKU or product name…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="warehouse">Warehouse</label>
            <select class="form-select" id="warehouse" name="warehouse">
                <option value="">All warehouses</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected($filters['warehouse'] === $warehouse->id)>{{ $warehouse->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if ($filters['q'] !== '' || $filters['warehouse'])
                <a class="btn btn-link" href="{{ route('inventory.reorder.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    @if ($perm('inventory.reorder.configure'))
        <div class="erp-card mb-3">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Set or change a level</h2>
                <div class="erp-card-actions">
                    <span class="erp-help">Saving the same product + warehouse again replaces the row — one policy per pair.</span>
                </div>
            </div>
            <form class="erp-inline-form px-3 pb-3" method="POST" action="{{ route('inventory.reorder.store') }}">
                @csrf
                <div class="erp-form-grid">
                    <div class="erp-form-field">
                        <label class="form-label" for="product_id">Product <span class="text-danger">*</span></label>
                        <select class="form-select @error('product_id') is-invalid @enderror" id="product_id" name="product_id" required>
                            <option value="">Choose a product…</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}" @selected((int) old('product_id') === $product->id)>
                                    {{ $product->sku }} · {{ \Illuminate\Support\Str::limit($product->name, 48) }}
                                </option>
                            @endforeach
                        </select>
                        @error('product_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="warehouse_id">Applies to</label>
                        <select class="form-select" id="warehouse_id" name="warehouse_id">
                            <option value="">Every warehouse (company-wide rule)</option>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected((int) old('warehouse_id') === $warehouse->id)>
                                    {{ $warehouse->name }} only
                                </option>
                            @endforeach
                        </select>
                        <div class="erp-help">A warehouse's own row wins over the company-wide one for that shelf.</div>
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="min_level">Minimum level <span class="text-danger">*</span></label>
                        <input class="form-control erp-num @error('min_level') is-invalid @enderror" type="number" step="0.0001" min="0"
                               id="min_level" name="min_level" value="{{ old('min_level', 0) }}" required>
                        @error('min_level')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="max_level">Maximum level <span class="text-danger">*</span></label>
                        <input class="form-control erp-num @error('max_level') is-invalid @enderror" type="number" step="0.0001" min="0"
                               id="max_level" name="max_level" value="{{ old('max_level', 0) }}" required>
                        <div class="erp-help">Zero means "no ceiling" — the overstock alert is then never raised for this row.</div>
                        @error('max_level')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="reorder_point">Reorder point <span class="text-danger">*</span></label>
                        <input class="form-control erp-num @error('reorder_point') is-invalid @enderror" type="number" step="0.0001" min="0"
                               id="reorder_point" name="reorder_point" value="{{ old('reorder_point', 0) }}" required>
                        <div class="erp-help">Order when stock falls to this figure — at or below the minimum is already late.</div>
                        @error('reorder_point')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="safety_stock">Safety stock <span class="text-danger">*</span></label>
                        <input class="form-control erp-num @error('safety_stock') is-invalid @enderror" type="number" step="0.0001" min="0"
                               id="safety_stock" name="safety_stock" value="{{ old('safety_stock', 0) }}" required>
                        <div class="erp-help">Buffer for lead-time wobble; cannot exceed the reorder point.</div>
                        @error('safety_stock')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="reorder_qty">Reorder quantity <span class="text-danger">*</span></label>
                        <input class="form-control erp-num @error('reorder_qty') is-invalid @enderror" type="number" step="0.0001" min="0"
                               id="reorder_qty" name="reorder_qty" value="{{ old('reorder_qty', 0) }}" required>
                        <div class="erp-help">What the alert suggests ordering; zero means "enough to reach the maximum".</div>
                        @error('reorder_qty')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="lead_time_days">Lead time (days) <span class="text-danger">*</span></label>
                        <input class="form-control erp-num @error('lead_time_days') is-invalid @enderror" type="number" step="1" min="0" max="365"
                               id="lead_time_days" name="lead_time_days" value="{{ old('lead_time_days', 0) }}" required>
                        @error('lead_time_days')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="erp-form-actions mt-2">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Save levels
                    </button>
                </div>
            </form>
        </div>
    @endif

    <x-ui.table-shell :count="$policies->count().' policy rows'">
        <thead>
            <tr>
                <th>Product</th>
                <th>Applies to</th>
                <th class="erp-th-num">Min</th>
                <th class="erp-th-num">Reorder point</th>
                <th class="erp-th-num">Max</th>
                <th class="erp-th-num">Safety</th>
                <th class="erp-th-num">Order qty</th>
                <th class="erp-th-num">Lead time</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($policies as $policy)
                <tr>
                    <td data-label="Product">
                        <span class="erp-cell-strong">{{ $policy->product?->name ?? 'Removed product' }}</span>
                        <span class="d-block erp-td-muted">{{ $policy->product?->sku }}</span>
                    </td>
                    <td data-label="Applies to" class="erp-td-muted">
                        {{ $policy->warehouse?->name ?? 'Every warehouse' }}
                    </td>
                    <td data-label="Min" class="erp-td-num">{{ number_format((float) $policy->min_level, 4) }}</td>
                    <td data-label="Reorder point" class="erp-td-num">{{ number_format((float) $policy->reorder_point, 4) }}</td>
                    <td data-label="Max" class="erp-td-num">{{ number_format((float) $policy->max_level, 4) }}</td>
                    <td data-label="Safety" class="erp-td-num erp-td-muted">{{ number_format((float) $policy->safety_stock, 4) }}</td>
                    <td data-label="Order qty" class="erp-td-num">{{ number_format((float) $policy->reorder_qty, 4) }}</td>
                    <td data-label="Lead time" class="erp-td-num erp-td-muted">{{ (int) $policy->lead_time_days }} d</td>
                    <td data-label="Status"><x-ui.status :value="$policy->is_active ? 'active' : 'inactive'" /></td>
                    <td data-label="" class="erp-td-actions">
                        <div class="d-flex gap-1 justify-content-end">
                            @if ($perm('inventory.reorder.view'))
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="{{ route('inventory.stock.alerts', array_filter(['type' => 'low', 'warehouse' => $policy->warehouse_id, 'q' => $policy->product?->sku])) }}">
                                    Alerts
                                </a>
                            @endif
                            @if ($perm('inventory.reorder.configure'))
                                <form method="POST" action="{{ route('inventory.reorder.destroy', $policy->id) }}"
                                      onsubmit="return confirm('Remove this policy? Alerts stop for this product in this warehouse.');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Remove policy">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10">
                        <x-ui.empty icon="bi-sliders" title="No reorder policy yet"
                                    text="Until a product has minimum and maximum levels, no alert can be raised for it — that is deliberate: an alert needs a stated threshold to mean anything." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="erp-help mt-2">
        Thresholds are checked together: minimum ≤ reorder point ≤ maximum, and safety stock ≤ reorder point. A row that
        breaks that order is refused with the numbers, because a contradictory policy produces alerts nobody can act on.
    </div>

    <x-ui.related-pages />
@endsection
