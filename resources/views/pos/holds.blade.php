@extends('layouts.app')

@section('page_title', 'POS Holds')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">POS Hold Orders</h1>
            <p class="erp-page-sub">Held carts re-reserve stock on resume (stock conflict surfaces).</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('pos.terminal') }}">Terminal</a>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    @foreach (['held', 'resumed', 'cancelled', 'committed'] as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ $s }}</option>
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
                        <th>Hold #</th>
                        <th>Held at</th>
                        <th>Lines</th>
                        <th class="text-end">Total</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($holds as $hold)
                        <tr>
                            <td><code>{{ $hold->hold_no }}</code></td>
                            <td>{{ optional($hold->held_at)->format('Y-m-d H:i') }}</td>
                            <td>{{ count($hold->lines ?? []) }}</td>
                            <td class="text-end">{{ number_format((float) $hold->total, 2) }}</td>
                            <td><span class="erp-status erp-status-{{ str_replace('_', '-', strtolower((string) ($hold->status))) }}">{{ $hold->status }}</span></td>
                            <td class="text-end">
                                @if ($hold->status === 'held')
                                    <form method="POST" action="{{ route('pos.holds.resume', $hold) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-primary" type="submit">Resume</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No holds yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $holds->links() }}</div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
