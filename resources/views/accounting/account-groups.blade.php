@extends('layouts.app')

@section('page_title', 'Account Groups')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Account Groups</h1>
            <p class="erp-page-sub">Structural groups that drive financial-statement mapping.</p>
        </div>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Accounts</th>
                        <th>System</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($groups as $group)
                        <tr>
                            <td><code>{{ $group->code }}</code></td>
                            <td>{{ $group->name }}</td>
                            <td><span class="erp-chip erp-chip-soft">{{ $group->type }}</span></td>
                            <td>{{ $group->accounts_count }}</td>
                            <td>
                                @if ($group->is_system)
                                    <span class="badge text-bg-secondary">system</span>
                                @else
                                    <span class="text-body-secondary">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-4 text-body-secondary">No account groups yet.</td>
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
