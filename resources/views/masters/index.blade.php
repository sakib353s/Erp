@extends('layouts.app')

@section('page_title', $entry['label'])

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $entry['label'] }}</h1>
            <p class="erp-page-sub">Master data for this company. Every change is written to the audit trail.</p>
        </div>
        @if ($perm($entry['mutation_permission'] ?? $entry['permission']))
            <a class="btn btn-primary" href="{{ route('masters.'.$type.'.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New {{ strtolower($entry['label']) }}
            </a>
        @endif
    </div>

    <form class="row g-2 mb-3" method="GET" action="{{ route('masters.'.$type.'.index') }}">
        <div class="col-sm-5 col-md-4">
            <input class="form-control" type="search" name="q" value="{{ $q }}" placeholder="Search…" aria-label="Search">
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Filter</button>
        </div>
        @if($q)
            <div class="col-auto"><a class="btn btn-link" href="{{ route('masters.'.$type.'.index') }}">Reset</a></div>
        @endif
    </form>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        @foreach($entry['columns'] as $column)
                            <th>{{ ucwords(str_replace('_', ' ', $column)) }}</th>
                        @endforeach
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $record)
                        <tr>
                            @foreach($entry['columns'] as $column)
                                <td>
                                    @if($column === 'code')
                                        <code>{{ $record->getAttribute($column) }}</code>
                                    @elseif($column === 'is_active' || $column === 'is_recurring' || $column === 'is_paid' || $column === 'is_default' || $column === 'requires_reference' || $column === 'requires_inspection' || $column === 'is_global')
                                        <span class="erp-status {{ $record->getAttribute($column) ? 'erp-status-active' : 'erp-status-disabled' }}">
                                            {{ $record->getAttribute($column) ? 'yes' : 'no' }}
                                        </span>
                                    @elseif($column === 'name' || $column === 'full_name')
                                        <span class="fw-semibold">{{ $record->getAttribute($column) }}</span>
                                    @else
                                        <span class="text-body-secondary">{{ $record->getAttribute($column) ?? '—' }}</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="text-end">
                                @if ($perm($entry['mutation_permission'] ?? $entry['permission']))
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('masters.'.$type.'.edit', $record) }}">Edit</a>
                                    <form class="d-inline" method="POST" action="{{ route('masters.'.$type.'.destroy', $record) }}"
                                          data-confirm="Delete this {{ strtolower($entry['label']) }} row? This cannot be undone.">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Delete">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($entry['columns']) + 1 }}" class="text-center py-4 text-body-secondary">No rows match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $records->links() }}</div>
@endsection
