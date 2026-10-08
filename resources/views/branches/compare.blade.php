@php
    /* §12-07 — the branches, side by side, for one month. */
    $totals = $comparison['totals'];
    $rows = $comparison['rows'];

    $share = fn (float $value): float => $totals['sales'] > 0 ? round($value / $totals['sales'] * 100, 1) : 0.0;
    $money = fn (float $value): string => '৳'.number_format($value, 2);
@endphp

<x-ui.page-header
    eyebrow="Business Management · Branches · Comparison"
    title="How the branches compare"
    subtitle="One month, every outlet, the same figures their own screens show. Sales are issued invoices, purchases are posted bills, stock is what the layers say it cost, money is the posted balance of the till and the bank accounts, book value is the asset register and headcount is the employees on the payroll. Nothing here is estimated — a branch with no trade says so instead of showing a zero."
    :pin="true">
    <x-slot:actions>
        <form method="GET" action="{{ route('branches.compare') }}" class="d-flex gap-2 align-items-center">
            <label class="visually-hidden" for="month">Month</label>
            <select class="form-select form-select-sm" id="month" name="month" data-erp-autosubmit>
                @foreach ($months as $option)
                    <option value="{{ $option }}" @selected($option === $comparison['month'])>{{ $option }}</option>
                @endforeach
            </select>
            <noscript><button class="btn btn-sm btn-outline-secondary" type="submit">Show</button></noscript>
        </form>
        <a class="btn btn-outline-secondary" href="{{ route('branches.index') }}">
            <i class="bi bi-diagram-3" aria-hidden="true"></i> All branches
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-note mb-3">
    <i class="bi bi-calendar3" aria-hidden="true"></i>
    Showing <strong>{{ $comparison['from']->format('F Y') }}</strong>
    ({{ $comparison['from']->format('d M') }} – {{ $comparison['to']->format('d M') }}),
    {{ $rows->count() }} branch(es), {{ $comparison['trading'] }} of them with sales in the month.
</div>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Company sales" :value="$money($totals['sales'])" icon="bi-receipt" hero
              :hint="$totals['invoices'].' invoice(s) issued this month'" />
    <x-ui.kpi label="Still receivable" :value="$money($totals['receivable'])" icon="bi-hourglass-split"
              hint="Issued and not yet settled, across every branch" />
    <x-ui.kpi label="Purchases" :value="$money($totals['purchases'])" icon="bi-truck"
              hint="Posted supplier bills — drafts are not counted" />
    <x-ui.kpi label="Stock at cost" :value="$money($totals['stock'])" icon="bi-box-seam"
              hint="What is on the shelves right now, layers × unit cost" />
    <x-ui.kpi label="Money on hand" :value="$money($totals['money'])" icon="bi-cash-stack"
              hint="Cash, bank and wallet balances from posted entries" />
    <x-ui.kpi label="Book value" :value="$money($totals['book'])" icon="bi-hdd-stack"
              hint="Assets at cost less depreciation charged" />
    <x-ui.kpi label="People" :value="$totals['people']" icon="bi-people"
              hint="Active employees on the payroll" />
    <x-ui.kpi label="Open work" :value="$totals['tasks']" icon="bi-list-check"
              hint="Tasks not done or cancelled" />
</div>

@if ($rows->isEmpty())
    <x-ui.empty
        icon="bi-diagram-3"
        title="Nothing to compare yet"
        text="This company has one branch, or only one is inside your scope. A comparison needs at least two." />
@else
    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Branch</th>
                        <th scope="col" class="text-end">Sales</th>
                        <th scope="col" class="text-end">Share</th>
                        <th scope="col" class="text-end">Invoices</th>
                        <th scope="col" class="text-end">Receivable</th>
                        <th scope="col" class="text-end">Purchases</th>
                        <th scope="col" class="text-end">Stock</th>
                        <th scope="col" class="text-end">Money</th>
                        <th scope="col" class="text-end">Book value</th>
                        <th scope="col" class="text-end">People</th>
                        <th scope="col" class="text-end">Open work</th>
                        <th scope="col" class="text-end">Transfer</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php($branch = $row['branch'])
                        <tr>
                            <td>
                                <a class="fw-semibold text-decoration-none" href="{{ route('branches.show', $branch) }}">{{ $branch->name }}</a>
                                <div class="text-body-secondary small">
                                    <code>{{ $branch->code }}</code>
                                    @if ($branch->is_default)<span class="erp-chip erp-chip-soft ms-1">default</span>@endif
                                    @if (! $branch->is_active)<span class="erp-status erp-status-disabled ms-1">inactive</span>@endif
                                </div>
                                @if ($row['dormant'])
                                    <div class="text-body-secondary small fst-italic">No trade and no open work this month.</div>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">{{ $money($row['sales']) }}</td>
                            <td class="text-end">
                                @if ($totals['sales'] > 0)
                                    <span class="erp-chip erp-chip-outline">{{ $share($row['sales']) }}%</span>
                                @else
                                    <span class="text-body-secondary">—</span>
                                @endif
                            </td>
                            <td class="text-end">{{ $row['invoices'] }}</td>
                            <td class="text-end">{{ $money($row['receivable']) }}</td>
                            <td class="text-end">{{ $money($row['purchases']) }}</td>
                            <td class="text-end">{{ $money($row['stock']) }}</td>
                            <td class="text-end {{ $row['money'] < 0 ? 'text-danger' : '' }}">{{ $money($row['money']) }}</td>
                            <td class="text-end">{{ $money($row['book']) }}</td>
                            <td class="text-end">{{ $row['people'] }}</td>
                            <td class="text-end">{{ $row['tasks'] }}</td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-light" href="{{ route('branches.transfer', $branch) }}">
                                    <i class="bi bi-arrow-left-right" aria-hidden="true"></i> Desk
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-semibold">
                        <td>Company</td>
                        <td class="text-end">{{ $money($totals['sales']) }}</td>
                        <td class="text-end">100%</td>
                        <td class="text-end">{{ $totals['invoices'] }}</td>
                        <td class="text-end">{{ $money($totals['receivable']) }}</td>
                        <td class="text-end">{{ $money($totals['purchases']) }}</td>
                        <td class="text-end">{{ $money($totals['stock']) }}</td>
                        <td class="text-end">{{ $money($totals['money']) }}</td>
                        <td class="text-end">{{ $money($totals['book']) }}</td>
                        <td class="text-end">{{ $totals['people'] }}</td>
                        <td class="text-end">{{ $totals['tasks'] }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <p class="text-body-secondary small mt-3 mb-0">
        Stock and money are balances <em>today</em>, not totals for the month: a shelf and a bank
        balance are facts about now, and a month's movement would answer a different question.
        Sales and purchases are the month's own.
    </p>
@endif

<x-ui.related-pages />
