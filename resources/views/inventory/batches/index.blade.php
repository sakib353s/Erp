@extends('layouts.app')

@section('page_title', 'Batch register')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Batch & Serial"
        title="Batch Register"
        subtitle="Every batch this company has received, with the date on its label and how much of it is still on a shelf. The quantity and value come from the valuation layers — a batch is a label on goods the ledger moved, never a second balance sheet."
        :pin="true">
        <x-slot:actions>
            @if ($perm('inventory.batch.view'))
                <a class="btn btn-primary" href="{{ route('inventory.batches.expiry') }}">
                    <i class="bi bi-calendar-x" aria-hidden="true"></i> Expiry desk
                </a>
            @endif
            @if ($perm('inventory.ledger.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.movements') }}">
                    <i class="bi bi-journal-text" aria-hidden="true"></i> Movement ledger
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Batches in the register" :value="number_format($batches->total())" icon="bi-upc-scan"
                  hint="Matching the filter below" />
        <x-ui.kpi label="Expired with stock" :value="number_format($buckets['expired']['batches'])" icon="bi-calendar-x"
                  hint="{{ $buckets['expired']['batches'] > 0 ? number_format($buckets['expired']['value'], 2).' of stock still on hand' : 'Nothing past its date' }}"
                  :href="route('inventory.batches.expiry', ['type' => 'expired', 'days' => $withinDays])" />
        <x-ui.kpi label="Expiring within {{ $withinDays }} days" :value="number_format($buckets['expiring']['batches'])" icon="bi-hourglass-split"
                  hint="{{ $buckets['expiring']['batches'] > 0 ? number_format($buckets['expiring']['value'], 2).' to move first' : 'Nothing close to its date' }}"
                  :href="route('inventory.batches.expiry', ['type' => 'expiring', 'days' => $withinDays])" />
        <x-ui.kpi label="No date recorded" :value="number_format($buckets['undated']['batches'])" icon="bi-question-circle"
                  hint="A date nobody wrote down cannot warn anybody"
                  :href="route('inventory.batches.expiry', ['type' => 'undated', 'days' => $withinDays])" />
    </div>

    @error('expires_on') <div class="alert alert-danger">{{ $message }}</div> @enderror

    @if ($buckets['expired']['batches'] > 0)
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-calendar-x" aria-hidden="true"></i>
            <div>
                <strong>{{ $buckets['expired']['batches'] }} batch(es) are past their expiry date and still hold
                    {{ number_format($buckets['expired']['qty'], 4) }} units ({{ number_format($buckets['expired']['value'], 2) }}).</strong>
                Expired stock is not sellable but it is still on the books — decide what happens to it on the
                <a href="{{ route('inventory.batches.expiry', ['type' => 'expired']) }}">expiry desk</a>, and write it
                off from the damage &amp; loss screens if that is the decision.
            </div>
        </div>
    @endif

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.batches.index') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="Batch number, SKU or product name…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="state">Date state</label>
            <select class="form-select" id="state" name="state">
                <option value="">Everything</option>
                @foreach (['expired', 'expiring', 'ok', 'undated'] as $key)
                    <option value="{{ $key }}" @selected($filters['state'] === $key)>{{ $states[$key] }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="product_id">Product</label>
            <select class="form-select" id="product_id" name="product_id">
                <option value="">All products</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}" @selected($filters['product_id'] === $product->id)>
                        {{ $product->sku }} — {{ $product->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="warehouse_id">Warehouse</label>
            <select class="form-select" id="warehouse_id" name="warehouse_id">
                <option value="">All warehouses</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected($filters['warehouse_id'] === $warehouse->id)>{{ $warehouse->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="stocked_only">Show</label>
            <div class="form-check form-switch mt-1">
                <input type="hidden" name="stocked_only" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="stocked_only" name="stocked_only"
                       value="1" @checked($filters['only_stocked'])>
                <label class="form-check-label" for="stocked_only">Only batches with stock left</label>
            </div>
        </div>
        <div class="erp-filterbar-actions">
            @if ($filters['q'] !== '' || $filters['state'] || $filters['product_id'] || $filters['warehouse_id'] || ! $filters['only_stocked'])
                <a class="btn btn-link" href="{{ route('inventory.batches.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$batches->total().' batch'.($batches->total() === 1 ? '' : 'es')">
        <thead>
            <tr>
                <th>Batch</th>
                <th>Product</th>
                <th>Warehouse</th>
                <th>Expires</th>
                <th class="erp-th-num">On hand</th>
                <th class="erp-th-num">Value</th>
                <th>State</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($batches as $batch)
                @php($days = $batch->daysToExpiry())
                <tr>
                    <td data-label="Batch">
                        <span class="erp-cell-strong"><code>{{ $batch->batch_no }}</code></span>
                        @if ($batch->manufactured_on)
                            <span class="d-block erp-td-muted small">made {{ $batch->manufactured_on->format('d M Y') }}</span>
                        @endif
                        @php($change = $batch->expiryChanges->first())
                        @if ($change)
                            <span class="d-block erp-td-muted small" title="{{ $change->reason }}">
                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                date corrected by {{ $change->actor?->name ?? 'a user' }}
                            </span>
                        @endif
                    </td>
                    <td data-label="Product">
                        {{ $batch->product?->name }}
                        <span class="d-block erp-td-muted small"><code>{{ $batch->product?->sku }}</code></span>
                    </td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $batch->warehouse?->name }}</td>
                    <td data-label="Expires">
                        @if ($batch->expires_on)
                            {{ $batch->expires_on->format('d M Y') }}
                            <span class="d-block erp-td-muted small">
                                @if ($days < 0)
                                    {{ number_format(abs($days)) }} day(s) ago
                                @elseif ($days === 0)
                                    today
                                @else
                                    in {{ number_format($days) }} day(s)
                                @endif
                            </span>
                        @else
                            <span class="erp-td-muted">Not recorded</span>
                        @endif
                    </td>
                    <td data-label="On hand" class="erp-td-num">{{ number_format((float) $batch->remaining_qty, 4) }}</td>
                    <td data-label="Value" class="erp-td-num">{{ number_format((float) $batch->remaining_value, 2) }}</td>
                    <td data-label="State">
                        <x-ui.status :value="$batch->state" :label="$batch->stateLabel($withinDays)" />
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty icon="bi-upc-scan" title="No batch matches this filter"
                            text="Batches appear here as goods are received: the receipt line names the batch number, and the register keeps the batch, its manufactured date and the date it goes off. Turn off the stock filter to see batches that have already been fully consumed."
                            action="Open the expiry desk" :href="route('inventory.batches.expiry')" />
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if ($batches->isNotEmpty())
            <x-slot:footer>
                <span class="erp-td-muted">Page totals:
                    {{ number_format((float) $batches->getCollection()->sum('remaining_qty'), 4) }} units ·
                    {{ number_format((float) $batches->getCollection()->sum('remaining_value'), 2) }} of stock</span>
                <span class="erp-td-muted">Dates are corrected on the expiry desk, and every correction keeps its reason.</span>
            </x-slot:footer>
        @endif
    </x-ui.table-shell>

    <div class="mt-3">{{ $batches->links() }}</div>
@endsection
