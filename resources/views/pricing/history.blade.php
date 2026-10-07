@extends('layouts.app')

@section('page_title', 'Price History')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Price History</h1>
            <p class="erp-page-sub">Append-only record of every price change — newest first, never edited in place.</p>
        </div>
        @if ($canBulk)
            <a class="btn btn-primary" href="{{ route('pricing.bulk-update') }}">
                <i class="bi bi-arrow-down-up" aria-hidden="true"></i> Bulk price update
            </a>
        @endif
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
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
                        <th>Product</th>
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
                            <td>
                                <code>{{ $row->product?->code ?? '—' }}</code>
                                <div class="small text-muted">{{ $row->product?->name ?? 'Product removed' }}</div>
                            </td>
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
                            <td colspan="9" class="text-center text-muted py-4">No price changes recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $rows->links() }}</div>
    </div>
@endsection
