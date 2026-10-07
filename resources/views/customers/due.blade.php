@extends('layouts.app')

@section('page_title', 'Customer dues')

@section('content')
    <x-ui.page-header
        eyebrow="Sales & CRM"
        title="Dues & ageing"
        subtitle="Open invoice balances grouped by how far past the credit date they are. Buckets are exact: 1-30, 31-60, 61-90 and beyond."
        :pin="true">
        <x-slot:actions>
            @if ($perm('customers.export'))
                <a class="btn btn-outline-secondary" href="{{ route('customers.export') }}">
                    <i class="bi bi-download" aria-hidden="true"></i> Export customers
                </a>
            @endif
            <a class="btn btn-light" href="{{ route('customers.index') }}">All customers</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        @foreach ($buckets as $key => $bucket)
            <x-ui.kpi
                :label="$bucket['label']"
                value="৳ {{ number_format($bucket['amount'], 2) }}"
                :hint="$bucket['count'].' invoice'.($bucket['count'] === 1 ? '' : 's')"
                :href="route('customers.due', ['bucket' => $key])" />
        @endforeach
    </div>

    <nav class="erp-segmented mb-3" aria-label="Ageing buckets">
        <a class="{{ $bucket === 'all' ? 'active' : '' }}" href="{{ route('customers.due') }}">All open</a>
        @foreach ($buckets as $key => $item)
            <a class="{{ $bucket === $key ? 'active' : '' }}" href="{{ route('customers.due', ['bucket' => $key]) }}">
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    <x-ui.table-shell :count="$rows->count().' invoices · ৳ '.number_format($total, 2)">
        <thead>
            <tr>
                <th>Invoice</th>
                <th>Customer</th>
                <th>Due date</th>
                <th class="erp-th-num">Days overdue</th>
                <th>Ageing</th>
                <th class="erp-th-num">Total</th>
                <th class="erp-th-num">Paid</th>
                <th class="erp-th-num">Due</th>
                <th class="erp-th-actions">Open</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td data-label="Invoice">{{ $row->invoice_no }}</td>
                    <td data-label="Customer">
                        <a class="erp-row-link" href="{{ route('customers.show', $row->customer_id) }}">{{ $row->customer_name }}</a>
                        <span class="erp-td-muted d-block small">{{ $row->customer_code }}</span>
                    </td>
                    <td data-label="Due date" class="erp-td-muted">{{ $row->due_date ?? '—' }}</td>
                    <td data-label="Days overdue" class="erp-td-num {{ $row->days_overdue > 60 ? 'erp-amount-danger' : ($row->days_overdue > 30 ? 'erp-amount-warn' : '') }}">
                        {{ $row->days_overdue > 0 ? $row->days_overdue : '—' }}
                    </td>
                    <td data-label="Ageing">
                        <span class="erp-status erp-status-{{ $row->bucket === 'current' ? 'issued' : ($row->bucket === '90_plus' ? 'overdue' : 'pending') }}">
                            {{ str_replace('_', '-', $row->bucket) }}
                        </span>
                    </td>
                    <td data-label="Total" class="erp-td-num">৳ {{ number_format((float) $row->grand_total, 2) }}</td>
                    <td data-label="Paid" class="erp-td-num erp-td-muted">৳ {{ number_format((float) $row->paid_amount, 2) }}</td>
                    <td data-label="Due" class="erp-td-num erp-amount">৳ {{ number_format($row->due, 2) }}</td>
                    <td data-label="Open" class="erp-td-actions">
                        @if ($perm('accounting.ledger.view'))
                            <a class="btn btn-sm btn-light" href="{{ route('customers.ledger', $row->customer_id) }}">Ledger</a>
                        @endif
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('customers.show', $row->customer_id) }}">Profile</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="p-0">
                        <x-ui.empty
                            icon="bi-emoji-smile"
                            title="Nothing outstanding in this bucket"
                            text="Either the money came in, or the invoices are not due yet." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
