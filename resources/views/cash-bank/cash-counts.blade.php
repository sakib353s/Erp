@extends('layouts.app')

@section('page_title', 'Cash count')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Cash management · Cash count"
        title="Counting the drawer"
        subtitle="Every other screen here believes the ledger. This one asks what is actually in the tin: the notes and coins are written down, added up, and compared with the books, and the gap — if there is one — is the only thing that posts. Above the company's tolerance the gap waits for somebody other than the person holding the money."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.index') }}">
                <i class="bi bi-cash-stack" aria-hidden="true"></i> Cash in hand
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

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Drawers in this company"
            value="{{ $summary['drawers'] }}"
            icon="bi-safe"
            :hint="$summary['uncounted'].' of them never counted yet'" />
        <x-ui.kpi
            label="Waiting for a signature"
            value="৳ {{ number_format(abs((float) $summary['pending_value']), 2) }}"
            icon="bi-hourglass-split"
            :hint="$summary['pending'].' count(s) — and none of those differences has reached the ledger'" />
        <x-ui.kpi
            label="Short this month"
            value="৳ {{ $summary['short_month'] }}"
            icon="bi-arrow-down-circle"
            hint="Counted less than the books said — posted to Cash Over &amp; Short" />
        <x-ui.kpi
            label="Over this month"
            value="৳ {{ $summary['over_month'] }}"
            icon="bi-arrow-up-circle"
            :hint="$summary['counted_month'].' count(s) posted this month; last one '.($summary['last_on'] ?? 'never')" />
    </div>

    <div class="erp-note erp-note-info mb-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            @if ((float) $summary['tolerance'] > 0)
                A difference of <strong>৳ {{ $summary['tolerance'] }}</strong> or more is recorded but not posted until somebody
                other than the counter approves it. Below that, the difference is corrected as it is counted.
            @else
                Every difference is corrected as it is counted. Set “A counted difference needs approval at or above” in
                <a href="{{ route('settings.show', ['group' => 'cash']) }}">Cash &amp; Bank settings</a>
                to make a large one wait for a second pair of eyes.
            @endif
        </div>
    </div>

    <x-ui.table-shell title="The drawers" :count="$drawers->count().' cash account(s)'">
        <thead>
            <tr>
                <th>Drawer</th>
                <th>Where</th>
                <th class="erp-th-num">Books say</th>
                <th>Last counted</th>
                <th>Waiting</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($drawers as $row)
                <tr>
                    <td>
                        <span class="erp-cell-strong">{{ $row['account']->code }}</span>
                        <div class="erp-td-muted">{{ $row['account']->name }}</div>
                    </td>
                    <td>
                        {{ $row['account']->bank_name ?? 'Cash in hand' }}
                        <div class="erp-td-muted">
                            <a href="{{ route('cash-bank.book', $row['account']->id) }}">read the ledger</a>
                        </div>
                    </td>
                    <td class="erp-td-num">৳ {{ number_format((float) $row['balance'], 2) }}</td>
                    <td>
                        @if ($row['last'])
                            {{ $row['last']->counted_on->toDateString() }}
                            <div class="erp-td-muted">
                                <x-ui.status :value="$row['last']->varianceTone()" :label="$row['last']->balanced() ? 'Exact' : ($row['last']->isShort() ? 'Short ' : 'Over ').$row['last']->varianceLabel()" />
                            </div>
                        @else
                            <span class="erp-td-muted">never counted</span>
                        @endif
                    </td>
                    <td>
                        @if ($row['pending'])
                            <x-ui.status value="pending_approval" label="Awaiting approval" />
                            <div class="erp-td-muted">
                                <a href="{{ route('cash-bank.cash-counts.show', $row['pending']->id) }}">the counted difference</a>
                            </div>
                        @else
                            <span class="erp-td-muted">nothing waiting</span>
                        @endif
                    </td>
                    <td class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="#count-the-drawer">Count it</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty
                            title="No cash account to count"
                            icon="bi-safe"
                            text="A drawer is a cash account in the chart of accounts. Declare one on the cash & bank desk — a bank or wallet is proved against a statement instead, not against a pile of notes." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    @if ($mayCount && $drawers->isNotEmpty())
        <section class="erp-card mt-3" id="count-the-drawer">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Count a drawer</h2>
                    <p class="erp-card-sub">
                        Write the notes down and the desk adds them up against the figure you type — a breakdown that does not
                        add up to the total is refused, because the whole reason for writing denominations down is that somebody
                        can check them afterwards.
                    </p>
                </div>
            </header>
            <div>
                <form method="POST" action="{{ route('cash-bank.cash-counts.store') }}">
                    @csrf
                    <div class="erp-form-grid">
                        <div class="erp-form-field">
                            <label class="form-label" for="account_id">Drawer</label>
                            <select class="form-select" id="account_id" name="account_id" required>
                                @foreach ($drawers as $row)
                                    <option value="{{ $row['account']->id }}" @selected(old('account_id') == $row['account']->id)>
                                        {{ $row['account']->code }} — {{ $row['account']->name }} (books say {{ number_format((float) $row['balance'], 2) }})
                                    </option>
                                @endforeach
                            </select>
                            @error('account_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="branch_id">Kept at</label>
                            <select class="form-select" id="branch_id" name="branch_id">
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>
                                        {{ $branch->name }}{{ $branch->is_default ? ' (head office)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            <small class="form-text">The ledger's figure is read for this branch, not the whole company.</small>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="counted_on">Counted on</label>
                            <input class="form-control" id="counted_on" name="counted_on" type="date" value="{{ old('counted_on', now()->toDateString()) }}" required>
                            @error('counted_on')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="counted_amount">What was in the tin</label>
                            <input class="form-control" id="counted_amount" name="counted_amount" inputmode="decimal" value="{{ old('counted_amount') }}" required>
                            <small class="form-text">Zero is a perfectly good answer — an empty drawer.</small>
                            @error('counted_amount')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <p class="erp-field-label mt-2">Notes and coins (optional, but they have to add up)</p>
                    <div class="erp-count-grid">
                        @foreach ($denominations as $index => [$kind, $face])
                            <div class="erp-count-row">
                                <span class="erp-count-label">৳ {{ rtrim(rtrim(number_format((float) $face, 2, '.', ''), '0'), '.') }} {{ strtolower($kind) }}</span>
                                <input type="hidden" name="denominations[{{ $index }}][kind]" value="{{ $kind }}">
                                <input type="hidden" name="denominations[{{ $index }}][face_value]" value="{{ $face }}">
                                <input class="form-control form-control-sm erp-num" name="denominations[{{ $index }}][quantity]" inputmode="numeric" placeholder="0" value="{{ old('denominations.'.$index.'.quantity') }}">
                            </div>
                        @endforeach
                    </div>

                    <div class="erp-form-grid mt-2">
                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="difference_reason">Why it does not match (when it does not)</label>
                            <input class="form-control" id="difference_reason" name="difference_reason" value="{{ old('difference_reason') }}" maxlength="300" placeholder="Change given wrong at 4pm — the customer came back">
                            <small class="form-text">Required whenever the counted figure differs from the books: the next person to count this drawer reads it.</small>
                            @error('difference_reason')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="notes">Anything else worth writing down</label>
                            <input class="form-control" id="notes" name="notes" value="{{ old('notes') }}" maxlength="300" placeholder="Counted with the shift supervisor present">
                        </div>
                    </div>

                    <button class="btn btn-primary mt-2" type="submit">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Record the count
                    </button>
                </form>
            </div>
        </section>
    @elseif ($drawers->isEmpty())
        <div class="erp-note erp-note-warn mt-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>There is no cash account to count yet — a drawer has to exist in the chart of accounts before anybody can count it.</div>
        </div>
    @endif

    <form class="erp-filterbar mt-3" method="GET" action="{{ route('cash-bank.cash-counts') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="filter-account">Drawer</label>
            <select class="form-select" id="filter-account" name="account">
                <option value="">Every drawer</option>
                @foreach ($drawers as $row)
                    <option value="{{ $row['account']->id }}" @selected($filters['account'] === $row['account']->id)>{{ $row['account']->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="filter-status">State</label>
            <select class="form-select" id="filter-status" name="status">
                <option value="">Everything</option>
                @foreach ($statuses as $key => $label)
                    <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="filter-from">From</label>
            <input class="form-control" id="filter-from" name="from" type="date" value="{{ $filters['from'] }}">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="filter-to">To</label>
            <input class="form-control" id="filter-to" name="to" type="date" value="{{ $filters['to'] }}">
        </div>
        <div class="erp-filterbar-actions">
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a class="btn btn-link" href="{{ route('cash-bank.cash-counts') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell title="Counts made" :count="$counts->count().' count(s) shown'">
        <thead>
            <tr>
                <th>Date</th>
                <th>Drawer</th>
                <th class="erp-th-num">Books said</th>
                <th class="erp-th-num">Counted</th>
                <th class="erp-th-num">Difference</th>
                <th>State</th>
                <th>Counted by</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($counts as $count)
                <tr>
                    <td>{{ $count->counted_on->toDateString() }}</td>
                    <td>
                        <span class="erp-cell-strong">{{ $count->account?->code }}</span>
                        <div class="erp-td-muted">{{ $count->account?->name }}</div>
                    </td>
                    <td class="erp-td-num">৳ {{ number_format((float) $count->expected_amount, 2) }}</td>
                    <td class="erp-td-num">৳ {{ number_format((float) $count->counted_amount, 2) }}</td>
                    <td class="erp-td-num">
                        <x-ui.status :value="$count->varianceTone()" :label="$count->balanced() ? 'Exact' : ($count->isShort() ? 'Short ' : 'Over ').$count->varianceLabel()" />
                    </td>
                    <td>
                        <x-ui.status :value="$count->statusTone()" :label="$count->label()" />
                        @if ($count->journalEntry)
                            <div class="erp-td-muted">posted to the ledger</div>
                        @endif
                    </td>
                    <td>
                        {{ $count->counter?->name ?? '—' }}
                        @if ($count->decider)
                            <div class="erp-td-muted">decided by {{ $count->decider->name }}</div>
                        @endif
                    </td>
                    <td class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('cash-bank.cash-counts.show', $count) }}">The sheet</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty
                            title="No drawer has been counted yet"
                            icon="bi-clipboard-check"
                            text="A count is not a report: it is a person, a tin and a figure written down. The first one is worth more than the next hundred." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <x-ui.related-pages />
@endsection
