@extends('layouts.app')

@section('page_title', 'Navigation registry')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Navigation registry</h1>
            <p class="erp-page-sub">
                The full imported menu catalog. <code>planned</code> entries are catalogued but <strong>never rendered</strong>
                in the sidebar until you flip them to active here.
            </p>
        </div>
        <div class="d-flex gap-2">
            <span class="erp-chip erp-chip-soft">{{ $counts['active'] }} active</span>
            <span class="erp-chip erp-chip-warn">{{ $counts['planned'] }} planned</span>
        </div>
    </div>

    <ul class="nav erp-tabs mb-3">
        @foreach($locations as $locationKey)
            <li class="nav-item">
                <a class="nav-link {{ $location === $locationKey ? 'active' : '' }}"
                   href="{{ route('menus.index', ['location' => $locationKey]) }}">{{ ucfirst($locationKey) }}</a>
            </li>
        @endforeach
    </ul>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Label</th>
                        <th>Route</th>
                        <th>Permission</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                        <tr class="{{ $item->status === 'planned' ? 'erp-row-muted' : '' }}">
                            <td><code class="small">{{ $item->code }}</code></td>
                            <td>
                                <span class="fw-semibold">{{ $item->label }}</span>
                                @if($item->module)<span class="d-block small text-body-secondary">{{ $item->module->name }}</span>@endif
                            </td>
                            <td class="text-body-secondary small">
                                @if($item->route)<code>{{ $item->route }}</code>@else— @endif
                            </td>
                            <td class="small">
                                @if($item->permission)<code>{{ $item->permission->key }}</code>@else<span class="text-body-secondary">public</span>@endif
                            </td>
                            <td>
                                <span class="erp-status {{ $item->status === 'active' ? 'erp-status-active' : 'erp-status-planned' }}">
                                    {{ $item->status }}
                                </span>
                            </td>
                            <td class="text-end">
                                @if ($perm('menus.manage'))
                                    <form method="POST" action="{{ route('menus.toggle', $item) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                                            {{ $item->status === 'active' ? 'Mark planned' : 'Make active' }}
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center py-4 text-body-secondary">No menu entries for this location.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
