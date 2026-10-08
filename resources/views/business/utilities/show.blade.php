@php
    /* §12-15 — one bill: what it says, what it owes, and what the ledger did. */
    $money = fn ($value): string => '৳'.number_format((float) $value, 2);
@endphp

<x-ui.page-header
    eyebrow="Business Management · Utility Bills · {{ $bill->provider?->familyLabel() }}"
    title="{{ $bill->bill_no }} — {{ $bill->provider?->name }}"
    subtitle="Filed for {{ $bill->periodLabel() }} and due {{ $bill->due_date?->format('d M Y') }}. Filing recorded the obligation — nothing has been paid. Paying posts one entry, and the entry number below is the proof it did."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('business.utilities.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> The desk
        </a>
        @if ($canManage && $bill->isOpen())
            <a class="btn btn-outline-secondary" href="{{ route('business.utilities.edit', $bill) }}">
                <i class="bi bi-pencil" aria-hidden="true"></i> Correct it
            </a>
        @endif
        @if ($bill->journalEntry)
            <a class="btn btn-outline-secondary" href="{{ route('accounting.journals.show', $bill->journalEntry) }}">
                <i class="bi bi-journal-text" aria-hidden="true"></i> Journal {{ $bill->journalEntry->entry_no }}
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Amount" :value="$money($bill->amount)" icon="bi-cash-stack" hero
              :hint="$bill->consumption !== null
                  ? number_format((float) $bill->consumption, 2).' '.$bill->consumption_unit.' consumed'
                  : 'A standing charge — no meter reading'" />
    <x-ui.kpi label="Status" :value="$bill->stateLabel()" icon="bi-flag"
              :hint="$bill->isPaid()
                  ? 'Paid on '.$bill->paid_on?->format('d M Y')
                  : ($bill->isVoid()
                      ? 'Voided: '.$bill->void_reason
                      : ($bill->isOverdue() ? 'Past its due date' : 'Due '.$bill->due_date?->format('d M Y')))" />
    <x-ui.kpi label="Posts to" :value="$bill->provider?->account?->code ?? '—'" icon="bi-diagram-3"
              :hint="$bill->provider?->account?->name ?? 'The provider has no expense account yet'" />
    <x-ui.kpi label="Paid from" :value="$bill->moneyAccount?->code ?? 'Not yet'" icon="bi-wallet2"
              :hint="$bill->moneyAccount?->name ?? 'Nothing has left any account'" />
    <x-ui.kpi label="Due" :value="$bill->due_date?->format('d M Y') ?? '—'" icon="bi-calendar-event"
              :hint="$bill->daysToDue() === null
                  ? 'No due date on file'
                  : ($bill->daysToDue() < 0 ? abs($bill->daysToDue()).' day(s) past' : 'in '.$bill->daysToDue().' day(s)')" />
    <x-ui.kpi label="Bill date" :value="$bill->issue_date?->format('d M Y') ?? '—'" icon="bi-receipt"
              :hint="'Filed by '.($bill->creator?->name ?? 'somebody since removed')" />
</div>

@if ($bill->isVoid())
    <div class="erp-note mb-3">
        <i class="bi bi-slash-circle" aria-hidden="true"></i>
        <div>
            <strong>This bill was voided.</strong> {{ $bill->void_reason }} It stays on the register so the gap in the sequence has an explanation.
        </div>
    </div>
@endif

@if ($bill->isPending())
    <div class="erp-note erp-note-warn mb-3">
        <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
        <div>
            <strong>Waiting for a second signature.</strong>
            {{ $money($bill->amount) }} is at or above the approval limit of {{ $money($threshold) }}, so nothing has left {{ $bill->moneyAccount?->name ?? 'any account' }} yet.
            Somebody other than {{ $bill->creator?->name ?? 'the person who filed it' }} has to approve it below.
        </div>
    </div>
@endif

@if ($canManage && $bill->isOpen())
    <div class="erp-card mb-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">{{ $bill->isPending() ? 'Decide on the payment' : 'Pay this bill' }}</h2>
                <p class="erp-card-sub">
                    @if ($bill->isPending())
                        Approving posts the entry and the money moves; sending it back leaves it an unpaid bill with nothing touched.
                    @else
                        The entry is written now: Dr {{ $bill->provider?->account?->code ?? '—' }} · Cr the account the money leaves. At or above {{ $money($threshold) }} the payment is held for a second signature instead.
                    @endif
                </p>
            </div>
        </div>

        @if ($bill->isPending())
            <div class="row g-3">
                <div class="col-md-8">
                    <form method="POST" action="{{ route('business.utilities.approve', $bill) }}" data-confirm="Approve and pay {{ $bill->bill_no }}?">
                        @csrf
                        <label class="form-label" for="decision_note">Note (kept on the bill)</label>
                        <div class="d-flex gap-2">
                            <input class="form-control @error('decision_note') is-invalid @enderror" type="text" maxlength="500"
                                   id="decision_note" name="decision_note" value="{{ old('decision_note') }}"
                                   placeholder="Checked against the meter reading">
                            <button class="btn btn-primary text-nowrap" type="submit">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i> Approve &amp; pay
                            </button>
                        </div>
                        @error('decision_note')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </form>
                </div>
                <div class="col-md-4">
                    <form method="POST" action="{{ route('business.utilities.reject', $bill) }}" data-confirm="Send {{ $bill->bill_no }} back unpaid?">
                        @csrf
                        <label class="form-label" for="reject_note">Send it back</label>
                        <div class="d-flex gap-2">
                            <input class="form-control" type="text" maxlength="500" id="reject_note" name="decision_note"
                                   placeholder="Why not">
                            <button class="btn btn-outline-secondary text-nowrap" type="submit">Send back</button>
                        </div>
                    </form>
                </div>
            </div>
        @else
            <form method="POST" action="{{ route('business.utilities.pay', $bill) }}" data-confirm="Pay {{ $bill->bill_no }} for {{ $money($bill->amount) }}?">
                @csrf
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label" for="money_account_id">Pay from</label>
                        <select class="form-select @error('money_account_id') is-invalid @enderror" id="money_account_id" name="money_account_id">
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" @selected((int) old('money_account_id') === $account->id)>
                                    {{ $account->code }} — {{ $account->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('money_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="paid_on">Paid on</label>
                        <input class="form-control" type="date" id="paid_on" name="paid_on" value="{{ old('paid_on', now()->toDateString()) }}">
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-cash-coin" aria-hidden="true"></i> Pay {{ $money($bill->amount) }}
                        </button>
                    </div>
                </div>
            </form>
        @endif
    </div>
@endif

<div class="erp-split">
    <div class="erp-card">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">The bill itself</h2>
                <p class="erp-card-sub">As filed, with the reading the provider charged.</p>
            </div>
        </div>
        <table class="table erp-table mb-0 align-middle">
            <tbody>
                <tr>
                    <th scope="row">Provider</th>
                    <td>
                        {{ $bill->provider?->name ?? '—' }}
                        <div class="erp-td-muted">
                            {{ $bill->provider?->familyLabel() }}
                            @if ($bill->provider?->consumer_no) · consumer no {{ $bill->provider->consumer_no }} @endif
                            @if ($bill->provider?->meter_no) · meter {{ $bill->provider->meter_no }} @endif
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Premises</th>
                    <td>{{ $bill->provider?->premises ?? '—' }} <span class="erp-td-muted">· {{ $bill->branch?->name ?? 'company-wide' }}</span></td>
                </tr>
                <tr>
                    <th scope="row">Month covered</th>
                    <td>{{ $bill->periodLabel() }}</td>
                </tr>
                <tr>
                    <th scope="row">Meter reading</th>
                    <td>{{ $bill->meter_reading !== null ? number_format((float) $bill->meter_reading, 3) : '—' }}</td>
                </tr>
                <tr>
                    <th scope="row">Consumption</th>
                    <td>{{ $bill->consumption !== null ? number_format((float) $bill->consumption, 2).' '.$bill->consumption_unit : '—' }}</td>
                </tr>
                <tr>
                    <th scope="row">Amount</th>
                    <td class="erp-td-num">{{ $money($bill->amount) }}</td>
                </tr>
                <tr>
                    <th scope="row">Note</th>
                    <td>{{ $bill->narration ?: '—' }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="erp-card">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">What the ledger did</h2>
                <p class="erp-card-sub">One entry on the day the money went, and nothing before it.</p>
            </div>
        </div>

        @if ($bill->journalEntry)
            <table class="table erp-table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Side</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bill->journalEntry->lines as $line)
                        <tr>
                            <td>
                                {{ $line->account?->code }} — {{ $line->account?->name }}
                                @if ($line->narration)<div class="erp-td-muted">{{ $line->narration }}</div>@endif
                            </td>
                            <td><x-ui.status :value="$line->dc" :label="ucfirst($line->dc)" /></td>
                            <td class="erp-td-num text-end">{{ $money($line->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="p-3">
                <x-ui.empty
                    icon="bi-journal-x"
                    title="Nothing has been posted"
                    text="A filed bill is an obligation, not an entry. The ledger hears about it when the money actually leaves — and only then." />
            </div>
        @endif
    </div>
</div>

@if ($canManage && $bill->isOpen())
    <form class="erp-card mt-3" method="POST" action="{{ route('business.utilities.void', $bill) }}"
          data-confirm="Void {{ $bill->bill_no }}? The row stays on the register with your reason.">
        @csrf
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Void this bill</h2>
                <p class="erp-card-sub">For a bill that should never have been filed — a duplicate, a wrong meter, an account that is not ours. A paid bill cannot be voided: the ledger has the money, and undoing that is a reversal with a reason, not a flag.</p>
            </div>
        </div>
        <div class="row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label" for="void_reason">Why it is being voided</label>
                <input class="form-control @error('void_reason') is-invalid @enderror" type="text" maxlength="500"
                       id="void_reason" name="void_reason" value="{{ old('void_reason') }}"
                       placeholder="Duplicate of UB-000012" required>
                @error('void_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <button class="btn btn-outline-danger" type="submit"><i class="bi bi-slash-circle" aria-hidden="true"></i> Void the bill</button>
            </div>
        </div>
    </form>
@endif

@if ($siblings->isNotEmpty())
    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Earlier bills from this provider</h2>
                <p class="erp-card-sub">Useful for the comparison nobody asks for until the figure looks wrong.</p>
            </div>
        </header>
        <div class="table-responsive">
            <table class="table erp-table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Bill</th>
                        <th>Month</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($siblings as $sibling)
                        <tr>
                            <td><a class="erp-cell-strong" href="{{ route('business.utilities.show', $sibling) }}">{{ $sibling->bill_no }}</a></td>
                            <td>{{ $sibling->periodLabel() }}</td>
                            <td class="erp-td-num text-end">{{ $money($sibling->amount) }}</td>
                            <td><x-ui.status :value="$sibling->state()" :label="$sibling->stateLabel()" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<x-ui.related-pages />
