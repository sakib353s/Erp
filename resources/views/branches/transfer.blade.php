@php
    /* §12-08 — the branch's transfer desk: what has crossed this branch's
       boundary, and the form that moves more through the stock engine. */
    $money = fn ($value): string => '৳'.number_format((float) $value, 2);
    $own = $warehouses->pluck('id')->all();
@endphp

<x-ui.page-header
    eyebrow="Business Management · Branches · Transfer"
    title="Moving stock between {{ $branch->name }} and the rest"
    subtitle="Stock leaves one of this branch's warehouses and arrives at another branch's. It is the same transfer the inventory desk raises — valued at what the goods cost where they are leaving from, held for approval above the limit, dispatched from the stockroom and received at the far end — seen here from the branch rather than from the shelf."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('branches.show', $branch) }}">
            <i class="bi bi-building" aria-hidden="true"></i> Branch profile
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('inventory.transfers.index') }}">
            <i class="bi bi-arrow-left-right" aria-hidden="true"></i> Stock desk
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('branches.compare') }}">
            <i class="bi bi-bar-chart-line" aria-hidden="true"></i> Comparison
        </a>
    </x-slot:actions>
</x-ui.page-header>

@unless ($perm('branches.transfer'))
    <div class="erp-note mb-3">
        <i class="bi bi-lock" aria-hidden="true"></i>
        You may read this desk. Raising a transfer needs the <code>branches.transfer</code> key —
        ask an administrator if moving stock between branches is part of your job.
    </div>
@endunless

<div class="erp-card mb-3">
    <div class="erp-card-head">
        <div>
            <h2 class="erp-h3 mb-1">What has moved</h2>
            <p class="text-body-secondary small mb-0">
                Every transfer with one end in this branch, newest first. “Out” means the goods left
                here; “in” means they arrived here.
            </p>
        </div>
        <span class="erp-chip erp-chip-outline">Approval above {{ $money($threshold) }}</span>
    </div>

    @if ($transfers->isEmpty())
        <x-ui.empty
            icon="bi-arrow-left-right"
            title="Nothing has crossed this branch's boundary yet"
            text="Raise the first transfer below; it will be filed against your own branch by the stock engine." />
    @else
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Transfer</th>
                        <th scope="col">Direction</th>
                        <th scope="col">From</th>
                        <th scope="col">To</th>
                        <th scope="col">Date</th>
                        <th scope="col" class="text-end">Lines</th>
                        <th scope="col" class="text-end">Value</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transfers as $transfer)
                        @php($out = in_array($transfer->from_warehouse_id, $own, true))
                        <tr>
                            <td><code>{{ $transfer->transfer_no }}</code></td>
                            <td>
                                <span class="erp-chip {{ $out ? 'erp-chip-outline' : 'erp-chip-soft' }}">
                                    {{ $out ? 'Out' : 'In' }}
                                </span>
                            </td>
                            <td>
                                {{ $transfer->fromWarehouse?->name ?? '—' }}
                                @if (($transfer->fromWarehouse?->branch?->name) && ! $out)
                                    <div class="text-body-secondary small">{{ $transfer->fromWarehouse->branch->name }}</div>
                                @endif
                            </td>
                            <td>
                                {{ $transfer->toWarehouse?->name ?? '—' }}
                                @if (($transfer->toWarehouse?->branch?->name) && $out)
                                    <div class="text-body-secondary small">{{ $transfer->toWarehouse->branch->name }}</div>
                                @endif
                            </td>
                            <td>{{ $transfer->transfer_date?->format('d M Y') }}</td>
                            <td class="text-end">{{ $transfer->lines->count() }}</td>
                            <td class="text-end">{{ $money($transfer->total_value) }}</td>
                            <td><x-ui.status :value="$transfer->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $transfers->links() }}</div>
    @endif
</div>

