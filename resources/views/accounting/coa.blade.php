@extends('layouts.app')

@section('page_title', 'Chart of Accounts')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Chart of Accounts</h1>
            <p class="erp-page-sub">Hierarchical COA. System accounts are protected; posting history blocks silent reparenting.</p>
        </div>
        @if ($perm('accounting.coa.manage'))
            <a class="btn btn-primary" href="{{ route('accounting.accounts.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add account
            </a>
        @endif
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Flags</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php($account = $row['account'])
                        <tr>
                            <td>
                                <span style="padding-left: {{ $row['depth'] * 1.25 }}rem">
                                    @if ($row['has_children'])
                                        <i class="bi bi-chevron-down small text-body-secondary" aria-hidden="true"></i>
                                    @endif
                                    <code>{{ $account->code }}</code>
                                </span>
                            </td>
                            <td class="{{ $account->is_group ? 'fw-semibold' : '' }}">{{ $account->name }}</td>
                            <td><span class="erp-chip erp-chip-soft">{{ $account->type }}</span></td>
                            <td>
                                <span class="erp-status {{ $account->is_active ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $account->is_active ? 'active' : 'inactive' }}
                                </span>
                            </td>
                            <td class="small text-body-secondary">
                                @if ($account->is_system)<span class="badge text-bg-secondary">system</span>@endif
                                @if ($account->is_group)<span class="badge text-bg-light">group</span>@endif
                                @if ($account->is_control_account)<span class="badge text-bg-info">control</span>@endif
                                @if ($account->is_cash)<span class="badge text-bg-success">cash</span>@endif
                                @if ($account->is_bank)<span class="badge text-bg-primary">bank</span>@endif
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-light" href="{{ route('accounting.ledger', $account) }}">Ledger</a>
                                @if ($perm('accounting.coa.manage'))
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('accounting.accounts.edit', $account) }}">Edit</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4 text-body-secondary">
                                No accounts yet — add your first account or re-run the structural COA seeder.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
