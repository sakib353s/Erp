@extends('layouts.app')

@section('page_title', 'Journal '.$entry->entry_no)

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Journal {{ $entry->entry_no }}</h1>
            <p class="erp-page-sub">
                {{ $entry->entry_date->format('d M Y') }} ·
                <span class="erp-chip erp-chip-soft">{{ $entry->journal_type }}</span>
                <span class="erp-status {{
                    $entry->posting_state === 'posted' ? 'erp-status-active'
                    : ($entry->posting_state === 'reversed' ? 'erp-status-disabled' : 'erp-status-pending')
                }}">{{ $entry->posting_state }}</span>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('accounting.journals.index') }}">Back</a>
            @if ($entry->isPosted() && $perm('accounting.journals.reverse'))
                <button class="btn btn-outline-danger" type="button" data-bs-toggle="collapse" data-bs-target="#reverse-panel">
                    Reverse
                </button>
            @endif
        </div>
    </div>

    @if ($entry->reversalOf)
        <div class="alert alert-warning">
            This entry reverses
            <a href="{{ route('accounting.journals.show', $entry->reversalOf) }}">{{ $entry->reversalOf->entry_no }}</a>.
        </div>
    @endif
    @if ($entry->isReversed() && $entry->reversals->isNotEmpty())
        <div class="alert alert-info">
            Reversed by
            @foreach ($entry->reversals as $rev)
                <a href="{{ route('accounting.journals.show', $rev) }}">{{ $rev->entry_no }}</a>{{ $loop->last ? '' : ', ' }}
            @endforeach.
        </div>
    @endif

    @if ($entry->isPosted() && $perm('accounting.journals.reverse'))
        <div class="collapse mb-3" id="reverse-panel">
            <div class="erp-card">
                <form method="POST" action="{{ route('accounting.journals.reverse', $entry) }}">
                    @csrf
                    <label class="form-label" for="reason">Reversal reason (required)</label>
                    <textarea class="form-control mb-2" id="reason" name="reason" rows="2" required minlength="3">{{ old('reason') }}</textarea>
                    @error('reason') <div class="text-danger small mb-2">{{ $message }}</div> @enderror
                    <button class="btn btn-danger" type="submit">Confirm reversal</button>
                </form>
            </div>
        </div>
    @endif

    <div class="erp-card mb-3">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="small text-body-secondary">Entry no</div>
                <div class="fw-semibold">{{ $entry->entry_no }}</div>
            </div>
            <div class="col-md-3">
                <div class="small text-body-secondary">Fiscal period</div>
                <div class="fw-semibold">{{ $entry->fiscalPeriod?->code }} ({{ $entry->fiscalPeriod?->status }})</div>
            </div>
            <div class="col-md-3">
                <div class="small text-body-secondary">Posted by</div>
                <div class="fw-semibold">{{ $entry->poster?->name ?? '—' }}</div>
            </div>
            <div class="col-md-3">
                <div class="small text-body-secondary">Checksum</div>
                <code class="small">{{ \Illuminate\Support\Str::limit($entry->checksum, 16, '…') }}</code>
            </div>
            <div class="col-12">
                <div class="small text-body-secondary">Description</div>
                <div>{{ $entry->description }}</div>
            </div>
            @if ($entry->narration)
                <div class="col-12">
                    <div class="small text-body-secondary">Narration</div>
                    <div>{{ $entry->narration }}</div>
                </div>
            @endif
            @if ($entry->source_type)
                <div class="col-12">
                    <div class="small text-body-secondary">Source</div>
                    <div><code>{{ $entry->source_type }}#{{ $entry->source_id }}</code> @if ($entry->source_event)· <code>{{ $entry->source_event }}</code>@endif</div>
                </div>
            @endif
        </div>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Account</th>
                        <th>Narration</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entry->lines as $line)
                        <tr>
                            <td>{{ $line->line_no }}</td>
                            <td>
                                <a class="text-decoration-none" href="{{ route('accounting.ledger', $line->account) }}">
                                    <code>{{ $line->account?->code }}</code> {{ $line->account?->name }}
                                </a>
                            </td>
                            <td class="small">{{ $line->narration }}</td>
                            <td class="text-end">{{ $line->isDebit() ? number_format((float) $line->amount, 2) : '' }}</td>
                            <td class="text-end">{{ $line->isCredit() ? number_format((float) $line->amount, 2) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3" class="text-end">Total</th>
                        <th class="text-end">{{ number_format((float) $entry->total_debit, 2) }}</th>
                        <th class="text-end">{{ number_format((float) $entry->total_credit, 2) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
