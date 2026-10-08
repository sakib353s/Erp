@extends('layouts.app')

@section('page_title', 'Cheque '.$cheque->cheque_no)

@php
    // The state names are the register's own vocabulary — spelled here exactly as
    // the migration and the model spell them, because this view only reads them.
    $steps = [];

    if ($cheque->isReceived()) {
        $steps[] = ['key' => 'received', 'title' => 'Recorded as received', 'meta' => $cheque->created_at?->format('d M Y')];
        $steps[] = ['key' => 'deposited', 'title' => 'Deposited with the bank', 'meta' => $cheque->deposited_on?->format('d M Y')];
    } else {
        $steps[] = ['key' => 'issued', 'title' => 'Recorded as issued', 'meta' => $cheque->created_at?->format('d M Y')];
        $steps[] = ['key' => 'presented', 'title' => 'Presented for payment', 'meta' => $cheque->presented_on?->format('d M Y')];
    }

    $steps[] = ['key' => 'cleared', 'title' => 'Cleared — the bank paid it', 'meta' => $cheque->cleared_on?->format('d M Y')];

    if ($cheque->hasFailed()) {
        $steps[] = [
            'key' => 'failed',
            'title' => ($cheque->isReceived() ? 'Bounced' : 'Returned unpaid').' — '.($cheque->bounced_reason ?? 'no reason recorded'),
            'meta' => $cheque->bounced_on?->format('d M Y'),
        ];
    }

    // Where each step stands: the state the cheque is in now, the steps it has
    // already walked through, and the ones that have not happened at all.
    $stateFor = function (string $key) use ($cheque): string {
        if ($key === 'failed') {
            return 'bad';
        }

        if ($key === $cheque->status) {
            return 'now';
        }

        $done = match ($key) {
            'received', 'issued' => true,
            'deposited' => $cheque->deposited_on !== null,
            'presented' => $cheque->presented_on !== null,
            'cleared' => $cheque->cleared_on !== null,
            default => false,
        };

        return $done ? 'done' : 'pending';
    };

    // What the desk can do next. A cleared cheque is not settled history: if the
    // money came back, that is a failure and it reverses the entry — so the only
    // state that offers nothing is one that has already failed.
    $movements = [];

    if (! $cheque->hasFailed()) {
        if ($cheque->isReceived() && $cheque->status === 'received') {
            $movements['deposit'] = 'Deposited — handed to the bank';
        }

        if ($cheque->isIssued() && $cheque->status === 'issued') {
            $movements['present'] = 'Presented — handed to the party';
        }

        if ($cheque->isWithBank()) {
            $movements['clear'] = 'Cleared — the bank paid it (this is what posts)';
        }

        $movements['fail'] = $cheque->isReceived()
            ? 'Failed — the cheque bounced'
            : 'Failed — it came back unpaid and is reversed';
    }
