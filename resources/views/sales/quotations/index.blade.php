@extends('layouts.app')

@section('page_title', 'Quotations')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Quotations</h1>
            <p class="erp-page-sub">Price proposals — DOC only, no stock or GL effect.</p>
        </div>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" value="{{ $q }}" placeholder="Quote number">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    @foreach (['draft', 'sent', 'viewed', 'accepted', 'declined', 'expired', 'converted'] as $s)
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
                        <th>Quote #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th class="text-end">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($quotations as $quote)
                        <tr>
                            <td><code>{{ $quote->quote_no }}</code></td>
                            <td>{{ optional($quote->quote_date)->toDateString() }}</td>
                            <td>{{ $quote->customer?->name ?? '—' }}</td>
                            <td><span class="erp-status erp-status-active">{{ $quote->status }}</span>
                            @if ($quote->revision > 1)
                                <span class="erp-chip erp-chip-soft">r{{ $quote->revision }}</span>
                            @endif
                            @if ($quote->sent_at)
                                <div class="small text-muted">
                                    sent {{ $quote->sent_channel }} → {{ $quote->sent_to }}
                                    ({{ $quote->sent_at->format('Y-m-d H:i') }})
                                </div>
                            @endif
                            @if ($quote->share_token)
                                <div class="small">
                                    <a href="{{ route('share.quotation', ['token' => $quote->share_token]) }}" target="_blank" rel="noopener">
                                        share link
                                    </a>
                                    @if ($quote->viewed_at)
                                        · viewed {{ $quote->viewed_at->format('Y-m-d H:i') }}
                                    @endif
                                </div>
                            @endif
                        </td>
                            <td class="text-end">{{ number_format((float) $quote->grand_total, 2) }}</td>
                            <td class="text-end text-nowrap">
                                @if ($perm('sales.quotations.send') && in_array($quote->status, ['draft', 'sent', 'viewed'], true))
                                    <form method="POST" action="{{ route('sales.quotations.send', $quote) }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="channel" value="email">
                                        <button class="btn btn-sm btn-outline-primary" type="submit">
                                            {{ $quote->sent_at ? 'Resend' : 'Send' }}
                                        </button>
                                    </form>
                                @endif
                                @if ($perm('sales.quotations.process') && in_array($quote->status, ['draft', 'sent', 'viewed'], true))
                                    <form method="POST" action="{{ route('sales.quotations.accept', $quote) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-success" type="submit">Accept</button>
                                    </form>
                                    <form method="POST" action="{{ route('sales.quotations.decline', $quote) }}" class="d-inline" data-decline>
                                        @csrf
                                        <input type="hidden" name="reason" value="Declined from list">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Decline</button>
                                    </form>
                                @endif
                                @if ($perm('sales.quotations.revise') && ! in_array($quote->status, ['converted'], true))
                                    <form method="POST" action="{{ route('sales.quotations.revise', $quote) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Revise</button>
                                    </form>
                                @endif
                                @if ($perm('sales.quotations.convert') && ! in_array($quote->status, ['converted', 'declined'], true))
                                    <form method="POST" action="{{ route('sales.quotations.convert', $quote) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-primary" type="submit">Convert</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No quotations yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $quotations->links() }}</div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
