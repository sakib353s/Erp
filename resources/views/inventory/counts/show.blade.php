@extends('layouts.app')

@section('page_title', 'Count sheet '.$count->code)

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Stock"
        title="Count sheet {{ $count->code }}"
        :subtitle="$count->warehouse?->name.' · '.$count->count_date?->format('d M Y').' · '.($count->scope === \App\Domain\Inventory\StockCount::SCOPE_CYCLE ? 'cycle count' : 'full count')"
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.counts.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All counts
            </a>
            @if ($count->adjustment && $perm('inventory.adjustments.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.adjustments.index') }}">
                    <i class="bi bi-sliders" aria-hidden="true"></i> {{ $count->adjustment->adjustment_no }}
                </a>
            @endif
            @if ($count->isOpen() && $perm('inventory.counts.create'))
                <form method="POST" action="{{ route('inventory.counts.cancel', $count) }}"
                      class="d-flex gap-1" data-confirm="Cancelling corrects nothing — the sheet is abandoned as it stands. Continue?">
                    @csrf
                    <input class="form-control form-control-sm" name="cancel_note" maxlength="500"
                           placeholder="Why abandoned (optional)" aria-label="Reason for cancelling">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Cancel sheet</button>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Sheet status" :value="\App\Domain\Inventory\StockCount::STATUSES[$count->status] ?? $count->status"
                  icon="bi-clipboard-check"
                  :hint="$count->isOpen() ? 'Counting — nothing has changed yet' : ($count->posted_at?->format('d M Y H:i').' by '.($count->poster?->name ?? '—'))" />
        <x-ui.kpi label="Lines on the sheet" :value="number_format($count->line_count)" icon="bi-list-ol"
                  hint="{{ number_format($count->line_count - $count->counted_lines) }} not counted yet" />
        <x-ui.kpi label="Counted" :value="number_format($count->counted_lines)" icon="bi-pencil-square"
                  hint="Only counted lines can be posted" />
        <x-ui.kpi label="With a difference" :value="number_format($count->variance_lines)" icon="bi-exclamation-triangle"
                  hint="Lines where the shelf disagreed with the sheet" />
        @if ($count->isPosted())
            <x-ui.kpi label="Applied to stock" icon="bi-arrow-left-right"
                      :value="number_format($count->lines->filter(fn ($l) => abs((float) $l->posted_delta) > 1e-9)->count())"
                      hint="Lines the ledger had to move to reach the counted figure" />
        @endif
        <x-ui.kpi label="Value moved" icon="bi-currency-exchange"
                  :value="$count->isPosted() ? number_format((float) $count->variance_value, 2) : '—'"
                  :hint="$count->isPosted() ? 'What the ledger actually wrote off or took on' : 'Nothing moves until the sheet is posted'" />
    </div>

    @if ($blind)
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-eye-slash" aria-hidden="true"></i>
            <div>
                <strong>Blind count.</strong> The balance the ledger expects is hidden on this screen, because a
                counter who can see the expected number tends to find it. Write down what is actually on the shelf.
            </div>
        </div>
    @endif

    @if ($count->status === \App\Domain\Inventory\StockCount::STATUS_CANCELLED)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-x-octagon" aria-hidden="true"></i>
            <div>
                <strong>This sheet was cancelled.</strong> Nothing was corrected.
                @if ($count->cancel_note) Reason given: {{ $count->cancel_note }}. @endif
            </div>
        </div>
    @endif

    @if ($count->notes)
        <div class="erp-note mb-3">
            <i class="bi bi-journal-text" aria-hidden="true"></i>
            <div>{{ $count->notes }}</div>
        </div>
    @endif

    @error('counted') <div class="alert alert-danger">{{ $message }}</div> @enderror
    @error('cancel_note') <div class="alert alert-danger">{{ $message }}</div> @enderror

    @if ($count->isOpen() && $perm('inventory.counts.create'))
        <form method="POST" action="{{ route('inventory.counts.save', $count) }}">
            @csrf
    @endif

    <x-ui.table-shell :count="$count->lines->count().' line'.($count->lines->count() === 1 ? '' : 's')"
                      title="{{ $count->isOpen() ? 'What is on the shelf' : 'What the count found' }}">
        <thead>
            <tr>
                <th>#</th>
                <th>Product</th>
                @unless ($blind)
                    <th class="erp-th-num">Ledger said</th>
                @endunless
                <th class="erp-th-num">Counted</th>
                @unless ($blind)
                    <th class="erp-th-num">Difference</th>
                @endunless
                @if ($count->isPosted())
                    <th class="erp-th-num">Applied to stock</th>
                    <th class="erp-th-num">Value</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($count->lines as $line)
                <tr>
                    <td data-label="#" class="erp-td-muted">{{ $line->line_no }}</td>
                    <td data-label="Product">
                        <span class="erp-cell-strong">{{ $line->product?->name ?? 'Product removed' }}</span>
                        <span class="d-block erp-td-muted">{{ $line->product?->sku }}</span>
                    </td>
                    @unless ($blind)
                        <td data-label="Ledger said" class="erp-td-num erp-td-muted">{{ number_format((float) $line->system_qty, 4) }}</td>
                    @endunless
                    <td data-label="Counted" class="erp-td-num">
                        @if ($count->isOpen() && $perm('inventory.counts.create'))
                            <input class="form-control form-control-sm text-end" type="number" step="0.0001" min="0"
                                   name="counted[{{ $line->product_id }}]"
                                   value="{{ $line->counted_qty !== null ? number_format((float) $line->counted_qty, 4, '.', '') : '' }}"
                                   placeholder="{{ $line->isCounted() ? '' : 'not counted' }}"
                                   aria-label="Counted quantity for {{ $line->product?->sku }}">
                        @else
                            {{ $line->isCounted() ? number_format((float) $line->counted_qty, 4) : '—' }}
                        @endif
                    </td>
                    @unless ($blind)
                        <td data-label="Difference" class="erp-td-num">
                            @if (! $line->isCounted())
                                <span class="erp-td-muted">—</span>
                            @elseif ($line->hasVariance())
                                <span class="erp-amount {{ (float) $line->variance > 0 ? 'erp-amount-pos' : 'erp-amount-neg' }}">
                                    {{ (float) $line->variance > 0 ? '+' : '' }}{{ number_format((float) $line->variance, 4) }}
                                </span>
                            @else
                                <span class="erp-td-muted">agreed</span>
                            @endif
                        </td>
                    @endunless
                    @if ($count->isPosted())
                        <td data-label="Applied to stock" class="erp-td-num">
                            @if (abs((float) $line->posted_delta) < 1e-9)
                                <span class="erp-td-muted">nothing</span>
                            @else
                                {{ (float) $line->posted_delta > 0 ? '+' : '' }}{{ number_format((float) $line->posted_delta, 4) }}
                            @endif
                        </td>
                        <td data-label="Value" class="erp-td-num">{{ number_format((float) $line->value, 2) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
        @if ($count->lines->isNotEmpty())
            <tfoot>
                <tr>
                    <th colspan="2">Page total</th>
                    @unless ($blind)
                        <th class="erp-th-num">{{ number_format((float) $count->lines->sum('system_qty'), 4) }}</th>
                    @endunless
                    <th class="erp-th-num">{{ number_format((float) $count->lines->whereNotNull('counted_qty')->sum('counted_qty'), 4) }}</th>
                    @unless ($blind)
                        <th class="erp-th-num">{{ number_format((float) $count->lines->sum('variance'), 4) }}</th>
                    @endunless
                    @if ($count->isPosted())
                        <th class="erp-th-num">{{ number_format((float) $count->lines->sum('posted_delta'), 4) }}</th>
                        <th class="erp-th-num">{{ number_format((float) $count->lines->sum('value'), 2) }}</th>
                    @endif
                </tr>
            </tfoot>
        @endif
    </x-ui.table-shell>

    @if ($count->isOpen() && $perm('inventory.counts.create'))
            <div class="d-flex gap-2 mt-3">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-save" aria-hidden="true"></i> Save counted quantities
                </button>
                <span class="erp-td-muted small align-self-center">
                    Leaving a box empty means "not counted yet" — it is not the same as counting zero.
                </span>
            </div>
        </form>
    @endif

    @if ($count->isOpen() && $perm('inventory.counts.post'))
        <div class="erp-note erp-note-warn mt-3">
            <i class="bi bi-shield-lock" aria-hidden="true"></i>
            <div>
                <strong>Posting the sheet changes stock.</strong> Every counted line becomes the truth for that
                product: the ledger is adjusted to the counted figure, the difference is posted as one stock
                adjustment with this sheet as its reason, and the sheet closes. Lines nobody counted are left alone.
            </div>
        </div>

        <form method="POST" action="{{ route('inventory.counts.post', $count) }}" class="mt-2"
              data-confirm="Post {{ $count->code }}? The counted figures replace the ledger for every counted line.">
            @csrf
            <button class="btn btn-primary" type="submit" @disabled($count->counted_lines === 0)>
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Post the count
            </button>
            @if ($count->counted_lines === 0)
                <span class="erp-td-muted small ms-2">Nothing has been counted yet.</span>
            @endif
        </form>
    @endif

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
