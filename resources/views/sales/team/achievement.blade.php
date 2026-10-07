@extends('layouts.app')

@section('page_title', 'Target Achievement')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Target Achievement</h1>
            <p class="erp-page-sub">
                Period {{ $bounds['period_start'] }} → {{ $bounds['period_end'] }}
                · {{ $periodType }}
                · sample size {{ $sampleSize }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.index') }}">Sales persons</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.targets.index') }}">Targets</a>
        </div>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="period_type">Period type</label>
                <select class="form-select" id="period_type" name="period_type">
                    @foreach (['daily', 'monthly', 'yearly'] as $p)
                        <option value="{{ $p }}" @selected($periodType === $p)>{{ $p }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="at">As of</label>
                <input class="form-control" id="at" name="at" type="date" value="{{ $at }}">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" type="submit">Apply</button>
            </div>
        </form>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th class="text-end">Target</th>
                        <th class="text-end">Actual (issued/partial/paid invoices)</th>
                        <th class="text-end">Variance</th>
                        <th class="text-end">%</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>
                                {{ $row['employee']?->full_name ?? '—' }}
                                @if ($row['employee'])
                                    <code class="ms-1">{{ $row['employee']->code }}</code>
                                @endif
                            </td>
                            <td class="text-end">{{ number_format($row['target_amount'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['actual_amount'], 2) }}</td>
                            <td class="text-end {{ $row['variance'] < 0 ? 'text-danger' : 'text-success' }}">
                                {{ number_format($row['variance'], 2) }}
                            </td>
                            <td class="text-end">
                                {{ $row['pct'] !== null ? number_format($row['pct'], 1).'%' : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                No targets for this period. Set targets first — achievement only shows rows with a target (no fake data).
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="small text-muted mb-0 mt-3">
            Actuals = sum of <code>invoices.grand_total</code> where status ∈ issued|partial|paid and
            <code>sales_person_id</code> matches, filtered to the period window. Inputs and sample size shown above.
        </p>
    </div>
@endsection
