@extends('layouts.app')

@section('page_title', 'Ledger Reconciliation')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Ledger Reconciliation</h1>
            <p class="erp-page-sub">Every posted entry must satisfy Σdebits = Σcredits (DOUBLE-ENTRY-01).</p>
        </div>
        <div class="d-flex gap-2">
            <form method="POST" action="{{ route('accounting.reports.rebuild') }}">
                @csrf
                <button class="btn btn-outline-secondary" type="submit">Rebuild running balances</button>
            </form>
            <a class="btn btn-outline-secondary" href="{{ route('accounting.reports.trial-balance') }}">Trial balance</a>
        </div>
    </div>

    <div class="erp-card mb-3">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="small text-body-secondary">Entries checked</div>
                <div class="fs-4 fw-semibold">{{ $result['entries_checked'] }}</div>
            </div>
            <div class="col-md-3">
                <div class="small text-body-secondary">Total debits</div>
                <div class="fs-4 fw-semibold">{{ number_format((float) $result['total_debit'], 2) }}</div>
            </div>
            <div class="col-md-3">
                <div class="small text-body-secondary">Total credits</div>
                <div class="fs-4 fw-semibold">{{ number_format((float) $result['total_credit'], 2) }}</div>
            </div>
            <div class="col-md-3">
                <div class="small text-body-secondary">Status</div>
                @if ($result['is_balanced'])
                    <span class="fs-5 fw-bold text-success">Balanced ✓</span>
                @else
                    <span class="fs-5 fw-bold text-danger">IMBALANCE</span>
                @endif
            </div>
        </div>
    </div>

    @if ($result['unbalanced_entry_ids'] !== [])
        <div class="alert alert-danger">
            Unbalanced entries:
            @foreach ($result['unbalanced_entry_ids'] as $id)
                <a href="{{ route('accounting.journals.show', $id) }}">#{{ $id }}</a>{{ $loop->last ? '' : ', ' }}
            @endforeach
        </div>
    @endif
@endsection
