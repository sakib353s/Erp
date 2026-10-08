@extends('layouts.app')

@section('page_title', $expense->expense_no)

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Expenses"
        title="{{ $expense->payee }}"
        subtitle="{{ $expense->category?->name }} · booked to {{ $expense->category?->accountLabel() }} · {{ $expense->expense_date?->toDateString() }}"
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.expenses') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> The register
            </a>
            @if ($expense->receipt)
                <a class="btn btn-outline-secondary" href="{{ route('documents.download', ['document' => $expense->receipt->id]) }}">
                    <i class="bi bi-paperclip" aria-hidden="true"></i> The receipt
                </a>
            @endif
            <x-ui.status :value="$expense->statusTone()" :label="$expense->label()" />
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="erp-note erp-note-ok mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    @error('expense')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    @if ($expense->isPending())
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">{{ $expense->expense_no }} is waiting for approval — nothing is in the books</strong>
                It is worth ৳ {{ number_format((float) $expense->amount, 2) }}, at or above the approval limit of ৳ {{ number_format((float) $expense->approval_threshold, 2) }} it was judged against. It posts the moment somebody with the authority approves it, and the day it posts is the day the money leaves the books.
            </div>
        </div>
    @endif

    @if ($expense->isRejected())
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-x-octagon" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">Refused — nothing posted, and nothing ever will</strong>
                The record is kept so the desk can answer "who asked for this and why was it refused?" — but no journal entry was ever written for it.
            </div>
        </div>
    @endif

    <div class="erp-split">
        <section>
            <div class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">The expense</h2>
                    <div class="erp-card-actions">
                        <span class="erp-chip erp-chip-outline">{{ $expense->expense_no }}</span>
                        <span class="erp-chip erp-chip-outline">{{ $expense->currency }}</span>
                    </div>
                </header>

                <div class="p-3">
                    <div class="erp-dl erp-dl-tight erp-dl-striped">
                        <dt>Amount</dt>
                        <dd>
                            <span class="erp-cell-strong">{{ number_format((float) $expense->amount, 2) }}</span>
                            <span class="d-block erp-td-muted">{{ $wordsEn }}</span>
                            <span class="d-block erp-td-muted">{{ $wordsBn }} · {{ $figuresBn }}</span>
                        </dd>

                        <dt>Date</dt>
                        <dd>{{ $expense->expense_date?->toDateString() }}</dd>

                        <dt>Paid to</dt>
                        <dd>
                            {{ $expense->payee }}
                            @if ($expense->supplier)
                                <span class="d-block erp-td-muted">on the books as {{ $expense->supplier->name }}</span>
                            @endif
                        </dd>

                        <dt>Category</dt>
                        <dd>
                            {{ $expense->category?->name }}
                            <span class="d-block erp-td-muted">debits {{ $expense->category?->accountLabel() }}</span>
                        </dd>

                        <dt>Money</dt>
                        <dd>
                            <x-ui.status :value="$expense->settlementTone()" :label="$expense->settlementLabel()" />
                            @if ($expense->moneyAccount)
                                <span class="d-block erp-td-muted">from {{ $expense->moneyAccount->code }} — {{ $expense->moneyAccount->name }}</span>
                            @elseif ($expense->settled_with === 'payable')
                                <span class="d-block erp-td-muted">carried as a liability until it is paid</span>
                            @endif
                        </dd>

                        @if ($expense->narration)
                            <dt>What it was</dt>
                            <dd>{{ $expense->narration }}</dd>
                        @endif
                        @if ($expense->isGenerated() && $expense->recurring)
                            <dt>Where it came from</dt>
                            <dd>
                                Generated by the standing schedule for {{ $expense->recurring->payee }} —
                                {{ $expense->recurring->rhythm() }}.
                                @if ($perm('expenses.recurring'))
                                    <a href="{{ route('cash-bank.expenses.recurring') }}">the schedule</a>.
                                @endif
                                It went through this same desk: the approval limit applied, and whoever wrote the schedule cannot approve what it produced.
                            </dd>
                        @endif

                        @if ($expense->receipt)
                            <dt>Receipt</dt>
                            <dd>
                                <a href="{{ route('documents.download', ['document' => $expense->receipt->id]) }}">{{ $expense->receipt->original_name }}</a>
                                <span class="d-block erp-td-muted">{{ $expense->receipt->created_at?->toDateTimeString() }}</span>
                            </dd>
                        @endif

                        <dt>Recorded by</dt>
                        <dd>
                            {{ $expense->creator?->name ?? '—' }}
                            <span class="d-block erp-td-muted">{{ $expense->created_at?->toDateTimeString() }}</span>
                        </dd>

                        @if ($expense->decisionMaker)
                            <dt>{{ $expense->isRejected() ? 'Refused by' : 'Approved by' }}</dt>
                            <dd>
                                {{ $expense->decisionMaker->name }}
                                <span class="d-block erp-td-muted">{{ $expense->decided_at?->toDateTimeString() }}</span>
                                @if ($expense->decision_note)
                                    <span class="d-block">{{ $expense->decision_note }}</span>
                                @endif
                            </dd>
                        @endif

                        @if ($expense->approval_gate)
                            <dt>Approval limit</dt>
                            <dd>৳ {{ number_format((float) $expense->approval_threshold, 2) }} when this was recorded</dd>
                        @endif
                    </div>
                </div>
            </div>

            @if ($expense->journalEntry)
                <div class="erp-card mt-3">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">What it posted</h2>
                            <p class="erp-card-sub">Entry <span class="font-monospace">{{ $expense->journalEntry->entry_no }}</span> · {{ $expense->journalEntry->entry_date?->toDateString() }}</p>
                        </div>
                    </header>
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
                                @foreach ($expense->journalEntry->lines as $line)
                                    <tr>
                                        <td>
                                            <code>{{ $line->account?->code }}</code>
                                            <span class="erp-cell-strong">{{ $line->account?->name }}</span>
                                        </td>
                                        <td class="erp-td-muted">{{ $line->narration ?? '—' }}</td>
                                        <td class="erp-td-num erp-num">{{ $line->isDebit() ? number_format((float) $line->amount, 2) : '' }}</td>
                                        <td class="erp-td-num erp-num">{{ $line->isCredit() ? number_format((float) $line->amount, 2) : '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            @php
                                /* Summed from the legs themselves: the ledger service
                                   computes totals on the way out, and a footer that
                                   trusted a stale attribute would be the one place the
                                   page could disagree with the entry it shows. */
                                $debitTotal = $expense->journalEntry->lines->filter(fn ($leg) => $leg->isDebit())->sum(fn ($leg) => (float) $leg->amount);
                                $creditTotal = $expense->journalEntry->lines->filter(fn ($leg) => $leg->isCredit())->sum(fn ($leg) => (float) $leg->amount);
                            @endphp
                            <tfoot>
                                <tr>
                                    <th colspan="2" class="text-end">Total</th>
                                    <th class="erp-th-num">{{ number_format($debitTotal, 2) }}</th>
                                    <th class="erp-th-num">{{ number_format($creditTotal, 2) }}</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            @endif

            @if ($expense->reversalEntry)
                <div class="erp-card mt-3">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">The reversal that answers it</h2>
                            <p class="erp-card-sub">Entry <span class="font-monospace">{{ $expense->reversalEntry->entry_no }}</span> · {{ $expense->reversalEntry->entry_date?->toDateString() }}</p>
                        </div>
                    </header>
                    <div class="p-3">
                        <p class="mb-0">The original entry was <strong>not</strong> edited — money that left the company really did leave it, and the history of that has to survive. The reversal takes the same amounts back, and the ledger shows both.</p>
                    </div>
                </div>
            @endif
        </section>

        <aside>
            <div class="erp-card">
                <header class="erp-card-head"><h2 class="erp-card-title">The life of this expense</h2></header>
                <div class="p-3">
                    <ul class="erp-steps">
                        <li class="erp-step erp-step-done">
                            <div class="erp-step-mark"><span class="erp-step-dot"><i class="bi bi-check-lg" aria-hidden="true"></i></span><span class="erp-step-line"></span></div>
                            <div class="erp-step-body">
                                <p class="erp-step-title">Recorded</p>
                                <p class="erp-step-meta">{{ $expense->created_at?->toDateTimeString() }} · {{ $expense->creator?->name ?? '—' }}</p>
                            </div>
                        </li>

                        @if ($expense->approval_gate || $expense->decided_at)
                            <li class="erp-step {{ $expense->isRejected() ? 'erp-step-bad' : ($expense->decided_at ? 'erp-step-done' : 'erp-step-now') }}">
                                <div class="erp-step-mark">
                                    <span class="erp-step-dot"><i class="bi {{ $expense->isRejected() ? 'bi-x-lg' : 'bi-check-lg' }}" aria-hidden="true"></i></span>
                                    @unless ($expense->isRejected())<span class="erp-step-line"></span>@endunless
                                </div>
                                <div class="erp-step-body">
                                    <p class="erp-step-title">{{ $expense->isRejected() ? 'Refused' : 'Approved' }}</p>
                                    <p class="erp-step-meta">
                                        @if ($expense->decided_at)
                                            {{ $expense->decided_at->toDateTimeString() }} · {{ $expense->decisionMaker?->name ?? '—' }}
                                        @else
                                            waiting — it may not post until somebody else signs it
                                        @endif
                                    </p>
                                </div>
                            </li>
                        @endif

                        <li class="erp-step {{ $expense->isPosted() || $expense->isReversed() ? 'erp-step-done' : 'erp-step-pending' }}">
                            <div class="erp-step-mark"><span class="erp-step-dot"><i class="bi bi-journal-check" aria-hidden="true"></i></span><span class="erp-step-line"></span></div>
                            <div class="erp-step-body">
                                <p class="erp-step-title">Posted to the ledger</p>
                                <p class="erp-step-meta">{{ $expense->journalEntry?->entry_no ?? 'not yet — the books have not been told' }}</p>
                            </div>
                        </li>

                        <li class="erp-step {{ $expense->isReversed() ? 'erp-step-bad' : 'erp-step-pending' }}">
                            <div class="erp-step-mark"><span class="erp-step-dot"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></span></div>
                            <div class="erp-step-body">
                                <p class="erp-step-title">Reversed</p>
                                <p class="erp-step-meta">{{ $expense->reversalEntry?->entry_no ?? 'nothing has been given back' }}</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            @if ($mayDecide && ($expense->isPending() || $expense->isReversible()))
                <div class="erp-card mt-3">
                    <header class="erp-card-head"><h2 class="erp-card-title">Decide</h2></header>
                    <div class="p-3">
                        @if ($isOwnRecord)
                            <div class="erp-note erp-note-warn">
                                <i class="bi bi-person-x" aria-hidden="true"></i>
                                <div>You recorded this expense, so you cannot decide it. An approval given to oneself is not an approval — ask somebody else to look at it.</div>
                            </div>
                        @elseif ($expense->isPending())
                            <p class="mb-2">Approving posts it now, exactly once. Refusing keeps the record and posts nothing — ever.</p>
                            <form method="POST" action="{{ route('cash-bank.expenses.decide', ['expense' => $expense->id]) }}">
                                @csrf
                                <div class="erp-form-field">
                                    <label class="form-label" for="note">Note</label>
                                    <input class="form-control @error('note') is-invalid @enderror" type="text" id="note" name="note" maxlength="300"
                                           placeholder="Optional when approving or refusing">
                                    @error('note') <div class="text-danger small">{{ $message }}</div> @enderror
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-primary" type="submit" name="action" value="approve">
                                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Approve and post
                                    </button>
                                    <button class="btn btn-outline-secondary" type="submit" name="action" value="reject">
                                        <i class="bi bi-x-octagon" aria-hidden="true"></i> Refuse
                                    </button>
                                </div>
                            </form>
                        @else
                            <p class="mb-2">Reversing gives the money back in the books. The original entry stays, and the reversal is filed beside it.</p>
                            <form method="POST" action="{{ route('cash-bank.expenses.decide', ['expense' => $expense->id]) }}"
                                  data-confirm="Reverse {{ $expense->expense_no }}? The ledger will show the entry and its reversal.">
                                @csrf
                                <div class="erp-form-field">
                                    <label class="form-label" for="note">Why it is being reversed</label>
                                    <input class="form-control @error('note') is-invalid @enderror" type="text" id="note" name="note" maxlength="300" required
                                           placeholder="Recorded twice; the receipt belongs to the March bill">
                                    <div class="form-text">Required. A reversal without a reason is an entry nobody can explain next year.</div>
                                    @error('note') <div class="text-danger small">{{ $message }}</div> @enderror
                                </div>
                                <button class="btn btn-outline-secondary" type="submit" name="action" value="reverse">
                                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reverse it
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @elseif ($expense->isPending())
                <div class="erp-card mt-3">
                    <header class="erp-card-head"><h2 class="erp-card-title">Waiting</h2></header>
                    <div class="p-3">
                        <p class="mb-0 erp-td-muted">This expense is waiting for somebody holding the approval key. Until then nothing about it is in the books, and no report will show it as money spent.</p>
                    </div>
                </div>
            @endif

            <div class="erp-card mt-3">
                <header class="erp-card-head"><h2 class="erp-card-title">The round trip</h2></header>
                <div class="p-3">
                    <div class="erp-dl erp-dl-tight">
                        <dt>Debit</dt>
                        <dd>{{ $expense->category?->accountLabel() }}</dd>
                        <dt>Credit</dt>
                        <dd>{{ $expense->settled_with === 'money' ? ($expense->moneyAccount?->name ?? '—') : 'payables, through the company posting rules' }}</dd>
                    </div>
                    <p class="erp-td-muted mt-3 mb-0">Two lines, always: an expense that does not balance is not an expense, it is a typo.</p>
                </div>
            </div>
        </aside>
    </div>
@endsection
