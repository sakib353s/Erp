@extends('layouts.app')

@section('page_title', 'General Ledger — '.$account->code)

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">General Ledger</h1>
            <p class="erp-page-sub">
                <code>{{ $account->code }}</code> · {{ $account->name }} ·
                <span class="erp-chip erp-chip-soft">{{ $account->type }}</span>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('accounting.coa') }}">Back to COA</a>
            @if ($perm('accounting.reports.view'))
                <a class="btn btn-outline-secondary" href="{{ route('accounting.reports.trial-balance') }}">Trial balance</a>
            @endif
        </div>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="from">From</label>
                <input type="date" class="form-control" id="from" name="from" value="{{ $from }}">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="to">To</label>
                <input type="date" class="form-control" id="to" name="to" value="{{ $to }}">
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
                        <th>Date</th>
                        <th>Entry</th>
                        <th>Description</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                        <th class="text-end">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ledger['rows'] as $row)
                        <tr class="{{ ($row['is_opening'] ?? false) ? 'table-light' : '' }}">
                            <td>{{ $row['entry_date'] }}</td>
                            <td>
                                @if (! empty($row['journal_entry_id']))
                                    <a href="{{ route('accounting.journals.show', $row['journal_entry_id']) }}">{{ $row['entry_no'] }}</a>
                                @else
                                    <span class="text-body-secondary">{{ $row['label'] }}</span>
                                @endif
                            </td>
                            <td class="small">{{ $row['description'] }}</td>
                            <td class="text-end">
                                @if ($row['debit'] !== null && $row['debit'] !== '0.0000' && $row['debit'] !== '0')
                                    {{ number_format((float) $row['debit'], 2) }}
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($row['credit'] !== null && $row['credit'] !== '0.0000' && $row['credit'] !== '0')
                                    {{ number_format((float) $row['credit'], 2) }}
                                @endif
                            </td>
                            <td class="text-end fw-semibold">{{ number_format((float) $row['running_balance'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3" class="text-end">Total</th>
                        <th class="text-end">{{ number_format((float) $ledger['debit_total'], 2) }}</th>
                        <th class="text-end">{{ number_format((float) $ledger['credit_total'], 2) }}</th>
                        <th class="text-end">{{ number_format((float) $ledger['balance'], 2) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
