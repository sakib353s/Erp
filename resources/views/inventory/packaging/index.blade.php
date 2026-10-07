@extends('layouts.app')

@section('page_title', 'Packaging types')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Packaging"
        title="What the goods travel in"
        subtitle="A packaging type is not a price list — it is a promise that stock can be taken out of the warehouse under that name. So every type points at a real stock-managed product, its shelf comes from the ledger, and the money comes from the valuation layers that priced it."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.packaging.stock') }}">
                <i class="bi bi-boxes" aria-hidden="true"></i> Packaging stock
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.packaging.cost') }}">
                <i class="bi bi-cash-stack" aria-hidden="true"></i> Packaging cost
            </a>
            @if ($perm('inventory.reports.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.reports.packaging') }}">
                    <i class="bi bi-clipboard-data" aria-hidden="true"></i> Report
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @error('packaging')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Types declared" :value="number_format($totals['types'])" icon="bi-box-seam"
                  hint="Products this company packs with" />
        <x-ui.kpi label="Usable" :value="number_format($totals['active'])" icon="bi-check2-circle"
                  hint="Not retired" />
        <x-ui.kpi label="On the shelf" :value="number_format($totals['on_hand'], 4)" icon="bi-boxes"
                  hint="Summed across warehouses, from the ledger" />
        <x-ui.kpi label="Consumed, 30 days" :value="number_format($totals['consumed_cost'], 2)" icon="bi-cash-stack"
                  hint="Layer-valued cost of what went out" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.packaging.index') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="Code, name or the product behind it…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filterbar-actions">
            @if ($filters['q'] !== '')
                <a class="btn btn-link" href="{{ route('inventory.packaging.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :title="'Declared types'" :count="count($rows).' type(s)'">
        <thead>
            <tr>
                <th>Code</th>
                <th>Packaging</th>
                <th>Stock product</th>
                <th class="erp-th-num">On the shelf</th>
                <th class="erp-th-num">Value now</th>
                <th class="erp-th-num">Avg unit cost</th>
                <th class="erp-th-num">Used, 30 d</th>
                <th>Last used</th>
                <th>State</th>
                <th class="erp-th-actions">Manage</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php($type = $row['type'])
                <tr>
                    <td data-label="Code" class="erp-cell-strong">{{ $type->code }}</td>
                    <td data-label="Packaging">{{ $type->name }}</td>
                    <td data-label="Stock product">
                        <span class="erp-cell-strong">{{ $row['product']?->name ?? '—' }}</span>
                        <span class="d-block erp-td-muted">{{ $row['product']?->sku }}</span>
                    </td>
                    <td data-label="On the shelf" class="erp-td-num">{{ number_format($row['on_hand'], 4) }}</td>
                    <td data-label="Value now" class="erp-td-num">{{ number_format($row['value'], 2) }}</td>
                    <td data-label="Avg unit cost" class="erp-td-num erp-td-muted">
                        {{ $row['avg_cost'] !== null ? number_format($row['avg_cost'], 4) : '—' }}
                    </td>
                    <td data-label="Used, 30 d" class="erp-td-num erp-td-muted">
                        {{ number_format($row['consumed_qty'], 4) }}
                        <span class="d-block erp-td-muted">{{ number_format($row['consumed_cost'], 2) }} cost</span>
                    </td>
                    <td data-label="Last used" class="erp-td-muted">
                        {{ $row['last_consumed_at'] !== null ? \Illuminate\Support\Carbon::parse($row['last_consumed_at'])->format('d M Y') : 'never' }}
                    </td>
                    <td data-label="State">
                        @if (! $type->is_active)
                            <x-ui.status value="inactive" label="Retired" />
                        @elseif (! $row['usable'])
                            <x-ui.status value="broken" label="Cannot be used" />
                        @else
                            <x-ui.status value="active" label="Usable" />
                        @endif
                        @unless ($row['usable'])
                            <span class="d-block erp-td-muted">
                                Its product is no longer stock-managed or active, so it cannot be consumed.
                            </span>
                        @endunless
                    </td>
                    <td data-label="Manage" class="erp-td-actions">
                        @if ($perm('inventory.packaging.manage'))
                            <details class="erp-details">
                                <summary class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                                </summary>
                                <form class="erp-details-body" method="POST"
                                      action="{{ route('inventory.packaging.types.update', $type) }}">
                                    @csrf
                                    @method('PUT')
                                    <label class="form-label" for="code_{{ $type->id }}">Code</label>
                                    <input class="form-control form-control-sm" type="text" maxlength="32"
                                           id="code_{{ $type->id }}" name="code" value="{{ $type->code }}" required>
                                    @error("code")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                    <label class="form-label mt-2" for="name_{{ $type->id }}">Name</label>
                                    <input class="form-control form-control-sm" type="text" maxlength="120"
                                           id="name_{{ $type->id }}" name="name" value="{{ $type->name }}" required>
                                    <label class="form-label mt-2" for="product_{{ $type->id }}">Stock product</label>
                                    <select class="form-select form-select-sm" id="product_{{ $type->id }}" name="product_id" required>
                                        @foreach ($products as $product)
                                            <option value="{{ $product->id }}" @selected($product->id === $type->product_id)>
                                                {{ $product->sku }} — {{ $product->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-sm btn-primary mt-2" type="submit">Save</button>
                                    <p class="erp-help mt-1 mb-0">
                                        Once a type has consumed stock, its code and product are frozen — the consumption
                                        already priced what it took, and renaming it now would rewrite that.
                                    </p>
                                </form>
                            </details>

                            <div class="d-flex gap-1 mt-1 justify-content-end">
                                <form method="POST" action="{{ route('inventory.packaging.types.toggle', $type) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-secondary" type="submit">
                                        {{ $type->is_active ? 'Retire' : 'Bring back' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('inventory.packaging.types.destroy', $type) }}"
                                      data-confirm="Remove {{ $type->code }}? Only a type that has never been used can be removed — otherwise retire it.">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                                </form>
                            </div>
                        @else
                            <span class="erp-td-muted">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10">
                        <x-ui.empty icon="bi-box-seam"
                                    title="No packaging type is declared"
                                    text="A type maps a name your warehouse uses — “Corrugated box, 12 inch” — onto a stock-managed product. Declare one below and it becomes selectable at dispatch, where consuming it takes the stock out and prices the order." />
                    </td>
                </tr>
            @endforelse
        </tbody>
        <x-slot:footer>
            <span class="erp-td-muted">
                Consumption is priced by the valuation layers at the moment it happens — never by a figure typed on the
                type, which would let this register and the ledger disagree about the same box.
            </span>
        </x-slot:footer>
    </x-ui.table-shell>

    @if ($perm('inventory.packaging.manage'))
        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <h2 class="erp-card-title">Declare a packaging type</h2>
                <span class="erp-chip erp-chip-soft">stock-managed products only</span>
            </header>

            <form method="POST" action="{{ route('inventory.packaging.types.store') }}">
                @csrf

                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="new_code">Code</label>
                        <input class="form-control @error('code') is-invalid @enderror" type="text" maxlength="32"
                               id="new_code" name="code" value="{{ old('code') }}" placeholder="BOX-12" required>
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">What the floor calls it. Letters, digits, dot, dash and underscore.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="new_name">Name</label>
                        <input class="form-control @error('name') is-invalid @enderror" type="text" maxlength="120"
                               id="new_name" name="name" value="{{ old('name') }}" placeholder="Corrugated box, 12 inch" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="new_product">Stock product</label>
                        <select class="form-select @error('product_id') is-invalid @enderror" id="new_product" name="product_id" required>
                            <option value="">Choose the product this packaging is…</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}" @selected((int) old('product_id') === $product->id)>
                                    {{ $product->sku }} — {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('product_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            A product cannot be both sold and consumed as packaging without being both — if it is a box,
                            it is packaging.
                        </div>
                    </div>
                </div>

                <button class="btn btn-primary mt-3" type="submit">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Declare type
                </button>
            </form>
        </section>
    @endif

    <x-ui.related-pages />
@endsection
