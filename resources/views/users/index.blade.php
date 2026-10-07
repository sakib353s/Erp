@extends('layouts.app')

@section('page_title', 'Users')

@section('content')
    <x-ui.page-header
        eyebrow="Configuration · Security"
        title="Users"
        subtitle="People with access, their roles and branch scope. You only ever see users inside your own access."
        :pin="true">
        <x-slot:actions>
            @if ($perm('users.create'))
                <a class="btn btn-primary" href="{{ route('users.create') }}">
                    <i class="bi bi-person-plus" aria-hidden="true"></i> New user
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <form class="erp-filterbar" method="GET" action="{{ route('users.index') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $q }}"
                       placeholder="Name or e-mail…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Any status</option>
                @foreach (['active', 'locked', 'disabled'] as $s)
                    <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if($q || $status)
                <a class="btn btn-link" href="{{ route('users.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$users->total().' total'">
        <x-slot:tools>
            <span class="erp-chip erp-chip-outline">branch-scoped</span>
        </x-slot:tools>

        <thead>
            <tr>
                <th>Name</th>
                <th>E-mail</th>
                <th>Status</th>
                <th>Roles</th>
                <th>Branch scope</th>
                <th class="erp-th-actions">Open</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $u)
                <tr>
                    <td data-label="Name">
                        <a class="erp-row-link" href="{{ route('users.show', $u) }}">{{ $u->name }}</a>
                        @if($u->id === auth()->id())<span class="erp-chip erp-chip-soft">you</span>@endif
                    </td>
                    <td data-label="E-mail" class="erp-td-muted">{{ $u->email }}</td>
                    <td data-label="Status"><span class="erp-status erp-status-{{ $u->status }}">{{ $u->status }}</span></td>
                    <td data-label="Roles">
                        @forelse($u->roles as $r)
                            <span class="erp-chip">{{ $r->name }}</span>
                        @empty
                            <span class="erp-td-muted">—</span>
                        @endforelse
                    </td>
                    <td data-label="Scope">
                        @if($u->branch_scope === 'all')
                            <span class="erp-chip erp-chip-warn">all branches</span>
                        @else
                            <span class="erp-td-muted">assigned branches</span>
                        @endif
                    </td>
                    <td data-label="Open" class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('users.show', $u) }}">View</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="p-0">
                        <x-ui.empty
                            icon="bi-person-x"
                            title="No users match this filter"
                            text="Clear the filters to see everyone in your scope, or invite a colleague if your role allows it." />
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <span>{{ $users->total() }} user{{ $users->total() === 1 ? '' : 's' }} in your scope</span>
            {{ $users->links() }}
        </x-slot:footer>
    </x-ui.table-shell>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
