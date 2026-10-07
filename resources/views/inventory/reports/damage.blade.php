@extends('layouts.app')

@section('page_title', 'Damage & loss analytics')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Reports"
        title="Damage & loss"
        subtitle="What stock damage and loss cost, why, where and when — summed from the documents that exist, with the current damaged compartment valued from the valuation layers."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary"
               href="{{ route('inventory.reports.damage', array_filter(array_merge($filters, ['format' => 'csv']))) }}">
                <i class="bi bi-download" aria-hidden="true"></i> Export CSV
            </a>
            @if ($perm('inventory.stock.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.damage.index') }}">
                    <i class="bi bi-clipboard-x" aria-hidden="true"></i> Damage records
                </a>
                <a class="btn btn-outline-secondary" href="{{ route('inventory.writeoffs.index') }}">
                    <i class="bi bi-file-earmark-x" aria-hidden="true"></i> Write-offs
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.reports.damage') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="from">From</label>
            <input class="form-control" type="date" id="from" name="from" value="{{ $filters['from'] }}">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="to">To</label>
            <input class="form-control" type="date" id="to" name="to" value="{{ $filters['to'] }}">
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
            <label class="form-label" for="kind">Kind</label>
            <select class="form-select" id="kind" name="kind">
                <option value="">Damage and loss</option>
                <option value="damage" @selected($filters['kind'] === 'damage')>Damage only</option>
                <option value="loss" @selected($filters['kind'] === 'loss')>Loss only</option>
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="reason">Cause</label>
            <select class="form-select" id="reason" name="reason">
                <option value="">Any cause</option>
                @foreach ($reasons as $key => $label)
                    <option value="{{ $key }}" @selected($filters['reason'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if (array_filter($filters))
                <a class="btn btn-link" href="{{ route('inventory.reports.damage') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button>
        </div>
    </form>

    @php($t = $analytics['totals'])

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Recorded value" :value="number_format((float) $t['recorded_value'], 2)" icon="bi-cash-stack"
                  hint="{{ $t['entries'] }} entr{{ $t['entries'] === 1 ? 'y' : 'ies' }} in this period" />
        <x-ui.kpi label="Damage recorded" :value="number_format((float) $analytics['by_kind']['damage']['value'], 2)"
                  icon="bi-shield-exclamation" hint="{{ $t['damage_entries'] }} damage entr{{ $t['damage_entries'] === 1 ? 'y' : 'ies' }} — goods kept, still valued" />
        <x-ui.kpi label="Loss recorded" :value="number_format((float) $analytics['by_kind']['loss']['value'], 2)"
                  icon="bi-question-circle" hint="{{ $t['loss_entries'] }} loss entr{{ $t['loss_entries'] === 1 ? 'y' : 'ies' }} — stock and value are gone" />
        <x-ui.kpi label="Written off (approved)" :value="number_format((float) $t['written_off_value'], 2)"
                  icon="bi-file-earmark-x" hint="{{ $t['writeoff_count'] }} approved write-off(s)" />
        <x-ui.kpi label="Held as damaged now" :value="number_format((float) $analytics['held']['damaged'], 4)"
                  icon="bi-box-seam" hint="Valued at {{ number_format((float) $analytics['held']['damaged_value'], 2) }}" />
        <x-ui.kpi label="Held as quarantined now" :value="number_format((float) $analytics['held']['quarantined'], 4)"
                  icon="bi-clipboard-check" hint="Awaiting an inspection decision" />
        <x-ui.kpi label="Write-offs awaiting a decision" :value="number_format((int) $t['pending_writeoffs'])"
                  icon="bi-hourglass-split" hint="Nothing has moved for these yet"
                  :href="$perm('inventory.writeoffs.approve') ? route('inventory.writeoffs.index', ['status' => 'pending_approval']) : null" />
    </div>

    @if ($analytics['by_month']->isNotEmpty())
        @php($peak = max(0.0001, (float) $analytics['by_month']->max('value')))
        <section class="erp-card mb-3">
            <header class="erp-card-head">
                <h2 class="erp-card-title"><i class="bi bi-bar-chart" aria-hidden="true"></i> Value by month</h2>
            </header>
            <div class="erp-widget-series">
                @foreach ($analytics['by_month'] as $month)
                    <div class="erp-widget-bar" title="{{ $month['label'] }}: {{ number_format((float) $month['value'], 2) }} over {{ $month['count'] }} entr{{ $month['count'] === 1 ? 'y' : 'ies' }}">
                        <span style="width: {{ (int) round(((float) $month['value']) / $peak * 100) }}%"></span>
                        <em>{{ $month['label'] }} · {{ number_format((float) $month['value'], 2) }}</em>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <div class="erp-split mb-3">
        <x-ui.table-shell title="By cause" :count="$analytics['by_reason']->count().' cause(s)'">
            <thead>
                <tr>
                    <th>Cause</th>
                    <th>Kind</th>
                    <th class="erp-th-num">Entries</th>
                    <th class="erp-th-num">Value</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($analytics['by_reason'] as $row)
                    <tr>
                        <td data-label="Cause">{{ $row['label'] }}</td>
                        <td data-label="Kind"><x-ui.status :value="$row['kind']" /></td>
                        <td data-label="Entries" class="erp-td-num">{{ $row['count'] }}</td>
                        <td data-label="Value" class="erp-td-num">{{ number_format((float) $row['value'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4"><x-ui.empty icon="bi-pie-chart" title="Nothing recorded in this period"
                                                    text="Damage and loss entries from the register appear here, grouped by the cause recorded on them." /></td></tr>
                @endforelse
            </tbody>
        </x-ui.table-shell>

        <x-ui.table-shell title="By warehouse" :count="$analytics['by_warehouse']->count().' warehouse(s)'">
            <thead>
                <tr>
                    <th>Warehouse</th>
                    <th class="erp-th-num">Entries</th>
                    <th class="erp-th-num">Value</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($analytics['by_warehouse'] as $row)
                    <tr>
                        <td data-label="Warehouse">{{ $row['warehouse']?->name ?? '—' }}</td>
                        <td data-label="Entries" class="erp-td-num">{{ $row['count'] }}</td>
                        <td data-label="Value" class="erp-td-num">{{ number_format((float) $row['value'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3"><x-ui.empty icon="bi-building" title="No warehouse has recorded damage or loss in this period" /></td></tr>
                @endforelse
            </tbody>
        </x-ui.table-shell>
    </div>

    <x-ui.table-shell title="Products affected most" :count="$analytics['top_products']->count().' product(s)'">
        <thead>
            <tr>
                <th>Product</th>
                <th class="erp-th-num">Quantity</th>
                <th class="erp-th-num">Value</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($analytics['top_products'] as $row)
                <tr>
                    <td data-label="Product">
                        <span class="erp-cell-strong">{{ $row['product']?->name ?? 'Product removed' }}</span>
                        <span class="d-block erp-td-muted">{{ $row['product']?->sku }}</span>
                    </td>
                    <td data-label="Quantity" class="erp-td-num">{{ number_format((float) $row['qty'], 4) }}</td>
                    <td data-label="Value" class="erp-td-num">{{ number_format((float) $row['value'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3"><x-ui.empty icon="bi-box" title="No product has been damaged or lost in this period" /></td></tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    @if ($holdings->isNotEmpty())
        <x-ui.table-shell class="mt-3" title="Damaged compartment, right now" :count="$holdings->count().' row(s)'">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Warehouse</th>
                    <th class="erp-th-num">Qty</th>
                    <th class="erp-th-num">Unit cost</th>
                    <th class="erp-th-num">Value</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($holdings as $row)
                    <tr>
                        <td data-label="Product">
                            <span class="erp-cell-strong">{{ $row['product']?->name }}</span>
                            <span class="d-block erp-td-muted">{{ $row['product']?->sku }}</span>
                        </td>
                        <td data-label="Warehouse" class="erp-td-muted">{{ $row['warehouse']?->name }}</td>
                        <td data-label="Qty" class="erp-td-num">{{ number_format($row['qty'], 4) }}</td>
                        <td data-label="Unit cost" class="erp-td-num erp-td-muted">{{ number_format($row['unit_cost'], 4) }}</td>
                        <td data-label="Value" class="erp-td-num">{{ number_format($row['value'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2">Total held as damaged</th>
                    <th class="erp-th-num">{{ number_format($holdings->sum('qty'), 4) }}</th>
                    <th></th>
                    <th class="erp-th-num">{{ number_format($holdings->sum('value'), 2) }}</th>
                </tr>
            </tfoot>
        </x-ui.table-shell>
    @endif

    <x-ui.table-shell class="mt-3" title="Entries in this period" :count="$entries->total().' entr'.($entries->total() === 1 ? 'y' : 'ies')">
        <thead>
            <tr>
                <th>Entry</th>
                <th>Date</th>
                <th>Kind</th>
                <th>Warehouse</th>
                <th>Cause</th>
                <th>Reason</th>
                <th class="erp-th-num">Lines</th>
                <th class="erp-th-num">Value</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td data-label="Entry"><code>{{ $entry->code }}</code></td>
                    <td data-label="Date">{{ $entry->entry_date?->format('d M Y') }}</td>
                    <td data-label="Kind"><x-ui.status :value="$entry->kind" /></td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $entry->warehouse?->name }}</td>
                    <td data-label="Cause">{{ $entry->reasonLabel() }}</td>
                    <td data-label="Reason" class="erp-td-muted">{{ \Illuminate\Support\Str::limit($entry->reason, 50) }}</td>
                    <td data-label="Lines" class="erp-td-num">{{ $entry->lines->count() }}</td>
                    <td data-label="Value" class="erp-td-num">{{ number_format((float) $entry->total_value, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="8"><x-ui.empty icon="bi-journal-text" title="No entry in this window"
                                                text="Widen the dates, or record damage and loss from the registers." /></td></tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">{{ $entries->links() }}</div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
