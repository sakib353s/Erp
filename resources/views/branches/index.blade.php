@extends('layouts.app')

@section('page_title', 'Branches')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Branches</h1>
            <p class="erp-page-sub">One company, many branches — branch scope is enforced server-side on every query.</p>
        </div>
        @if ($perm('branches.create'))
            <a class="btn btn-primary" href="{{ route('branches.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New branch
            </a>
        @endif
    </div>

    <form class="row g-2 mb-3" method="GET" action="{{ route('branches.index') }}">
        <div class="col-sm-5 col-md-4">
            <input class="form-control" type="search" name="q" value="{{ $q }}" placeholder="Search name or code…" aria-label="Search branches">
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Filter</button>
        </div>
        @if($q)
            <div class="col-auto"><a class="btn btn-link" href="{{ route('branches.index') }}">Reset</a></div>
        @endif
    </form>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>District</th>
                        <th>Users</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($branches as $branch)
                        <tr>
                            <td><code>{{ $branch->code }}</code></td>
                            <td>
                                <a class="fw-semibold text-decoration-none" href="{{ route('branches.show', $branch) }}">{{ $branch->name }}</a>
                                @if($branch->is_default)<span class="erp-chip erp-chip-soft">default</span>@endif
                            </td>
                            <td class="text-body-secondary">{{ $branch->district ?: '—' }}</td>
                            <td>{{ $branch->users_count ?? $branch->users()->count() }}</td>
                            <td>
                                <span class="erp-status {{ $branch->is_active ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $branch->is_active ? 'active' : 'inactive' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-light" href="{{ route('branches.show', $branch) }}">View</a>
                                @if ($perm('branches.update'))
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('branches.edit', $branch) }}">Edit</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center py-4 text-body-secondary">No branches match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $branches->links() }}</div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
