@extends('layouts.app')

@section('page_title', 'Pick list '.$list->code)

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Warehouse"
        title="Pick list {{ $list->code }}"
        :subtitle="$list->warehouse?->name.' · '.($list->order ? 'for order '.$list->order->order_no : 'manual list').' · raised by '.($list->creator?->name ?? '—')"
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.pick-lists.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All pick lists
            </a>
            @if ($list->order)
                <a class="btn btn-outline-secondary" href="{{ route('sales.orders.show', $list->order) }}">
                    <i class="bi bi-cart-check" aria-hidden="true"></i> {{ $list->order->order_no }}
                </a>
            @endif
            <button class="btn btn-outline-secondary" type="button" onclick="window.print()">
                <i class="bi bi-printer" aria-hidden="true"></i> Print
            </button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Status" :value="$list->stateLabel()" icon="bi-basket"
                  :hint="$list->assignee ? 'With '.$list->assignee->name : 'Nobody has been handed this walk yet'" />
        <x-ui.kpi label="Lines" :value="number_format($list->lines->count())" icon="bi-list-ol"
                  hint="{{ $list->pendingLines() }} line(s) not finished" />
        <x-ui.kpi label="Picked" :value="number_format($list->pickedQty(), 2)" icon="bi-hand-index"
                  hint="of {{ number_format($list->requiredQty(), 2) }} asked for" />
        <x-ui.kpi label="Lines with no bin" :value="number_format($list->linesWithoutBin())" icon="bi-question-circle"
                  :hint="$list->linesWithoutBin() > 0 ? 'Somebody has to say where these live' : 'Every line knows where to walk'" />
        @if ($list->completed_at)
            <x-ui.kpi label="Finished" :value="$list->completed_at->format('d M Y H:i')" icon="bi-check2-circle"
                      hint="Nothing moved in the ledger — dispatch does that" />
        @endif
    </div>

    @if ($list->isCancelled())
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-x-octagon" aria-hidden="true"></i>
            <div>
                <strong>This walk was abandoned.</strong> No stock was affected — a pick list never moves stock.
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

    @error('picked') <div class="alert alert-danger">{{ $message }}</div> @enderror
    @error('complete') <div class="alert alert-danger">{{ $message }}</div> @enderror
    @error('cancel_reason') <div class="alert alert-danger">{{ $message }}</div> @enderror
    @error('assign') <div class="alert alert-danger">{{ $message }}</div> @enderror

    @if ($list->isOpen() && $perm('warehouses.update'))
        <form method="POST" action="{{ route('inventory.pick-lists.pick', $list) }}">
            @csrf
    @endif

    <x-ui.table-shell :count="$list->lines->count().' line'.($list->lines->count() === 1 ? '' : 's')"
                      title="{{ $list->isOpen() ? 'What to walk' : 'What was picked' }}">
        <thead>
            <tr>
                <th>#</th>
                <th>Product</th>
                <th>Bin</th>
                <th>Batch to take</th>
                <th class="erp-th-num">Asked for</th>
                <th class="erp-th-num">Picked</th>
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
                    <td data-label="Bin">
                        @if ($line->bin)
                            <span class="erp-cell-strong">{{ $line->bin->code }}</span>
                            @if ($line->bin->zone)
                                <span class="d-block erp-td-muted">{{ $line->bin->zone->name }}</span>
                            @endif
                        @else
                            <span class="erp-chip erp-chip-warn">no bin recorded</span>
                        @endif
                    </td>
                    <td data-label="Batch to take">
                        @if ($line->batch)
                            <span class="erp-cell-strong">{{ $line->batch->batch_no }}</span>
                            <span class="d-block erp-td-muted">
                                {{ $line->batch->expires_on ? 'expires '.$line->batch->expires_on->format('d M Y') : 'no expiry date' }}
                            </span>
                        @else
                            <span class="erp-td-muted">—</span>
                        @endif
                    </td>
                    <td data-label="Asked for" class="erp-td-num">{{ number_format((float) $line->quantity, 4) }}</td>
                    <td data-label="Picked" class="erp-td-num">
                        @if ($list->isOpen() && $perm('warehouses.update'))
                            <input class="form-control form-control-sm text-end" type="number" step="0.0001" min="0"
                                   name="picked[{{ $line->id }}]"
                                   value="{{ (float) $line->picked_quantity > 0 ? number_format((float) $line->picked_quantity, 4, '.', '') : '' }}"
                                   placeholder="not yet"
                                   aria-label="Picked quantity for {{ $line->product?->sku }}">
                        @else
                            {{ number_format((float) $line->picked_quantity, 4) }}
                        @endif
                    </td>
                    @if ($list->isOpen() && $perm('warehouses.update'))
                        <td>
                            <input class="form-control form-control-sm" type="text" maxlength="500"
                                   name="line_notes[{{ $line->id }}]" value="{{ $line->note }}"
                                   placeholder="found, short, damaged…"
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
                    <th class="erp-th-num">{{ number_format($list->requiredQty(), 4) }}</th>
                    <th class="erp-th-num">{{ number_format($list->pickedQty(), 4) }}</th>
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
                    <i class="bi bi-save" aria-hidden="true"></i> Save what came off the shelf
                </button>
                <span class="erp-td-muted small align-self-center">
                    An empty box means "not finished", not "picked nothing". Nobody can pick more than was asked for.
                </span>
            </div>
        </form>
    @endif

    @if ($list->isOpen() && $perm('warehouses.update'))
        <div class="erp-note erp-note-info mt-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                <strong>Finishing the walk does not move stock.</strong>
                It says the goods are off the shelf and on the dock, and moves the order to ready-to-ship when the order
                can go there. The ledger changes at dispatch, where it belongs.
            </div>
        </div>

        <form method="POST" action="{{ route('inventory.pick-lists.complete', $list) }}" class="mt-2"
              data-confirm="Finish {{ $list->code }}? The goods are off the shelf and the order may move to ready-to-ship.">
            @csrf
            <button class="btn btn-primary" type="submit" @disabled($list->pickedQty() <= 0)>
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Finish the walk
            </button>
            @if ($list->pickedQty() <= 0)
                <span class="erp-td-muted small ms-2">Nothing has been picked yet.</span>
            @endif
        </form>
    @endif

    @if ($list->isOpen() && $perm('warehouses.update'))
        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <h2 class="erp-card-title">Whose walk is it</h2>
            </header>
            <form method="POST" action="{{ route('inventory.pick-lists.assign', $list) }}" class="d-flex gap-2 flex-wrap">
                @csrf
                <select class="form-select" name="assigned_to" style="max-width: 280px" aria-label="Picker">
                    <option value="">— nobody —</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected((int) $list->assigned_to === (int) $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
                <button class="btn btn-outline-secondary" type="submit">Hand over</button>
                <span class="erp-td-muted small align-self-center">Handing it to nobody puts the list back to unassigned.</span>
            </form>

            <form method="POST" action="{{ route('inventory.pick-lists.cancel', $list) }}" class="d-flex gap-2 flex-wrap mt-3"
                  data-confirm="Abandon {{ $list->code }}? Nothing in the ledger moves either way — but the goods may still be waiting.">
                @csrf
                <input class="form-control" name="cancel_reason" maxlength="500" style="max-width: 420px"
                       placeholder="Why the walk is being abandoned" aria-label="Reason for cancelling">
                <button class="btn btn-outline-danger" type="submit">Abandon the walk</button>
            </form>
        </section>
    @endif

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
