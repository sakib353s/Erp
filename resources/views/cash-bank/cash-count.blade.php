@extends('layouts.app')

@section('page_title', 'Cash count sheet')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Cash management · Cash count"
        title="The counted drawer"
        subtitle="What the books said on the day, what was found in the tin, and the notes and coins that were added up to say so — kept together because a count is evidence rather than a figure. Nothing on this sheet was recalculated afterwards."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.cash-counts') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All counts
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.book', $count->account_id) }}">
                <i class="bi bi-journal-text" aria-hidden="true"></i> The drawer's ledger
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="erp-note erp-note-ok mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    @error('cash_count')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    @if ($count->isPending())
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            <div>
                The difference is <strong>not in the ledger</strong>. It is at or above the tolerance of
                ৳ {{ $count->tolerance }}, so somebody other than {{ $count->counter?->name ?? 'the counter' }} has to approve it —
                money that is missing must not be written off by the person who was holding it.
            </div>
        </div>
    @elseif ($count->isPosted() && $count->journalEntry)
        <div class="erp-note erp-note-ok mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>
                The difference was posted the day it was counted:
                {{ $count->isShort() ? 'the drawer was credited and Cash Over &amp; Short debited' : 'the drawer was debited and Other Income credited' }}
                — see <a href="{{ route('accounting.journals.show', $count->journal_entry_id) }}">the entry</a>.
            </div>
        </div>
    @elseif ($count->isPosted())
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>The tin held exactly what the books said it would, so there was nothing to correct.</div>
        </div>
    @else
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-x-octagon" aria-hidden="true"></i>
            <div>
                This count was refused, so the books are exactly as they were. The refusal is on the record with its reason:
                {{ $count->decision_note ?? 'no reason given' }}.
            </div>
        </div>
    @endif

    <div class="erp-split">
        <section class="erp-card">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">{{ $count->account?->code }} — {{ $count->account?->name }}</h2>
                    <p class="erp-card-sub">
                        Counted on {{ $count->counted_on->toDateString() }}
                        @if ($count->branch) · {{ $count->branch->name }} @endif
                    </p>
                </div>
                <x-ui.status :value="$count->statusTone()" :label="$count->label()" />
            </header>
            <div>
                <div class="erp-dl erp-dl-tight erp-dl-striped">
                    <dt>Books said</dt>
                    <dd class="erp-money-flat">৳ {{ number_format((float) $count->expected_amount, 2) }}</dd>
                    <dt>Counted</dt>
                    <dd class="erp-money-flat">৳ {{ number_format((float) $count->counted_amount, 2) }}</dd>
                    <dt>Difference</dt>
                    <dd>
                        <x-ui.status :value="$count->varianceTone()" :label="$count->balanced() ? 'Counted exactly' : ($count->isShort() ? 'Short ' : 'Over ').$count->varianceLabel()" />
                    </dd>
                    <dt>Tolerance it was judged against</dt>
                    <dd class="erp-money-flat">৳ {{ $count->tolerance }}</dd>
                    <dt>Counted by</dt>
                    <dd>{{ $count->counter?->name ?? '—' }}</dd>
                    @if ($count->decider)
                        <dt>Decided by</dt>
                        <dd>
                            {{ $count->decider->name }}
                            @if ($count->decided_at)
                                <span class="erp-td-muted">on {{ $count->decided_at->toDateString() }}</span>
                            @endif
                        </dd>
                    @endif
                    <dt>Why it did not match</dt>
                    <dd>{{ $count->difference_reason ?? '—' }}</dd>
                    @if ($count->notes)
                        <dt>Noted</dt>
                        <dd>{{ $count->notes }}</dd>
                    @endif
                    @if ($count->decision_note)
                        <dt>On the decision</dt>
                        <dd>{{ $count->decision_note }}</dd>
                    @endif
                </div>
            </div>
        </section>

        <section class="erp-card">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">What the drawer was holding</h2>
                    <p class="erp-card-sub">
                        @if ($count->lines->isNotEmpty())
                            Added up by hand — the figures here are the ones written down at the tin.
                        @else
                            Counted as a single figure: the notes were not written down on this one.
                        @endif
                    </p>
                </div>
            </header>
            <div>
                @if ($count->lines->isNotEmpty())
                    <div class="erp-table-scroll">
                        <table class="erp-table erp-table-compact">
                            <thead>
                                <tr>
                                    <th>Denomination</th>
                                    <th class="erp-th-num">Count</th>
                                    <th class="erp-th-num">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($count->lines as $line)
                                    <tr>
                                        <td>{{ $line->label() }}</td>
                                        <td class="erp-td-num">{{ number_format($line->quantity) }}</td>
                                        <td class="erp-td-num">৳ {{ number_format((float) $line->amount, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="2">Added up</th>
                                    <th class="erp-th-num">৳ {{ number_format((float) $count->lines->sum(fn ($line) => (float) $line->amount), 2) }}</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @else
                    <x-ui.empty
                        title="No breakdown on this count"
                        icon="bi-cash-coin"
                        text="It was recorded as one figure. Counting the notes into the sheet next time makes the count checkable by whoever reads it." />
                @endif
            </div>
        </section>
    </div>

    @if ($count->isPending() && $mayApprove)
        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Answer for the difference</h2>
                    <p class="erp-card-sub">
                        Approving posts it — {{ $count->isShort() ? 'a shortage debits Cash Over &amp; Short and credits the drawer' : 'an overage debits the drawer and credits Other Income' }}.
                        Refusing leaves the books untouched and needs a reason the next counter can read.
                    </p>
                </div>
            </header>
            <div>
                <form method="POST" action="{{ route('cash-bank.cash-counts.decide', $count) }}">
                    @csrf
                    <div class="erp-form-grid">
                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="note">Why (kept with the decision)</label>
                            <input class="form-control" id="note" name="note" maxlength="300" placeholder="The courier was paid from the till and the voucher was filed late">
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit" name="action" value="approve">
                            <i class="bi bi-check2" aria-hidden="true"></i> Approve and post the difference
                        </button>
                        <button class="btn btn-outline-secondary" type="submit" name="action" value="reject">
                            <i class="bi bi-x-lg" aria-hidden="true"></i> Refuse the count
                        </button>
                    </div>
                </form>
            </div>
        </section>
    @elseif ($count->isPending())
        <div class="erp-note erp-note-warn mt-3">
            <i class="bi bi-person-lock" aria-hidden="true"></i>
            <div>
                This one is waiting for somebody who may approve a counted difference
                @if ($count->counter) — and not {{ $count->counter->name }}, who counted it. @endif
            </div>
        </div>
    @endif

    @if ($drawer)
        <div class="erp-filter-note mt-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <span>
                This drawer now reads ৳ {{ number_format((float) $drawer['balance'], 2) }} in the books, and it was last counted
                {{ $drawer['last']?->counted_on?->toDateString() ?? 'never' }}.
                @if ($drawer['pending'])
                    Another count of it is still waiting for a decision.
                @endif
            </span>
        </div>
    @endif

    <x-ui.related-pages />
@endsection
