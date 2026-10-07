@extends('layouts.app')

@section('page_title', 'Users')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Users</h1>
            <p class="erp-page-sub">People with access, their roles and branch scope. You only ever see users inside your own access.</p>
        </div>
        @if ($perm('users.create'))
            <a class="btn btn-primary" href="{{ route('users.create') }}">
                <i class="bi bi-person-plus" aria-hidden="true"></i> New user
            </a>
        @endif
    </div>

    <form class="row g-2 mb-3" method="GET" action="{{ route('users.index') }}">
        <div class="col-sm-5">
            <input class="form-control" type="search" name="q" value="{{ $q }}" placeholder="Search name or e-mail…" aria-label="Search users">
        </div>
        <div class="col-sm-4 col-md-3">
            <select class="form-select" name="status" aria-label="Filter by status">
                <option value="">Any status</option>
                @foreach (['active', 'locked', 'disabled'] as $s)
                    <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Filter</button>
        </div>
        @if($q || $status)
            <div class="col-auto"><a class="btn btn-link" href="{{ route('users.index') }}">Reset</a></div>
        @endif
    </form>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>E-mail</th>
                        <th>Status</th>
                        <th>Roles</th>
                        <th>Branch scope</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr>
                            <td>
                                <a class="fw-semibold text-decoration-none" href="{{ route('users.show', $u) }}">{{ $u->name }}</a>
                                @if($u->id === auth()->id())<span class="erp-chip erp-chip-soft">you</span>@endif
                            </td>
                            <td class="text-body-secondary">{{ $u->email }}</td>
                            <td><span class="erp-status erp-status-{{ $u->status }}">{{ $u->status }}</span></td>
                            <td>
                                @forelse($u->roles as $r)
                                    <span class="erp-chip">{{ $r->name }}</span>
                                @empty
                                    <span class="text-body-secondary">—</span>
                                @endforelse
                            </td>
                            <td>
                                @if($u->branch_scope === 'all')
                                    <span class="erp-chip erp-chip-warn">all branches</span>
                                @else
                                    <span class="text-body-secondary">assigned</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-light" href="{{ route('users.show', $u) }}">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4 text-body-secondary">No users match this filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $users->links() }}</div>
@endsection
