@extends('layouts.app')

@section('page_title', 'Stock counts')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Stock"
        title="Stock counts"
        subtitle="A count is how the system finds out it was wrong. Opening a sheet freezes the numbers the counter is asked about; posting the sheet makes the counted figure the truth and writes the difference into stock as an adjustment."
        :pin="true">
        <x-slot:actions>
            @if ($perm('inventory.counts.create'))
                <a class="btn btn-primary" href="{{ route('inventory.counts.create') }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Open a count sheet
                </a>
                <a class="btn btn-outline-secondary" href="{{ route('inventory.counts.create', ['scope' => 'cycle']) }}">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Cycle count
                </a>
            @endif
            @if ($perm('inventory.adjustments.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.adjustments.index') }}">
                    <i class="bi bi-sliders" aria-hidden="true"></i> Adjustments
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @php($a = $analytics)

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Sheets still counting" :value="number_format($a['open'])" icon="bi-clipboard-check"
                  hint="Open against a warehouse right now" />
        <x-ui.kpi label="Posted in this period" :value="number_format($a['posted'])" icon="bi-check2-circle"
                  hint="{{ number_format($a['counted_lines']) }} line(s) counted" />
        <x-ui.kpi label="Lines with a difference" :value="number_format($a['variance_lines'])" icon="bi-exclamation-triangle"
                  hint="{{ number_format($a['uncounted_lines']) }} line(s) never reached" />
        <x-ui.kpi label="Warehouse accuracy" icon="bi-bullseye"
                  :value="$a['accuracy'] === null ? '—' : number_format($a['accuracy'] * 100, 1).'%'"
                  hint="Lines that agreed with the ledger, over counted lines" />
        <x-ui.kpi label="Value the counts moved" :value="number_format((float) $a['variance_value'], 2)" icon="bi-currency-exchange"
                  hint="{{ number_format($a['cancelled']) }} sheet(s) cancelled — those corrected nothing" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.counts.index') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Any status</option>
                @foreach ($statuses as $key => $label)
                    <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="scope">Scope</label>
            <select class="form-select" id="scope" name="scope">
                <option value="">Any scope</option>
                <option value="full" @selected($filters['scope'] === 'full')>Full count</option>
                <option value="cycle" @selected($filters['scope'] === 'cycle')>Cycle count</option>
            </select>
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
        <div class="erp-filter">
            <label class="form-label" for="from">From</label>
            <input class="form-control" type="date" id="from" name="from" value="{{ $filters['from'] }}">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="to">To</label>
            <input class="form-control" type="date" id="to" name="to" value="{{ $filters['to'] }}">
        </div>
        <div class="erp-filterbar-actions">
            @if (array_filter($filters))
                <a class="btn btn-link" href="{{ route('inventory.counts.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$counts->total().' sheet'.($counts->total() === 1 ? '' : 's')">
        <thead>
            <tr>
                <th>Sheet</th>
                <th>Date</th>
                <th>Warehouse</th>
                <th>Scope</th>
                <th class="erp-th-num">Lines</th>
                <th class="erp-th-num">Counted</th>
                <th class="erp-th-num">With a difference</th>
                <th class="erp-th-num">Value moved</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($counts as $count)
                <tr>
                    <td data-label="Sheet">
                        <a href="{{ route('inventory.counts.show', $count) }}" class="erp-cell-strong"><code>{{ $count->code }}</code></a>
                        <span class="d-block erp-td-muted small">
                            Opened by {{ $count->creator?->name ?? '—' }}
                            @if ($count->poster) · posted by {{ $count->poster->name }} @endif
                        </span>
                    </td>
                    <td data-label="Date">{{ $count->count_date?->format('d M Y') }}</td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $count->warehouse?->name }}</td>
                    <td data-label="Scope">{{ $count->scope === \App\Domain\Inventory\StockCount::SCOPE_CYCLE ? 'Cycle' : 'Full' }}</td>
                    <td data-label="Lines" class="erp-td-num">{{ number_format($count->line_count) }}</td>
                    <td data-label="Counted" class="erp-td-num">{{ number_format($count->counted_lines) }}</td>
                    <td data-label="With a difference" class="erp-td-num">{{ number_format($count->variance_lines) }}</td>
                    <td data-label="Value moved" class="erp-td-num">{{ $count->isPosted() ? number_format((float) $count->variance_value, 2) : '—' }}</td>
                    <td data-label="Status"><x-ui.status :value="$count->status" /></td>
                    <td data-label="" class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('inventory.counts.show', $count) }}">
                            {{ $count->isOpen() ? 'Count' : 'Open' }}
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10">
                        <x-ui.empty icon="bi-clipboard-check" title="No count sheet here"
                            text="Opening a sheet freezes the balance each product shows today, so the counter's figures can be compared against something that does not move. Posting the sheet writes the difference into stock as an adjustment." />
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if ($counts->getCollection()->isNotEmpty())
            <tfoot>
                <tr>
                    <th colspan="4">Page total of {{ number_format($counts->total()) }} sheet(s)</th>
                    <th class="erp-th-num">{{ number_format($counts->getCollection()->sum('line_count')) }}</th>
                    <th class="erp-th-num">{{ number_format($counts->getCollection()->sum('counted_lines')) }}</th>
                    <th class="erp-th-num">{{ number_format($counts->getCollection()->sum('variance_lines')) }}</th>
                    <th class="erp-th-num">{{ number_format((float) $counts->getCollection()->sum('variance_value'), 2) }}</th>
                    <th colspan="2"></th>
                </tr>
            </tfoot>
        @endif
    </x-ui.table-shell>

    <div class="mt-3">{{ $counts->links() }}</div>

    @if ($a['by_warehouse']->isNotEmpty())
        <x-ui.table-shell class="mt-3" title="Posted counts by warehouse" :count="$a['by_warehouse']->count().' warehouse(s)'">
            <thead>
                <tr>
                    <th>Warehouse</th>
                    <th class="erp-th-num">Sheets posted</th>
                    <th class="erp-th-num">Lines counted</th>
                    <th class="erp-th-num">Value moved</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($a['by_warehouse'] as $row)
                    <tr>
                        <td data-label="Warehouse">{{ $row['warehouse']?->name ?? '—' }}</td>
                        <td data-label="Sheets posted" class="erp-td-num">{{ number_format($row['sessions']) }}</td>
                        <td data-label="Lines counted" class="erp-td-num">{{ number_format($row['lines']) }}</td>
                        <td data-label="Value moved" class="erp-td-num">{{ number_format($row['value'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table-shell>
    @endif

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
