@extends('layouts.app')

@section('page_title', 'Product Cost History')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Products"
        title="Product Cost History"
        subtitle="Every change to what a product record says it costs — who changed it, from what to what, when and why. This is the record's own history: what the stock actually cost on arrival lives in the valuation layers, and the two never overwrite each other."
        :pin="true">
        <x-slot:actions>
            @if ($perm('inventory.products.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.products.index') }}">
                    <i class="bi bi-box-seam" aria-hidden="true"></i> Products
                </a>
            @endif
            @if ($perm('pricing.view'))
                <a class="btn btn-outline-secondary" href="{{ route('pricing.history') }}">
                    <i class="bi bi-tags" aria-hidden="true"></i> Price history
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Products in the catalogue" :value="number_format($totals['products'])" icon="bi-box-seam"
                  hint="Cost changes are per product, per edit" />
        <x-ui.kpi label="Cost changes recorded" :value="number_format($totals['changes'])" icon="bi-clock-history"
                  hint="Append-only — a row is never edited or deleted" />
        <x-ui.kpi label="Changed in the last 30 days" :value="number_format($totals['recent'])" icon="bi-activity"
                  hint="A cost that jumps without a row here was brought in by stock, not typed" />
    </div>

    <div class="erp-note erp-note-info mb-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            <strong>Two answers to "what does it cost".</strong>
            A product's standard cost is a decision on the catalogue row and is recorded here with a reason.
            The cost of the goods actually on a shelf comes from the valuation layers, which a cost-method
            change never rewrites — open a product to see both side by side.
        </div>
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.cost-history') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="SKU, code or product name…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="changed">Cost changes</label>
            <select class="form-select" id="changed" name="changed">
                <option value="any" @selected($filters['changed'] === 'any')>Any</option>
                <option value="30" @selected($filters['changed'] === '30')>Changed in the last 30 days</option>
                <option value="never" @selected($filters['changed'] === 'never')>Never changed</option>
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if ($filters['q'] !== '' || $filters['changed'] !== 'any')
                <a class="btn btn-link" href="{{ route('inventory.cost-history') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$products->total().' product'.($products->total() === 1 ? '' : 's')">
        <thead>
            <tr>
                <th>Product</th>
                <th>Category</th>
                <th class="erp-th-num">Standard cost</th>
                <th>Cost method</th>
                <th class="erp-th-num">Changes</th>
                <th>Last change</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($products as $product)
                @php($last = $product->last_cost_change_at)
                <tr>
                    <td data-label="Product">
                        <a class="erp-cell-strong text-decoration-none"
                           href="{{ route('inventory.products.cost-history', $product) }}">{{ $product->name }}</a>
                        <span class="d-block erp-td-muted small"><code>{{ $product->sku }}</code></span>
                    </td>
                    <td data-label="Category" class="erp-td-muted">{{ $product->category?->name ?? '—' }}</td>
                    <td data-label="Standard cost" class="erp-td-num">
                        {{ number_format((float) $product->standard_cost, 2) }}
                        @if ($product->unit)
                            <span class="erp-td-muted small d-block">per {{ $product->unit->name }}</span>
                        @endif
                    </td>
                    <td data-label="Cost method">
                        <span class="erp-chip erp-chip-soft">{{ strtoupper($product->cost_method) }}</span>
                    </td>
                    <td data-label="Changes" class="erp-td-num">
                        @if ($product->cost_history_count > 0)
                            {{ number_format($product->cost_history_count) }}
                        @else
                            <span class="erp-td-muted">none</span>
                        @endif
                    </td>
                    <td data-label="Last change">
                        @if ($last)
                            {{ \Illuminate\Support\Carbon::parse($last)->format('d M Y') }}
                            <span class="d-block erp-td-muted small">
                                {{ \Illuminate\Support\Carbon::parse($last)->diffForHumans($today) }}
                            </span>
                        @else
                            <span class="erp-td-muted">Never changed</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-light"
                           href="{{ route('inventory.products.cost-history', $product) }}">History</a>
                        @if ($perm('inventory.products.edit'))
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('inventory.products.edit', $product) }}">Edit</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty icon="bi-clock-history" title="No product matches this filter"
                            text="Cost history starts when somebody changes a product's standard cost or cost method — the reason they type in is kept with the change. Products whose cost was only ever set once, at creation, have nothing to show yet."
                            action="Open the product list" :href="route('inventory.products.index')" />
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if ($products->isNotEmpty())
            <x-slot:footer>
                <span class="erp-td-muted">Page {{ $products->currentPage() }} of {{ $products->lastPage() }}</span>
                <span class="erp-td-muted">A change is recorded only when the cost or the method actually moves.</span>
            </x-slot:footer>
        @endif
    </x-ui.table-shell>

    <div class="mt-3">{{ $products->links() }}</div>
@endsection
