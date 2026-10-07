@extends('layouts.app')

@section('page_title', 'Cost History · '.$product->sku)

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Products"
        title="{{ $product->name }}"
        subtitle="What this product record says it costs, and every change somebody made to that figure. Stock on hand is valued from the layers on the right — the record's cost never rewrites them."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.products.ledger', $product) }}">
                <i class="bi bi-journal-text" aria-hidden="true"></i> Stock ledger
            </a>
            @if ($perm('pricing.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.products.price-history', $product) }}">
                    <i class="bi bi-tags" aria-hidden="true"></i> Price history
                </a>
            @endif
            @if ($perm('inventory.products.edit'))
                <a class="btn btn-primary" href="{{ route('inventory.products.edit', $product) }}">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit product
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-meta-row mb-3">
        <span class="erp-chip erp-chip-soft"><i class="bi bi-upc" aria-hidden="true"></i> {{ $product->sku }}</span>
        <span class="erp-chip erp-chip-soft">{{ $product->code }}</span>
        @if ($product->category)
            <span class="erp-chip">{{ $product->category->name }}</span>
        @endif
        @if ($product->brand)
            <span class="erp-chip">{{ $product->brand->name }}</span>
        @endif
        <x-ui.status :value="$product->is_active ? 'active' : 'disabled'" :label="$product->is_active ? 'active' : 'inactive'" />
        @if ($product->track_batch)
            <span class="erp-chip erp-chip-soft">batch-tracked</span>
        @endif
    </div>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Standard cost on the record" :value="number_format((float) $product->standard_cost, 2)"
                  icon="bi-cash-stack" hint="{{ $product->unit?->name ? 'Per '.$product->unit->name : 'Per unit' }}" />
        <x-ui.kpi label="Cost method" :value="strtoupper($product->cost_method)" icon="bi-diagram-3"
                  hint="Drives new valuation layers only — posted layers are never rewritten" />
        <x-ui.kpi label="Changes recorded" :value="number_format($changes)" icon="bi-clock-history"
                  hint="Append-only, each with its reason and author" />
        <x-ui.kpi label="Last change" :value="$lastChange ? $lastChange->changed_at->format('d M Y') : 'Never'"
                  icon="bi-calendar-check"
                  hint="{{ $lastChange ? ($lastChange->actor?->name ?? 'a user').' — '.$lastChange->summary() : 'No cost edit has been recorded' }}" />
    </div>

    <div class="erp-split">
        <section class="erp-card erp-split-main">
            <header class="erp-card-head">
                <h2 class="erp-card-title"><i class="bi bi-clock-history" aria-hidden="true"></i> Cost changes</h2>
                <span class="erp-td-muted">{{ number_format($changes) }} row(s), newest first</span>
            </header>

            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>What changed</th>
                            <th>Reason</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td data-label="When">
                                    {{ $row->changed_at->format('d M Y') }}
                                    <span class="d-block erp-td-muted small">{{ $row->changed_at->format('H:i') }}</span>
                                </td>
                                <td data-label="What changed">
                                    <span class="erp-cell-strong">{{ $row->summary() }}</span>
                                    @if ($row->old_cost_method !== $row->new_cost_method)
                                        <span class="d-block erp-td-muted small">
                                            future layers follow {{ strtoupper((string) $row->new_cost_method) }};
                                            layers already posted keep their own cost
                                        </span>
                                    @endif
                                </td>
                                <td data-label="Reason" class="erp-td-muted">
                                    {{ $row->reason ?: '—' }}
                                </td>
                                <td data-label="By">
                                    {{ $row->actor?->name ?? 'System' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <x-ui.empty icon="bi-clock-history" title="No cost change recorded yet"
                                        text="A row is written when an edit moves the standard cost or the cost method — the reason field on the product form travels with it. Until then the only cost on file is the one set when the product was created."
                                        action="Edit the product" :href="route('inventory.products.edit', $product)" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">{{ $rows->links() }}</div>
        </section>

        <aside class="erp-split-side">
            <section class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title"><i class="bi bi-layers" aria-hidden="true"></i> What the stock cost</h2>
                </header>

                @if ($layers->isEmpty())
                    <p class="erp-td-muted mb-0">
                        No valuation layer holds stock for this product right now, so there is nothing the record's
                        cost could be confused with.
                    </p>
                @else
                    <p class="erp-card-sub mb-2">
                        {{ number_format($layerQty, 4) }} units on hand at
                        <strong>{{ number_format($layerValue, 2) }}</strong>, from the layers that received them.
                    </p>

                    <ul class="list-unstyled mb-0">
                        @foreach ($layers->take(6) as $layer)
                            <li class="d-flex justify-content-between gap-2 border-top py-2">
                                <span class="small">
                                    {{ $layer->received_at?->format('d M Y') }}
                                    @if ($layer->batch)
                                        <span class="d-block erp-td-muted">
                                            <code>{{ $layer->batch->batch_no }}</code>
                                            @if ($layer->batch->expires_on)
                                                · expires {{ $layer->batch->expires_on->format('d M Y') }}
                                            @endif
                                        </span>
                                    @endif
                                </span>
                                <span class="text-end small">
                                    {{ number_format((float) $layer->qty_remaining, 4) }} ×
                                    {{ number_format((float) $layer->unit_cost, 2) }}
                                    <span class="d-block erp-cell-strong">
                                        {{ number_format((float) $layer->qty_remaining * (float) $layer->unit_cost, 2) }}
                                    </span>
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($layers->count() > 6)
                        <p class="erp-td-muted small mt-2 mb-0">
                            …and {{ number_format($layers->count() - 6) }} older layer(s) — the ledger shows them all.
                        </p>
                    @endif
                @endif
            </section>

            <div class="erp-note erp-note-info mt-3">
                <i class="bi bi-shield-check" aria-hidden="true"></i>
                <span>
                    Changing the cost method here affects <strong>future</strong> layers only. Layers already posted
                    keep the cost they were received at, so the value of stock on hand never moves because somebody
                    edited a dropdown.
                </span>
            </div>
        </aside>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
