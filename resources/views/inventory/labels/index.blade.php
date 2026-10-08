@extends('layouts.app')

@section('page_title', 'Label desk')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Labels & barcodes"
        title="What goes on the sticker"
        subtitle="A label is a promise to a scanner: it carries the product's own code, printed wide enough that a hand scanner reads it first time. Nothing is filed from this screen until you ask for it, and every sheet you do file keeps its own checksum and its own print log."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.labels.barcodes') }}">
                <i class="bi bi-upc-scan" aria-hidden="true"></i> One barcode
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.labels.qr-codes') }}">
                <i class="bi bi-qr-code" aria-hidden="true"></i> One QR code
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.labels.scanner') }}">
                <i class="bi bi-broadcast" aria-hidden="true"></i> Scanner test
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>
                {{ session('status') }}
                @if (session('label_sheet_id'))
                    <a class="fw-semibold" href="{{ route('inventory.labels.sheet', ['labelSheet' => session('label_sheet_id')]) }}">
                        Open the filed sheet
                    </a>
                @endif
            </div>
        </div>
    @endif

    @if (session('label_warnings'))
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-rulers" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">{{ count(session('label_warnings')) }} thing(s) to look at before this sheet is printed</strong>
                <ul class="mb-1 ps-3">
                    @foreach (session('label_warnings') as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
                <span class="d-block mt-1">
                    A narrower bar than {{ number_format(\App\Domain\Inventory\Services\BarcodeService::MIN_MODULE_MM, 2) }} mm may
                    still scan on a good desk scanner and will fail on a cheap one. Change the paper and generate again — the
                    previous sheet stays filed and can be ignored.
                </span>
            </div>
        </div>
    @endif

    @error('product_ids')<div class="erp-note erp-note-danger mb-3"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>{{ $message }}</div></div>@enderror
    @error('batch_ids')<div class="erp-note erp-note-danger mb-3"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>{{ $message }}</div></div>@enderror
    @error('order_ids')<div class="erp-note erp-note-danger mb-3"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>{{ $message }}</div></div>@enderror
    @error('invoice_ids')<div class="erp-note erp-note-danger mb-3"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>{{ $message }}</div></div>@enderror
    @error('templates')<div class="erp-note erp-note-danger mb-3"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>{{ $message }}</div></div>@enderror

    <form method="POST" action="{{ route('inventory.labels.generate') }}" data-erp-label-desk>
        @csrf

        <div class="erp-card mb-3">
            <header class="erp-card-head">
                <h2 class="erp-card-title">What is being labelled</h2>
                <div class="erp-card-actions">
                    <span class="erp-chip erp-chip-outline">{{ $template['label'] }}</span>
                </div>
            </header>

            {{-- Which of the ticked tables below this run is actually for. The
                 tables are all on screen so one run can start where the operator
                 is already looking, and the ticks in the others are simply not
                 part of this sheet. --}}
            <div class="erp-field-label mb-2">This run labels</div>
            <div class="d-flex flex-wrap gap-3 mb-3">
                @foreach ([
                    'product' => 'Products — a shelf label or a shelf-sticker for the catalogue row',
                    'batch' => 'Batches — a sticker for one received lot, with its expiry',
                    'order' => 'Sales orders — a pick/parcel sticker for the order',
                    'invoice' => 'Invoices — a sticker for the parcel that carries an invoice',
                ] as $value => $label)
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="subject_type" id="subject_type_{{ $value }}"
                               value="{{ $value }}" @checked(old('subject_type', 'product') === $value)>
                        <label class="form-check-label" for="subject_type_{{ $value }}">{{ $label }}</label>
                    </div>
                @endforeach
            </div>

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="template">Sheet</label>
                    <select class="form-select @error('template') is-invalid @enderror" id="template" name="template">
                        @foreach ($templates as $key => $option)
                            <option value="{{ $key }}" @selected(old('template', $defaults['template']) === $key)>
                                {{ $option['label'] }}
                            </option>
                        @endforeach
                    </select>
                    @error('template')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">
                        {{ count($templates) }} papers are wired in. The sheet is laid out to the millimetre —
                        a run is generated, filed, and printed as one document.
                    </div>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="copies">Copies each</label>
                    <input class="form-control @error('copies') is-invalid @enderror" type="number" min="1"
                           max="{{ \App\Domain\Inventory\Services\LabelService::MAX_COPIES }}"
                           id="copies" name="copies" value="{{ old('copies', $defaults['copies']) }}">
                    @error('copies')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">One run may produce {{ \App\Domain\Inventory\Services\LabelService::MAX_LABELS }} labels.</div>
                </div>

                <div class="col-md-6">
                    <div class="erp-field-label mb-2">On every label</div>
                    <div class="d-flex flex-wrap gap-3">
                        @foreach ([
                            ['show_company', 'Company name', $defaults['show_company']],
                            ['show_price', 'Price and unit', $defaults['show_price']],
                            ['show_code_text', 'The code in text', $defaults['show_code_text']],
                            ['qr', 'A QR beside the bars', $defaults['qr']],
                        ] as [$field, $label, $default])
                            <div class="form-check">
                                {{-- Every switch needs its hidden twin, or unticking it would let the setting win. --}}
                                <input type="hidden" name="{{ $field }}" value="0">
                                <input class="form-check-input" type="checkbox" name="{{ $field }}" value="1"
                                       id="{{ $field }}" @checked(old($field, $default))>
                                <label class="form-check-label" for="{{ $field }}">{{ $label }}</label>
                            </div>
                        @endforeach

                        <div>
                            <label class="form-label" for="qr_level">QR level</label>
                            <select class="form-select form-select-sm" id="qr_level" name="qr_level">
                                @foreach (['L' => 'L — 7 % recoverable (densest)', 'M' => 'M — 15 % (the usual choice)', 'Q' => 'Q — 25 %', 'H' => 'H — 30 % (survives a scuff)'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('qr_level', $defaults['qr_level']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <x-ui.table-shell :title="'Products'" :count="$products->count().' shown'">
            <x-slot:bulkActions>
                <button class="btn btn-sm btn-primary" type="submit">
                    <i class="bi bi-printer" aria-hidden="true"></i> Generate the sheet
                </button>
            </x-slot:bulkActions>
            <thead>
                <tr>
                    <th scope="col" style="width: 2.4rem;">
                        <input class="form-check-input" type="checkbox" data-erp-select-all aria-label="Tick every product">
                    </th>
                    <th>Product</th>
                    <th>What the label would carry</th>
                    <th>Unit</th>
                    <th>State</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    @php($payload = $product->barcode ?: ($product->sku ?: $product->code))
                    <tr>
                        <td data-label="Tick">
                            <input class="form-check-input" type="checkbox" name="product_ids[]" value="{{ $product->id }}"
                                   data-erp-row-select aria-label="Label {{ $product->name }}" @checked(in_array($product->id, array_map('intval', (array) old('product_ids', [])), true))>
                        </td>
                        <td data-label="Product">
                            <span class="erp-cell-strong">{{ $product->name }}</span>
                            <span class="d-block erp-td-muted">{{ $product->code }} · {{ $product->sku }}</span>
                        </td>
                        <td data-label="Payload">
                            <span class="font-monospace">{{ $payload }}</span>
                            <span class="d-block erp-td-muted">
                                {{ $product->barcode ? 'its barcode' : 'no barcode — the SKU stands in' }}
                            </span>
                        </td>
                        <td data-label="Unit">{{ $product->unit?->symbol ?? '—' }}</td>
                        <td data-label="State">
                            <x-ui.status :value="$product->is_stocked ? 'active' : 'inactive'"
                                         :label="$product->is_stocked ? 'stock-managed' : 'service'" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <x-ui.empty icon="bi-box-seam" title="No active products to label"
                                        text="Labels are printed for catalogue rows. Add a product first."
                                        action="Go to products" :href="route('inventory.products.index')" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table-shell>

        <div class="mt-3">
            <x-ui.table-shell :title="'Batches'" :count="$batches->count().' most recent'">
                <thead>
                    <tr>
                        <th scope="col" style="width: 2.4rem;">
                            <input class="form-check-input" type="checkbox" data-erp-select-all aria-label="Tick every batch">
                        </th>
                        <th>Batch</th>
                        <th>Product</th>
                        <th>Warehouse</th>
                        <th>Expiry</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($batches as $batch)
                        <tr>
                            <td data-label="Tick">
                                <input class="form-check-input" type="checkbox" name="batch_ids[]" value="{{ $batch->id }}"
                                       data-erp-row-select aria-label="Label batch {{ $batch->batch_no }}">
                            </td>
                            <td data-label="Batch"><span class="font-monospace">{{ $batch->batch_no }}</span></td>
                            <td data-label="Product">
                                {{ $batch->product?->name ?? '—' }}
                                <span class="d-block erp-td-muted">{{ $batch->product?->code }}</span>
                            </td>
                            <td data-label="Warehouse" class="erp-td-muted">{{ $batch->warehouse?->name ?? '—' }}</td>
                            <td data-label="Expiry">
                                @if ($batch->expires_on)
                                    <x-ui.status value="warn" :label="$batch->expires_on->format('d M Y')" />
                                @else
                                    <span class="erp-td-muted">none recorded</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-ui.empty icon="bi-layers" title="No batches recorded yet"
                                            text="A batch label carries the lot and its expiry, so there is nothing to print until stock arrives in a tracked lot."
                                            action="Go to batches" :href="route('inventory.batches.index')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table-shell>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-lg-6">
                <x-ui.table-shell :title="'Sales orders'" :count="$orders->count().' most recent'">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 2.4rem;">
                                <input class="form-check-input" type="checkbox" data-erp-select-all aria-label="Tick every order">
                            </th>
                            <th>Order</th>
                            <th>Customer</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($orders as $order)
                            <tr>
                                <td data-label="Tick">
                                    <input class="form-check-input" type="checkbox" name="order_ids[]" value="{{ $order->id }}"
                                           data-erp-row-select aria-label="Label order {{ $order->order_no }}">
                                </td>
                                <td data-label="Order"><span class="font-monospace">{{ $order->order_no }}</span></td>
                                <td data-label="Customer">{{ $order->customer?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3"><x-ui.empty icon="bi-receipt" title="No orders yet"
                                                            text="An order sticker is printed from a real order, so this table fills itself as orders arrive." /></td>
                            </tr>
                        @endforelse
                    </tbody>
                </x-ui.table-shell>
            </div>

            <div class="col-lg-6">
                <x-ui.table-shell :title="'Invoices'" :count="$invoices->count().' most recent'">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 2.4rem;">
                                <input class="form-check-input" type="checkbox" data-erp-select-all aria-label="Tick every invoice">
                            </th>
                            <th>Invoice</th>
                            <th>Customer</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($invoices as $invoice)
                            <tr>
                                <td data-label="Tick">
                                    <input class="form-check-input" type="checkbox" name="invoice_ids[]" value="{{ $invoice->id }}"
                                           data-erp-row-select aria-label="Label invoice {{ $invoice->invoice_no }}">
                                </td>
                                <td data-label="Invoice"><span class="font-monospace">{{ $invoice->invoice_no }}</span></td>
                                <td data-label="Customer">{{ $invoice->customer?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3"><x-ui.empty icon="bi-file-earmark-text" title="No invoices yet"
                                                            text="Invoice stickers come from issued invoices only." /></td>
                            </tr>
                        @endforelse
                    </tbody>
                </x-ui.table-shell>
            </div>
        </div>

        <div class="erp-card mt-3">
            <div class="d-flex flex-wrap align-items-center gap-2 justify-content-between">
                <div class="text-muted">
                    Nothing is written to stock, the ledger or the catalogue by generating a sheet — it is paper,
                    filed and counted like every other document this company generates.
                </div>
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-printer" aria-hidden="true"></i> Generate the sheet
                </button>
            </div>
        </div>
    </form>

    <div class="row g-3 mt-1">
        <div class="col-lg-7">
            <x-ui.table-shell :title="'Sheets filed'" :count="$sheets->count().' most recent'">
                <thead>
                    <tr>
                        <th>Sheet</th>
                        <th class="erp-th-num">Size</th>
                        <th>Filed</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sheets as $sheet)
                        <tr>
                            <td data-label="Sheet">
                                <span class="erp-cell-strong">{{ $sheet->original_name }}</span>
                                <span class="d-block text-muted font-monospace">{{ \Illuminate\Support\Str::limit($sheet->checksum, 24) }}</span>
                            </td>
                            <td data-label="Size" class="erp-td-num">{{ number_format($sheet->size_bytes / 1024, 1) }} KB</td>
                            <td data-label="Filed">
                                {{ $sheet->created_at?->format('d M Y, H:i') }}
                                <span class="d-block erp-td-muted">{{ $sheet->branch_id ? 'branch #'.$sheet->branch_id : 'company-wide' }}</span>
                            </td>
                            <td data-label="">
                                <div class="d-flex flex-wrap align-items-center gap-1 justify-content-end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="{{ route('inventory.labels.sheet', ['labelSheet' => $sheet->id]) }}">
                                        <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Open
                                    </a>
                                    {{-- The sheet itself is a stored file, so the print that
                                         follows is recorded here rather than in the document:
                                         the bytes that were filed must not change. --}}
                                    <form class="d-flex align-items-center gap-1" method="POST"
                                          action="{{ route('inventory.labels.print', ['labelSheet' => $sheet->id]) }}">
                                        @csrf
                                        <label class="visually-hidden" for="print_copies_{{ $sheet->id }}">Copies printed</label>
                                        <input class="form-control form-control-sm" style="width:4.5rem" type="number"
                                               id="print_copies_{{ $sheet->id }}" name="copies" value="1" min="1" max="{{ \App\Domain\Inventory\Services\LabelService::MAX_COPIES }}">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit"
                                                title="Record a print run of this sheet">
                                            <i class="bi bi-printer" aria-hidden="true"></i> Record print
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <x-ui.empty icon="bi-printer" title="No label sheet has been filed yet"
                                            text="A sheet appears here the moment one is generated — with the checksum of the exact file that was printed." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table-shell>
        </div>

        <div class="col-lg-5">
            <x-ui.table-shell :title="'Prints'" :count="$history->count().' most recent'">
                <thead>
                    <tr>
                        <th>Sheet</th>
                        <th>Who</th>
                        <th class="erp-th-num">Copies</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($history as $print)
                        <tr>
                            <td data-label="Sheet" class="erp-td-muted">#{{ $print->printable_id }}</td>
                            <td data-label="Who">
                                {{ $print->user?->name ?? '—' }}
                                <span class="d-block erp-td-muted">{{ $print->ip }}</span>
                            </td>
                            <td data-label="Copies" class="erp-td-num">{{ $print->copies }}</td>
                            <td data-label="When" class="erp-td-muted">{{ $print->created_at?->format('d M, H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <x-ui.empty icon="bi-clock-history" title="Nothing printed yet"
                                            text="Every reprint of a filed sheet is logged here with who did it and how many copies." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table-shell>
        </div>
    </div>

    <x-ui.related-pages />
@endsection
