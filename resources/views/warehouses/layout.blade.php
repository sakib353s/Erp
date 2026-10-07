@extends('layouts.app')

@section('page_title', $layout['warehouse']->name)

@section('content')
    @php($warehouse = $layout['warehouse'])
    @php($totals = $layout['totals'])
    @php($canEdit = $perm('warehouses.update'))

    <x-ui.page-header
        eyebrow="Inventory · Warehouse"
        :title="$warehouse->name"
        :subtitle="trim(($warehouse->branch?->name ?? 'No branch').' · '.($warehouse->address ?? 'No address recorded'))"
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('warehouses.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All warehouses
            </a>
            @if ($canEdit)
                <a class="btn btn-outline-secondary" href="{{ route('warehouses.edit', $warehouse) }}">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                </a>
            @endif
            @if ($perm('inventory.stock.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.stock', ['warehouse' => $warehouse->id]) }}">
                    <i class="bi bi-boxes" aria-hidden="true"></i> Stock here
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Zones" :value="number_format($totals['zones'])" icon="bi-grid-3x3-gap"
                  hint="Receiving, storage, picking, dispatch…" />
        <x-ui.kpi label="Bins" :value="number_format($totals['bins'])" icon="bi-box-seam"
                  hint="{{ number_format($totals['pickable']) }} pickable and active" />
        <x-ui.kpi label="Products placed" :value="number_format($totals['products'])" icon="bi-upc-scan"
                  hint="{{ number_format($totals['assignments']) }} assignment(s) across the bins" />
        <x-ui.kpi label="Bins without a home" :value="number_format($layout['unzoned_bins'])" icon="bi-question-circle"
                  hint="Should be zero — every bin belongs to a zone" />
    </div>

    <div class="erp-note erp-note-info mb-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            <strong>A bin is a place, not a balance.</strong> Quantities stay in the ledger per product per warehouse;
            the layout tells a picker where to go and never claims how much is there. That keeps one truth for stock
            and one for geography.
        </div>
    </div>

    @error('zone') <div class="alert alert-danger">{{ $message }}</div> @enderror
    @error('bin') <div class="alert alert-danger">{{ $message }}</div> @enderror
    @error('product_id') <div class="alert alert-danger">{{ $message }}</div> @enderror
    @error('bin_id') <div class="alert alert-danger">{{ $message }}</div> @enderror

    @if ($canEdit)
        <form method="POST" action="{{ route('warehouses.zones.store', $warehouse) }}" class="erp-card mb-3">
            @csrf
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label" for="zone_code">Zone code</label>
                    <input class="form-control text-uppercase" id="zone_code" name="code" required maxlength="32"
                           value="{{ old('code') }}" placeholder="A">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="zone_name">Name</label>
                    <input class="form-control" id="zone_name" name="name" maxlength="191" value="{{ old('name') }}"
                           placeholder="Rack area A">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="zone_type">Type</label>
                    <select class="form-select" id="zone_type" name="type" required>
                        @foreach ($zoneTypes as $key => $label)
                            <option value="{{ $key }}" @selected(old('type') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="sort_order">Order</label>
                    <input class="form-control" type="number" min="0" max="9999" id="sort_order" name="sort_order"
                           value="{{ old('sort_order', 0) }}">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add zone
                    </button>
                </div>
            </div>
        </form>
    @endif

    @forelse ($layout['zones'] as $zone)
        <x-ui.table-shell class="mb-3"
                          :title="$zone->code.' · '.$zone->name.' · '.$zone->typeLabel()"
                          :count="$zone->bins->count().' bin(s)'">
            <x-slot:tools>
                <div class="d-flex gap-2 align-items-center">
                    @unless ($zone->is_active)
                        <span class="erp-chip erp-chip-warn">Inactive</span>
                    @endunless
                    @if ($canEdit)
                        <form method="POST" action="{{ route('warehouses.zones.destroy', $zone) }}"
                              data-confirm="Remove zone {{ $zone->code }}? Only an empty zone can go.">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" type="submit">Remove zone</button>
                        </form>
                    @endif
                </div>
            </x-slot:tools>

            <thead>
                <tr>
                    <th>Bin</th>
                    <th>Picking</th>
                    <th>Products picked from here</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($zone->bins as $bin)
                    <tr>
                        <td data-label="Bin">
                            <span class="erp-cell-strong"><code>{{ $bin->code }}</code></span>
                            @if ($bin->name) <span class="d-block erp-td-muted small">{{ $bin->name }}</span> @endif
                        </td>
                        <td data-label="Picking">
                            @if (! $bin->is_active)
                                <span class="erp-chip erp-chip-warn">Inactive</span>
                            @elseif ($bin->is_pickable)
                                <span class="erp-chip erp-chip-ok">Pickable</span>
                            @else
                                <span class="erp-chip erp-chip-outline">Storage only</span>
                            @endif
                        </td>
                        <td data-label="Products picked from here">
                            @forelse ($bin->assignments as $assignment)
                                <span class="d-inline-flex align-items-center gap-1 me-2">
                                    <span class="erp-chip {{ $assignment->is_primary ? 'erp-chip-soft' : 'erp-chip-outline' }}">
                                        {{ $assignment->product?->sku ?? 'product #'.$assignment->product_id }}
                                        @if ($assignment->is_primary) · primary @endif
                                    </span>
                                    @if ($canEdit)
                                        <form method="POST" action="{{ route('warehouses.bins.unassign', $assignment) }}"
                                              data-confirm="Stop sending pickers to {{ $bin->code }} for this product?">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-link text-danger p-0" type="submit"
                                                    aria-label="Remove assignment">&times;</button>
                                        </form>
                                    @endif
                                </span>
                            @empty
                                <span class="erp-td-muted">Nothing assigned — a picker would have nowhere to go.</span>
                            @endforelse
                        </td>
                        <td data-label="" class="erp-td-actions">
                            @if ($canEdit)
                                <form method="POST" action="{{ route('warehouses.bins.destroy', $bin) }}"
                                      data-confirm="Remove bin {{ $bin->code }}?">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <x-ui.empty icon="bi-box-seam" title="No bins in this zone"
                                text="A zone with no bins cannot be picked from. Add the shelf, rack or floor positions that make it up." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table-shell>

        @if ($canEdit)
            <div class="row g-3 mb-4">
                <div class="col-lg-6">
                    <form method="POST" action="{{ route('warehouses.bins.store', $zone) }}" class="erp-card h-100">
                        @csrf
                        <h3 class="erp-card-title mb-2">Add a bin to {{ $zone->code }}</h3>
                        <div class="row g-2 align-items-end">
                            <div class="col-4">
                                <label class="form-label" for="bin_code_{{ $zone->id }}">Code</label>
                                <input class="form-control text-uppercase" id="bin_code_{{ $zone->id }}" name="code"
                                       required maxlength="32" placeholder="A-01">
                            </div>
                            <div class="col-4">
                                <label class="form-label" for="bin_name_{{ $zone->id }}">Name</label>
                                <input class="form-control" id="bin_name_{{ $zone->id }}" name="name" maxlength="191"
                                       placeholder="Rack A, shelf 1">
                            </div>
                            <div class="col-4">
                                <div class="form-check mb-2">
                                    <input type="hidden" name="is_pickable" value="0">
                                    <input class="form-check-input" type="checkbox" value="1" checked
                                           id="bin_pickable_{{ $zone->id }}" name="is_pickable">
                                    <label class="form-check-label" for="bin_pickable_{{ $zone->id }}">Pickable</label>
                                </div>
                                <button class="btn btn-outline-secondary w-100" type="submit">Add bin</button>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="col-lg-6">
                    @if ($zone->bins->isNotEmpty())
                        <form method="POST" action="{{ route('warehouses.bins.assign', $warehouse) }}"
                              class="erp-card h-100" id="assign-{{ $zone->id }}">
                            @csrf
                            <h3 class="erp-card-title mb-2">Send a product to a bin</h3>
                            <div class="row g-2 align-items-end">
                                <div class="col-4">
                                    <label class="form-label" for="assign_bin_{{ $zone->id }}">Bin</label>
                                    <select class="form-select" id="assign_bin_{{ $zone->id }}" name="bin_id">
                                        @foreach ($zone->bins as $bin)
                                            <option value="{{ $bin->id }}">{{ $bin->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-4">
                                    <label class="form-label" for="assign_product_{{ $zone->id }}">Product</label>
                                    <select class="form-select" id="assign_product_{{ $zone->id }}" name="product_id" required>
                                        <option value="">— select —</option>
                                        @foreach ($products as $product)
                                            <option value="{{ $product->id }}">{{ $product->sku }} · {{ $product->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-4">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" value="1" checked
                                               id="assign_primary_{{ $zone->id }}" name="is_primary">
                                        <label class="form-check-label" for="assign_primary_{{ $zone->id }}">Primary pick face</label>
                                    </div>
                                    <button class="btn btn-outline-secondary w-100" type="submit">Assign</button>
                                </div>
                            </div>
                            <div class="form-text">
                                Marking a primary pick face demotes the previous one for that product in this
                                warehouse, so "where do I pick this from?" always has one answer.
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        @endif
    @empty
        <x-ui.empty icon="bi-grid-3x3-gap" title="This warehouse has no zones yet"
            text="Zones are the warehouse's structure — receiving, storage, picking, dispatch. Bins live inside them, and a product is assigned to the bin a picker should be sent to." />
    @endforelse

    @if ($stale->isNotEmpty())
        <x-ui.table-shell class="mt-3" title="Placed here, untouched for a while"
                          :count="$stale->count().' product(s)'">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Bin</th>
                    <th class="erp-th-num">Days since a movement</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($stale as $row)
                    <tr>
                        <td data-label="Product">
                            <span class="erp-cell-strong">{{ $row['product']?->name ?? 'Product removed' }}</span>
                            <span class="d-block erp-td-muted">{{ $row['product']?->sku }}</span>
                        </td>
                        <td data-label="Bin" class="erp-td-muted">{{ $row['bin']?->label() }}</td>
                        <td data-label="Days since a movement" class="erp-td-num">
                            {{ $row['days_idle'] > 9000 ? 'never moved' : number_format($row['days_idle']) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table-shell>
    @endif

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
