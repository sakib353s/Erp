@extends('layouts.app')

@section('page_title', 'Approval inbox')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Approval inbox</h1>
            <p class="erp-page-sub">Requests routed by the generic workflow engine — decisions are enforced per step, server-side.</p>
        </div>
    </div>

    <ul class="nav erp-tabs mb-3">
        @foreach (['awaiting' => 'Awaiting me', 'submitted' => 'Submitted by me', 'all' => 'All in my scope'] as $tabKey => $tabLabel)
            <li class="nav-item">
                <a class="nav-link {{ $tab === $tabKey ? 'active' : '' }}"
                   href="{{ route('approvals.index', array_filter(['tab' => $tabKey, 'status' => $tabKey === 'awaiting' ? 'pending' : $status])) }}">
                    {{ $tabLabel }}
                </a>
            </li>
        @endforeach
    </ul>

    @if ($tab !== 'awaiting')
        <form class="row g-2 mb-3" method="GET" action="{{ route('approvals.index') }}">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="col-sm-4 col-md-3">
                <select class="form-select" name="status" aria-label="Filter by status">
                    <option value="all" @selected($status === 'all')>Any status</option>
                    @foreach (['pending', 'approved', 'rejected', 'returned', 'cancelled'] as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button>
            </div>
        </form>
    @endif

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Request</th>
                        <th>Workflow</th>
                        <th class="text-end">Amount</th>
                        <th>Level</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($requests as $req)
                        <tr class="{{ $tab === 'awaiting' && $canAct($req) ? 'erp-row-attention' : '' }}">
                            <td>
                                <a class="fw-semibold text-decoration-none" href="{{ route('approvals.show', $req) }}">{{ $req->subject }}</a>
                                @if($req->reference_no)<span class="d-block small text-body-secondary"><code>{{ $req->reference_no }}</code></span>@endif
                            </td>
                            <td class="text-body-secondary">
                                {{ $req->definition?->name ?: $req->entity_type.' · '.$req->action }}
                                <span class="d-block small">rev {{ $req->revision }} · v{{ $req->workflow_version }}</span>
                            </td>
                            <td class="text-end">
                                @if($req->amount !== null)
                                    <span class="erp-amount">৳{{ number_format((float) $req->amount, 2) }}</span>
                                @else
                                    <span class="text-body-secondary">—</span>
                                @endif
                            </td>
                            <td>{{ $req->current_level }}</td>
                            <td class="text-body-secondary">
                                {{ $req->submitted_at?->format('d M Y, H:i') }}
                                <span class="d-block small">{{ $req->submitter?->name }}</span>
                            </td>
                            <td><span class="erp-status erp-status-{{ $req->status }}">{{ $req->status }}</span></td>
                            <td class="text-end">
                                @if ($tab === 'awaiting' && $canAct($req))
                                    <a class="btn btn-sm btn-primary" href="{{ route('approvals.show', $req) }}">Review</a>
                                @else
                                    <a class="btn btn-sm btn-light" href="{{ route('approvals.show', $req) }}">View</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-4 text-body-secondary">
                                @if($tab === 'awaiting')
                                    Nothing is waiting for your decision. 🎉
                                @else
                                    No requests match this filter.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $requests->links() }}</div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