@if ($canTransfer)
    <div class="erp-card">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-h3 mb-1">Raise a transfer</h2>
                <p class="text-body-secondary small mb-0">
                    Source: this branch. Destination: another branch's warehouse. Above
                    {{ $money($threshold) }} the transfer waits for a second person and nothing
                    leaves the warehouse until it is approved.
                </p>
            </div>
        </div>

        @if ($warehouses->isEmpty() || $destinations->isEmpty())
            <x-ui.empty
                icon="bi-box-seam"
                title="This branch cannot transfer yet"
                text="{{ $warehouses->isEmpty() ? 'It has no warehouse of its own to send from.' : 'No other branch has a warehouse to receive into.' }}" />
        @else
            <form method="POST" action="{{ route('branches.transfer.store', $branch) }}" data-confirm="Raise this branch transfer?">
                @csrf

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="from_warehouse_id">From (this branch)</label>
                        <select class="form-select @error('from_warehouse_id') is-invalid @enderror" id="from_warehouse_id" name="from_warehouse_id" required>
                            <option value="">Choose a warehouse…</option>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected((int) old('from_warehouse_id') === $warehouse->id)>
                                    {{ $warehouse->name }}{{ $warehouse->code ? ' · '.$warehouse->code : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('from_warehouse_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="to_warehouse_id">To (another branch)</label>
                        <select class="form-select @error('to_warehouse_id') is-invalid @enderror" id="to_warehouse_id" name="to_warehouse_id" required>
                            <option value="">Choose a warehouse…</option>
                            @foreach ($destinations as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected((int) old('to_warehouse_id') === $warehouse->id)>
                                    {{ $warehouse->name }} — {{ $warehouse->branch?->name ?? 'another branch' }}
                                </option>
                            @endforeach
                        </select>
                        @error('to_warehouse_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="transfer_date">Date</label>
                        <input class="form-control @error('transfer_date') is-invalid @enderror" type="date" id="transfer_date"
                               name="transfer_date" value="{{ old('transfer_date', now()->toDateString()) }}" required>
                        @error('transfer_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="narration">Narration</label>
                        <input class="form-control @error('narration') is-invalid @enderror" type="text" id="narration"
                               name="narration" maxlength="500" value="{{ old('narration') }}"
                               placeholder="Why the goods are moving — “Uttara ran out of 40-count cartons”.">
                        @error('narration')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mt-4">
                    <p class="erp-field-label">Lines</p>
                    @error('lines')<div class="erp-note erp-note-danger mb-2">{{ $message }}</div>@enderror

                    @php($lineRows = old('lines', [['product_id' => '', 'qty_sent' => '', 'unit_cost' => '']]))
                    <div class="table-responsive">
                        <table class="table erp-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col" style="min-width: 18rem;">Product</th>
                                    <th scope="col" style="width: 10rem;">Quantity</th>
                                    <th scope="col" style="width: 12rem;">Unit cost (optional)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($lineRows as $index => $line)
                                    <tr>
                                        <td>
                                            <label class="visually-hidden" for="product_{{ $index }}">Product</label>
                                            <select class="form-select form-select-sm @error("lines.{$index}.product_id") is-invalid @enderror"
                                                    id="product_{{ $index }}" name="lines[{{ $index }}][product_id]" required>
                                                <option value="">Choose a product…</option>
                                                @foreach ($products as $product)
                                                    <option value="{{ $product->id }}" @selected((int) ($line['product_id'] ?? 0) === $product->id)>
                                                        {{ $product->sku }} — {{ $product->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error("lines.{$index}.product_id")<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </td>
                                        <td>
                                            <label class="visually-hidden" for="qty_{{ $index }}">Quantity</label>
                                            <input class="form-control form-control-sm @error("lines.{$index}.qty_sent") is-invalid @enderror"
                                                   id="qty_{{ $index }}" name="lines[{{ $index }}][qty_sent]" inputmode="decimal"
                                                   value="{{ $line['qty_sent'] ?? '' }}" required>
                                            @error("lines.{$index}.qty_sent")<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </td>
                                        <td>
                                            <label class="visually-hidden" for="cost_{{ $index }}">Unit cost</label>
                                            <input class="form-control form-control-sm @error("lines.{$index}.unit_cost") is-invalid @enderror"
                                                   id="cost_{{ $index }}" name="lines[{{ $index }}][unit_cost]" inputmode="decimal"
                                                   value="{{ $line['unit_cost'] ?? '' }}"
                                                   placeholder="Valued at source">
                                            @error("lines.{$index}.unit_cost")<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="text-body-secondary small mt-2 mb-0">
                        A line with no cost is valued at what the goods cost in the source warehouse,
                        which is the figure the approval threshold judges.
                    </p>
                </div>

                <div class="mt-2">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-arrow-left-right" aria-hidden="true"></i> Raise transfer
                    </button>
                </div>
            </form>
        @endif
    </div>
@endif

<x-ui.related-pages />
