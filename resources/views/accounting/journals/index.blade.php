@extends('layouts.app')

@section('page_title', 'Journal Entries')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Journal Entries</h1>
            <p class="erp-page-sub">Posted entries are immutable — corrections via reversal only.</p>
        </div>
        @if ($perm('accounting.journals.create'))
            <a class="btn btn-primary" href="{{ route('accounting.journals.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Manual journal
            </a>
        @endif
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" value="{{ $q }}" placeholder="Entry no or description">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="state">State</label>
                <select class="form-select" id="state" name="state">
                    <option value="">All</option>
                    <option value="posted" @selected($state === 'posted')>Posted</option>
                    <option value="reversed" @selected($state === 'reversed')>Reversed</option>
                    <option value="draft" @selected($state === 'draft')>Draft</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="type">Type</label>
                <select class="form-select" id="type" name="type">
                    <option value="">All</option>
                    @foreach (['manual', 'auto', 'opening', 'closing', 'reversal'] as $t)
                        <option value="{{ $t }}" @selected($type === $t)>{{ ucfirst($t) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" type="submit">Filter</button>
            </div>
        </form>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Entry No</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                        <th>State</th>
                        <th>Period</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <td>
                                <a class="fw-semibold text-decoration-none" href="{{ route('accounting.journals.show', $entry) }}">
                                    {{ $entry->entry_no }}
                                </a>
                            </td>
                            <td>{{ $entry->entry_date->format('d M Y') }}</td>
                            <td><span class="erp-chip erp-chip-soft">{{ $entry->journal_type }}</span></td>
                            <td>{{ \Illuminate\Support\Str::limit($entry->description, 60) }}</td>
                            <td class="text-end">{{ number_format((float) $entry->total_debit, 2) }}</td>
                            <td class="text-end">{{ number_format((float) $entry->total_credit, 2) }}</td>
                            <td>
                                <span class="erp-status {{
                                    $entry->posting_state === 'posted' ? 'erp-status-active'
                                    : ($entry->posting_state === 'reversed' ? 'erp-status-disabled' : 'erp-status-pending')
                                }}">{{ $entry->posting_state }}</span>
                            </td>
                            <td class="small">{{ $entry->fiscalPeriod?->code }}</td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-light" href="{{ route('accounting.journals.show', $entry) }}">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center py-4 text-body-secondary">
                                No journal entries yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $entries->links() }}
        </div>
    </div>
@endsection