@endphp

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; bank · Cheque management · {{ $cheque->isReceived() ? 'Received' : 'Issued' }}"
        title="Cheque {{ $cheque->cheque_no }}"
        :subtitle="$cheque->party_name.' · '.$cheque->bank_name.' · '.number_format((float) $cheque->amount, 2).' '.$cheque->currency.', written for '.$cheque->cheque_date?->format('d M Y').'.'"
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.cheques') }}">
                <i class="bi bi-journal-bookmark" aria-hidden="true"></i> The register
            </a>
            @if ($perm('bank.view'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.book', ['account' => $cheque->account_id]) }}">
                    <i class="bi bi-journal-text" aria-hidden="true"></i> Its book
                </a>
            @endif
            @if ($cheque->isIssued() && $perm('cheques.print'))
                <a class="btn btn-primary" href="{{ route('cash-bank.cheques.print', ['cheque' => $cheque->id]) }}"
                   target="_blank" rel="noopener">
                    <i class="bi bi-printer" aria-hidden="true"></i> Print the record
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    @error('cheque')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    @error('reason')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    @if ($cheque->isPostDated())
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-calendar-event" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">Post-dated to {{ $cheque->cheque_date?->format('d M Y') }}</strong>
                The register holds it, and the desk refuses to {{ $cheque->isReceived() ? 'deposit' : 'present' }} or clear it
                before that date — an early deposit comes back, and the return is a mark on the account for nothing.
            </div>
        </div>
    @endif

    <div class="erp-split">
        <div>
            <section class="erp-card">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Where this cheque has got to</h2>
                        <p class="erp-card-sub">The steps it has walked through, the one it is on, and the ones that have not happened — including the ones that never will.</p>
                    </div>
                    <div class="erp-card-actions">
                        <x-ui.status :value="$cheque->statusTone()" :label="$cheque->statusLabel()" lg />
                    </div>
                </header>

                <ul class="erp-steps">
                    @foreach ($steps as $index => $step)
                        @php($state = $stateFor($step['key']))
                        <li class="erp-step erp-step-{{ $state }}">
                            <div class="erp-step-mark">
                                <span class="erp-step-dot">
                                    @if ($state === 'done')
                                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                                    @elseif ($state === 'bad')
                                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                                    @else
                                        {{ $index + 1 }}
                                    @endif
                                </span>
                                @unless ($loop->last)
                                    <span class="erp-step-line"></span>
                                @endunless
                            </div>
                            <div class="erp-step-body">
                                <p class="erp-step-title">{{ $step['title'] }}</p>
                                <p class="erp-step-meta">
                                    @if ($step['meta'])
                                        {{ $step['meta'] }}
                                    @elseif ($state === 'now')
                                        Waiting — this is where it stands
                                    @else
                                        Not yet
                                    @endif
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">What the ledger has seen</h2>
                    @if ($cheque->journalEntry)
                        <div class="erp-card-actions">
                            <span class="erp-chip erp-chip-outline">{{ $cheque->journalEntry->entry_no }}</span>
                        </div>
                    @endif
                </header>

                @if ($cheque->journalEntry === null)
                    <p class="mb-0 erp-td-muted">
                        Nothing. {{ $cheque->hasFailed() ? 'The cheque failed before any bank paid it' : 'A promise the bank has not paid yet' }}
                        is not a posting, and the books say so by staying silent — which is why this register is not part of the trial balance.
                    </p>
                @else
                    <p class="mb-2">
                        The bank paid it on {{ $cheque->cleared_on?->format('d M Y') }}, and that posted once:
                    </p>
                    <div class="erp-table-scroll">
                        <table class="erp-table erp-table-compact">
                            <thead>
                                <tr>
                                    <th>Account</th>
                                    <th>What it was</th>
                                    <th class="erp-th-num">Debit</th>
                                    <th class="erp-th-num">Credit</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($cheque->journalEntry->lines as $line)
                                    <tr>
                                        <td data-label="Account">
                                            @if ($perm('accounting.coa.view'))
                                                <a class="text-decoration-none" href="{{ route('accounting.ledger', ['account' => $line->account_id]) }}">
                                                    <code>{{ $line->account?->code }}</code> <span class="erp-cell-strong">{{ $line->account?->name }}</span>
                                                </a>
                                            @else
                                                <code>{{ $line->account?->code }}</code> <span class="erp-cell-strong">{{ $line->account?->name }}</span>
                                            @endif
                                        </td>
                                        <td data-label="What it was" class="erp-td-muted">{{ $line->narration ?? '—' }}</td>
                                        <td data-label="Debit" class="erp-td-num">
                                            {{ $line->isDebit() ? number_format((float) $line->amount, 2) : '' }}
                                        </td>
                                        <td data-label="Credit" class="erp-td-num">
                                            {{ $line->isCredit() ? number_format((float) $line->amount, 2) : '' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="2" class="text-end">Total</th>
                                    <th class="erp-th-num">{{ number_format((float) $cheque->journalEntry->total_debit, 2) }}</th>
                                    <th class="erp-th-num">{{ number_format((float) $cheque->journalEntry->total_credit, 2) }}</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    @if ($cheque->reversalEntry)
                        <div class="erp-note erp-note-danger mt-3">
                            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                            <div>
                                <strong class="d-block mb-1">Reversed by {{ $cheque->reversalEntry->entry_no }}</strong>
                                The cheque cleared and then failed, so the money went back out. The original entry stays where it
                                is: it really happened. Open the reversal to see the two legs that answer it.
                                @if ($perm('accounting.journals.view'))
                                    <a class="fw-semibold" href="{{ route('accounting.journals.show', ['journal' => $cheque->reversalEntry->id]) }}">Open it</a>.
                                @endif
                            </div>
                        </div>
                    @endif
                @endif
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">The amount in words</h2>
                        <p class="erp-card-sub">What the printed record carries beside the figures — a bank reads the words when the digits are unclear.</p>
                    </div>
                </header>
                <div class="erp-dl erp-dl-striped mb-0">
                    <dt>In English</dt>
                    <dd class="erp-cell-strong">{{ $wordsEn }}</dd>
                    <dt>In Bangla</dt>
                    <dd class="erp-cell-strong">{{ $wordsBn }}</dd>
                    <dt>Figures, Bangla</dt>
                    <dd>{{ $figuresBn }}</dd>
                </div>
            </section>
        </div>

        <aside>
            @if ($movements !== [] && $perm('cheques.clear'))
                <div class="erp-card">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">What happened next?</h2>
                    </header>

                    <form method="POST" action="{{ route('cash-bank.cheques.transition', ['cheque' => $cheque->id]) }}">
                        @csrf

                        <div class="erp-form-field">
                            <label class="form-label" for="action">Movement</label>
                            <select class="form-select @error('action') is-invalid @enderror" id="action" name="action" required>
                                @foreach ($movements as $key => $label)
                                    <option value="{{ $key }}" @selected(old('action') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('action') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>

                        <div class="erp-form-field mt-2">
                            <label class="form-label" for="on">On which day</label>
                            <input class="form-control @error('on') is-invalid @enderror" type="date" id="on" name="on"
                                   value="{{ old('on', now()->toDateString()) }}">
                            <div class="form-text">The day the bank paid it, for a clearance — that date is what the entry carries.</div>
                            @error('on') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>

                        <div class="erp-form-field mt-2">
                            <label class="form-label" for="reason">Why it failed</label>
                            <input class="form-control @error('reason') is-invalid @enderror" type="text" id="reason" name="reason"
                                   value="{{ old('reason') }}" maxlength="300" placeholder="Insufficient funds, signature mismatch…">
                            <div class="form-text">Required when the movement is a failure — a customer cannot be told "it bounced".</div>
                            @error('reason') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>

                        <button class="btn btn-primary w-100 mt-3" type="submit">
                            <i class="bi bi-arrow-right-circle" aria-hidden="true"></i> Record this movement
                        </button>
                    </form>

                    <div class="erp-note erp-note-info mt-3">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <div>
                            Only <strong>cleared</strong> posts to the ledger. Depositing and presenting are what the desk
                            remembers; clearing is what the books are told, and it is told exactly once.
                        </div>
                    </div>
                </div>
            @elseif ($cheque->hasFailed())
                <div class="erp-card">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">Why it failed</h2>
                    </header>
                    <div class="erp-dl erp-dl-tight erp-dl-striped mb-0">
                        <dt>On</dt>
                        <dd>{{ $cheque->bounced_on?->format('d M Y') ?? '—' }}</dd>
                        <dt>Reason</dt>
                        <dd>{{ $cheque->bounced_reason ?? '—' }}</dd>
                        <dt>Reversed</dt>
                        <dd>{{ $cheque->reversalEntry?->entry_no ?? 'Nothing was posted to reverse' }}</dd>
                    </div>
                </div>
            @else
                <div class="erp-card">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">Nothing to do from here</h2>
                    </header>
                    <p class="mb-0 erp-td-muted">
                        This cheque is {{ strtolower((string) $cheque->statusLabel()) }}, which is a closed story.
                        A fresh cheque is a fresh record.
                    </p>
                </div>
            @endif

            @unless ($perm('cheques.clear'))
                <div class="erp-note erp-note-info mt-3">
                    <i class="bi bi-lock" aria-hidden="true"></i>
                    <div>
                        You can read this cheque but not move it. Saying a cheque cleared is the
                        <code>cheques.clear</code> key, because that is the moment the ledger changes.
                    </div>
                </div>
            @endunless

            <div class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">The record</h2>
                </header>
                <div class="erp-dl erp-dl-tight erp-dl-striped mb-0">
                    <dt>Direction</dt>
                    <dd>{{ $cheque->isReceived() ? 'Received' : 'Issued' }}</dd>
                    <dt>Cheque no.</dt>
                    <dd class="font-monospace">{{ $cheque->cheque_no }}</dd>
                    <dt>Date on it</dt>
                    <dd>{{ $cheque->cheque_date?->format('d M Y') }}</dd>
                    <dt>Bank</dt>
                    <dd>{{ $cheque->bank_name }}</dd>
                    <dt>Clears through</dt>
                    <dd>{{ $cheque->account?->name ?? '—' }}</dd>
                    <dt>Settles</dt>
                    <dd>{{ $cheque->counterAccount?->name ?? '—' }}</dd>
                    <dt>Party</dt>
                    <dd>
                        {{ $cheque->party_name }}
                        @if ($cheque->customer)
                            <span class="d-block erp-td-muted">Customer: {{ $cheque->customer->name }}</span>
                        @endif
                        @if ($cheque->supplier)
                            <span class="d-block erp-td-muted">Supplier: {{ $cheque->supplier->name }}</span>
                        @endif
                    </dd>
                    <dt>Amount</dt>
                    <dd>{{ number_format((float) $cheque->amount, 2) }} {{ $cheque->currency }}</dd>
                    @if ($cheque->reference)
                        <dt>Reference</dt>
                        <dd class="font-monospace">{{ $cheque->reference }}</dd>
                    @endif
                    @if ($cheque->narration)
                        <dt>What for</dt>
                        <dd>{{ $cheque->narration }}</dd>
                    @endif
                    <dt>Written in by</dt>
                    <dd>{{ $cheque->createdBy?->name ?? '—' }}</dd>
                    <dt>Written in at</dt>
                    <dd>{{ $cheque->created_at?->format('d M Y H:i') }}</dd>
                </div>
            </div>
        </aside>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
