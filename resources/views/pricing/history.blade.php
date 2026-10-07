@extends('layouts.app')

@section('page_title', $product ? 'Price History · '.$product->sku : 'Price History')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $product ? 'Price History — '.$product->name : 'Price History' }}</h1>
            <p class="erp-page-sub">
                @if ($product)
                    Every price this product has carried, newest first — append-only, never edited in place.
                    The general history across all products stays one click away.
                @else
                    Append-only record of every price change — newest first, never edited in place.
                @endif
            </p>
        </div>
        @if ($product)
            <div class="d-flex gap-2">
                @if ($perm('inventory.products.view'))
                    <a class="btn btn-outline-secondary" href="{{ route('inventory.products.cost-history', $product) }}">
                        <i class="bi bi-clock-history" aria-hidden="true"></i> Cost history
                    </a>
                @endif
                @if ($perm('inventory.products.view'))
                    <a class="btn btn-outline-secondary" href="{{ route('inventory.products.ledger', $product) }}">
                        <i class="bi bi-journal-text" aria-hidden="true"></i> Stock ledger
                    </a>
                @endif
                <a class="btn btn-light" href="{{ route('pricing.history') }}">All products</a>
            </div>
        @elseif ($canBulk)
            <a class="btn btn-primary" href="{{ route('pricing.bulk-update') }}">
                <i class="bi bi-arrow-down-up" aria-hidden="true"></i> Bulk price update
            </a>
        @endif
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            @unless ($product)
                <div class="col-md-3">
                    <label class="form-label" for="q">Product</label>
                    <input class="form-control" id="q" name="q" value="{{ $filters['q'] }}"
                           placeholder="Code or name" maxlength="120">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="price_list_id">Price list</label>
                    <select class="form-select" id="price_list_id" name="price_list_id">
                        <option value="">All lists</option>
                        @foreach ($lists as $list)
                            <option value="{{ $list->id }}" @selected($filters['price_list_id'] === $list->id)>
                                {{ $list->code }} — {{ $list->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @else
                <div class="col-md-3">
                    <label class="form-label">Product</label>
                    <div class="form-control-plaintext">
                        <code>{{ $product->code }}</code> {{ $product->name }}
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="price_list_id">Price list</label>
                    <select class="form-select" id="price_list_id" name="price_list_id">
                        <option value="">All lists</option>
                        @foreach ($lists as $list)
                            <option value="{{ $list->id }}" @selected($filters['price_list_id'] === $list->id)>
                                {{ $list->code }} — {{ $list->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endunless
            <div class="col-md-2">
                <label class="form-label" for="source">Source</label>
                <select class="form-select" id="source" name="source">
                    <option value="">All sources</option>
                    <option value="manual" @selected($filters['source'] === 'manual')>Manual</option>
                    <option value="bulk_update" @selected($filters['source'] === 'bulk_update')>Bulk update</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="from">From</label>
                <input class="form-control" type="date" id="from" name="from" value="{{ $filters['from'] }}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="to">To</label>
                <input class="form-control" type="date" id="to" name="to" value="{{ $filters['to'] }}">
            </div>
            <div class="col-md-1">
                <button class="btn btn-outline-secondary w-100" type="submit">Filter</button>
            </div>
        </form>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Changed at</th>
                        @unless ($product)
                            <th>Product</th>
                        @endunless
                        <th>Price list</th>
                        <th class="text-end">Old price</th>
                        <th class="text-end">New price</th>
                        <th class="text-end">Δ%</th>
                        <th>Source</th>
                        <th>Changed by</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td class="text-nowrap small">{{ $row->created_at->format('Y-m-d H:i') }}</td>
                            @unless ($product)
                                <td>
                                    <code>{{ $row->product?->code ?? '—' }}</code>
                                    <div class="small text-muted">{{ $row->product?->name ?? 'Product removed' }}</div>
                                </td>
                            @endunless
                            <td class="small">{{ $row->priceList?->code ?? '—' }}</td>
                            <td class="text-end">{{ number_format((float) $row->old_price, 2) }}</td>
                            <td class="text-end fw-semibold">{{ number_format((float) $row->new_price, 2) }}</td>
                            <td class="text-end">
                                @if ($row->percent_change !== null)
                                    {{ number_format((float) $row->percent_change, 2) }}%
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="erp-status {{ $row->source === 'bulk_update' ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $row->source === 'bulk_update' ? 'bulk update' : 'manual' }}
                                </span>
                            </td>
                            <td class="small">{{ $row->changedBy?->name ?? 'system' }}</td>
                            <td class="small text-nowrap">
                                @if ($row->price_bulk_update_id !== null)
                                    Batch #{{ $row->price_bulk_update_id }}
                                    @if ($canAudit)
                                        · <a href="{{ route('audit.index', ['action' => 'sales.price_bulk_update', 'entity_type' => 'price_bulk_update']) }}">audit</a>
                                    @endif
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                                @if ($row->note)
                                    <div class="text-muted" title="{{ $row->note }}">{{ \Illuminate\Support\Str::limit($row->note, 60) }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $product ? 8 : 9 }}" class="text-center text-muted py-4">No price changes recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $rows->links() }}</div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
