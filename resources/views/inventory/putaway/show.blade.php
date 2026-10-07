@extends('layouts.app')

@section('page_title', 'Putaway list '.$list->code)

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Warehouse"
        title="Putaway list {{ $list->code }}"
        :subtitle="$list->warehouse?->name.' · '.($list->receipt ? 'from receipt '.$list->receipt->code : 'manual move').' · raised by '.($list->creator?->name ?? '—')"
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.putaway-lists.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All putaway lists
            </a>
            @if ($list->receipt)
                <a class="btn btn-outline-secondary" href="{{ route('purchase.receipts.show', $list->receipt) }}">
                    <i class="bi bi-receipt" aria-hidden="true"></i> {{ $list->receipt->code }}
                </a>
            @endif
            <button class="btn btn-outline-secondary" type="button" onclick="window.print()">
                <i class="bi bi-printer" aria-hidden="true"></i> Print
            </button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Status" :value="$list->stateLabel()" icon="bi-box-arrow-in-down"
                  :hint="$list->assignee ? 'With '.$list->assignee->name : 'Nobody has been handed this job yet'" />
        <x-ui.kpi label="Lines" :value="number_format($list->lines->count())" icon="bi-list-ol"
                  hint="{{ $list->pendingLines() }} line(s) still on the dock" />
        <x-ui.kpi label="Placed" :value="number_format($list->placedQty(), 2)" icon="bi-box-seam"
                  hint="of {{ number_format($list->requiredQty(), 2) }} received on this list ({{ $list->progressPct() }}%)" />
        <x-ui.kpi label="Lines with no target bin" :value="number_format($list->linesWithoutBin())" icon="bi-signpost-split"
                  :hint="$list->linesWithoutBin() > 0 ? 'The system has no home for these yet' : 'Every line has a suggested bin'" />
        @if ($list->completed_at)
            <x-ui.kpi label="Finished" :value="$list->completed_at->format('d M Y H:i')" icon="bi-check2-circle"
                      hint="The dock is empty" />
        @endif
    </div>

    @if ($list->isCancelled())
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-x-octagon" aria-hidden="true"></i>
            <div>
                <strong>This putaway was abandoned.</strong> Whatever was already placed stays placed — the goods are where
                they are. The receipt can be put away again on a new list.
                @if ($list->cancel_reason) Reason given: {{ $list->cancel_reason }} @endif
            </div>
        </div>
    @endif

    @if ($list->notes)
        <div class="erp-note mb-3">
            <i class="bi bi-journal-text" aria-hidden="true"></i>
            <div>{{ $list->notes }}</div>
        </div>
    @endif

    @error('placements') <div class="alert alert-danger">{{ $message }}</div> @enderror
    @error('complete') <div class="alert alert-danger">{{ $message }}</div> @enderror
    @error('cancel_reason') <div class="alert alert-danger">{{ $message }}</div> @enderror
    @error('assign') <div class="alert alert-danger">{{ $message }}</div> @enderror

    @if ($list->isOpen() && $perm('warehouses.update'))
        <form method="POST" action="{{ route('inventory.putaway-lists.place', $list) }}">
            @csrf
    @endif

    <x-ui.table-shell :count="$list->lines->count().' line'.($list->lines->count() === 1 ? '' : 's')"
                      title="{{ $list->isOpen() ? 'Where these goods go' : 'Where these goods went' }}">
        <thead>
            <tr>
                <th>#</th>
                <th>Product</th>
                <th>Arrived as</th>
                <th>Bin</th>
                <th class="erp-th-num">Quantity</th>
                @if ($list->isOpen() && $perm('warehouses.update'))
                    <th>Note</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($list->lines as $line)
                <tr>
                    <td data-label="#" class="erp-td-muted">{{ $line->line_no }}</td>
                    <td data-label="Product">
                        <span class="erp-cell-strong">{{ $line->product?->name ?? 'Product removed' }}</span>
                        <span class="d-block erp-td-muted">{{ $line->product?->sku }}</span>
                    </td>
                    <td data-label="Arrived as" class="erp-td-muted">
                        @if ($line->batch)
                            {{ $line->batch->batch_no }}
                            @if ($line->batch->expires_on)
                                <span class="d-block small">expires {{ $line->batch->expires_on->format('d M Y') }}</span>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td data-label="Bin">
                        @if ($list->isOpen() && $perm('warehouses.update'))
                            <select class="form-select form-select-sm" name="placements[{{ $line->id }}][bin_id]"
                                    aria-label="Bin for {{ $line->product?->sku }}">
                                <option value="">— choose a bin —</option>
                                @foreach ($bins as $bin)
                                    <option value="{{ $bin->id }}"
                                        @selected((int) old("placements.{$line->id}.bin_id", $line->placed_bin_id ?? $line->warehouse_bin_id) === (int) $bin->id)>
                                        {{ $bin->code }}@if ($bin->zone) · {{ $bin->zone->name }}@endif
                                    </option>
                                @endforeach
                            </select>
                            @if (! $line->hasBin())
                                <span class="erp-chip erp-chip-warn mt-1">no suggestion yet</span>
                            @endif
                        @else
                            @php($where = $line->effectiveBin())
                            @if ($where)
                                <span class="erp-cell-strong">{{ $where->code }}</span>
                                @if ($line->placed_bin_id && (int) $line->placed_bin_id !== (int) $line->warehouse_bin_id)
                                    <span class="d-block erp-td-muted small">planned {{ $line->bin?->code ?? '—' }}</span>
                                @endif
                            @else
                                <span class="erp-chip erp-chip-warn">no bin recorded</span>
                            @endif
                        @endif
                    </td>
                    <td data-label="Quantity" class="erp-td-num">
                        <span class="erp-td-muted small d-block">arrived {{ number_format((float) $line->quantity, 4) }}</span>
                        <span class="d-block">placed {{ number_format((float) $line->placed_quantity, 4) }}</span>
                        @if ($list->isOpen() && $perm('warehouses.update'))
                            <input class="form-control form-control-sm text-end mt-1" type="number" step="0.0001" min="0"
                                   name="placements[{{ $line->id }}][qty]"
                                   placeholder="{{ number_format($line->shortfall(), 4, '.', '') }}"
                                   aria-label="Quantity placed for {{ $line->product?->sku }}">
                        @endif
                    </td>
                    @if ($list->isOpen() && $perm('warehouses.update'))
                        <td>
                            <input class="form-control form-control-sm" type="text" maxlength="500"
                                   name="placements[{{ $line->id }}][note]" value="{{ $line->note }}"
                                   placeholder="pallet, damaged, over-ordered…"
                                   aria-label="Note for {{ $line->product?->sku }}">
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
        @if ($list->lines->isNotEmpty())
            <tfoot>
                <tr>
                    <th colspan="4">Totals</th>
                    <th class="erp-th-num">
                        arrived {{ number_format($list->requiredQty(), 4) }} · placed {{ number_format($list->placedQty(), 4) }}
                    </th>
                    @if ($list->isOpen() && $perm('warehouses.update'))
                        <th></th>
                    @endif
                </tr>
            </tfoot>
        @endif
    </x-ui.table-shell>

    @if ($list->isOpen() && $perm('warehouses.update'))
            <div class="d-flex gap-2 mt-3">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-save" aria-hidden="true"></i> Save what was put away
                </button>
                <span class="erp-td-muted small align-self-center">
                    A quantity without a bin is refused: a putaway that does not say where the goods are teaches the map
                    nothing.
                </span>
            </div>
        </form>
    @endif

    @if ($list->isOpen() && $perm('warehouses.update'))
        <div class="erp-note erp-note-info mt-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                <strong>Placing goods writes the bin map.</strong>
                The first place a product is put away in a warehouse becomes its pick face; later placements are recorded
                without moving it. Nothing here changes stock — the receipt already did that when it was posted.
            </div>
        </div>

        <form method="POST" action="{{ route('inventory.putaway-lists.complete', $list) }}" class="mt-2"
              data-confirm="Finish {{ $list->code }}? A putaway is finished only when every line has been placed.">
            @csrf
            <button class="btn btn-primary" type="submit" @disabled($list->placedQty() <= 0)>
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Finish the putaway
            </button>
            @if ($list->pendingLines() > 0)
                <span class="erp-td-muted small ms-2">{{ $list->pendingLines() }} line(s) still on the dock.</span>
            @endif
        </form>
    @endif

    @if ($list->isOpen() && $perm('warehouses.update'))
        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <h2 class="erp-card-title">Whose job is it</h2>
            </header>
            <form method="POST" action="{{ route('inventory.putaway-lists.assign', $list) }}" class="d-flex gap-2 flex-wrap">
                @csrf
                <select class="form-select" name="assigned_to" style="max-width: 280px" aria-label="Receiver">
                    <option value="">— nobody —</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected((int) $list->assigned_to === (int) $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
                <button class="btn btn-outline-secondary" type="submit">Hand over</button>
                <span class="erp-td-muted small align-self-center">Handing it to nobody puts the list back to unassigned.</span>
            </form>

            <form method="POST" action="{{ route('inventory.putaway-lists.cancel', $list) }}" class="d-flex gap-2 flex-wrap mt-3"
                  data-confirm="Abandon {{ $list->code }}? Whatever is already placed stays placed.">
                @csrf
                <input class="form-control" name="cancel_reason" maxlength="500" style="max-width: 420px"
                       placeholder="Why the putaway is being abandoned" aria-label="Reason for cancelling">
                <button class="btn btn-outline-danger" type="submit">Abandon the putaway</button>
            </form>
        </section>
    @endif

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
