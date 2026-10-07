@extends('layouts.app')

@section('page_title', 'Product Import')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Products"
        title="Product import"
        subtitle="Bring the catalogue in from a CSV: prices, categories, tracking flags and all. Each row lands on its own, so one bad line does not undo the four hundred good ones — and you can look before you leap."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.products.import.template') }}">
                <i class="bi bi-filetype-csv" aria-hidden="true"></i> Download template
            </a>
            @if ($perm('inventory.products.export'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.products.export') }}">
                    <i class="bi bi-download" aria-hidden="true"></i> Export catalogue
                </a>
            @endif
            <a class="btn btn-outline-secondary" href="{{ route('inventory.products.import.history') }}">
                <i class="bi bi-clock-history" aria-hidden="true"></i> Import history
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-note erp-note-info mb-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            <strong>Preview writes nothing.</strong>
            The preview walks the file exactly as the import would and rolls every row back, so what it reports is
            what a real run would do — not a second opinion about it. A <strong>blank cell means "leave it alone"</strong>,
            never "erase it": a file that carries codes and prices cannot blank out the descriptions somebody else
            filled in. Categories, brands and units must already exist in Masters — a spreadsheet never invents them.
        </div>
    </div>

    <div class="erp-split">
        <section class="erp-card erp-split-main">
            <header class="erp-card-head">
                <h2 class="erp-card-title"><i class="bi bi-upload" aria-hidden="true"></i> Upload a file</h2>
                <span class="erp-td-muted">CSV, up to {{ \App\Domain\Inventory\Services\ProductImportService::MAX_KILOBYTES }} KB &middot; {{ number_format(\App\Domain\Inventory\Services\ProductImportService::MAX_ROWS) }} rows</span>
            </header>

            <form method="POST" action="{{ route('inventory.products.import.store') }}" enctype="multipart/form-data">
                @csrf

                <div class="mb-3">
                    <label class="form-label" for="file">CSV file</label>
                    <input class="form-control @error('file') is-invalid @enderror" type="file" id="file" name="file"
                           accept=".csv,.txt,.tsv,text/csv" required>
                    @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">
                        Exported straight from the products list, or built from the template. The delimiter is worked
                        out from the heading row — comma, semicolon or tab.
                    </div>
                </div>

                <fieldset class="mb-3">
                    <legend class="form-label">What to do with it</legend>

                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="mode" id="mode-preview" value="preview"
                               @checked(old('mode', 'preview') === 'preview')>
                        <label class="form-check-label" for="mode-preview">
                            <strong>Preview</strong> — read the file and report every row that would be created,
                            updated or refused. Nothing is written.
                        </label>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="mode" id="mode-import" value="import"
                               @checked(old('mode') === 'import')>
                        <label class="form-check-label" for="mode-import">
                            <strong>Import</strong> — apply it. Every change is audited, and a row that moves a cost
                            lands in the product's cost history with this file as the reason.
                        </label>
                    </div>

                    @error('mode')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </fieldset>

                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-play-circle" aria-hidden="true"></i> Run
                </button>
            </form>
        </section>

        <aside class="erp-split-side">
            <section class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title"><i class="bi bi-list-columns" aria-hidden="true"></i> The columns</h2>
                </header>

                <p class="erp-card-sub">
                    <code>code</code> or <code>sku</code> identifies the row, <code>name</code> is required for a new
                    product. Any order; extra columns are ignored and reported.
                </p>

                <ul class="erp-list small">
                    @foreach ($columns as $column)
                        <li class="d-flex justify-content-between gap-2 border-top py-1">
                            <code>{{ $column }}</code>
                            <span class="erp-td-muted text-end">
                                @switch($column)
                                    @case('code') Product code — matched first @break
                                    @case('sku') SKU — matched first, defaults to the code @break
                                    @case('name') Product name @break
                                    @case('category') Category code or name @break
                                    @case('brand') Brand code or name @break
                                    @case('unit') Unit code or name @break
                                    @case('barcode') Printed barcode, if any @break
                                    @case('description') Free text @break
                                    @case('cost_method') fifo / lifo / wac / standard @break
                                    @case('standard_cost') Number, no separators @break
                                    @case('is_stocked') yes / no @break
                                    @case('track_batch') yes / no @break
                                    @case('is_active') yes / no @break
                                @endswitch
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Last runs</h2>
                    <a class="btn btn-sm btn-link" href="{{ route('inventory.products.import.history') }}">All</a>
                </header>

                @forelse ($runs as $run)
                    <div class="d-flex justify-content-between gap-2 border-top py-2">
                        <span class="small">
                            <a class="text-decoration-none" href="{{ route('inventory.products.import.show', $run) }}">
                                {{ $run->original_name }}
                            </a>
                            <span class="d-block erp-td-muted">
                                {{ $run->created_at->format('d M Y H:i') }} &middot; {{ $run->actor?->name ?? 'System' }}
                            </span>
                        </span>
                        <span class="text-end small">
                            <x-ui.status :value="$run->stateTone()" :label="$run->stateLabel()" />
                            <span class="d-block erp-td-muted">
                                {{ number_format($run->rows_created) }} new &middot; {{ number_format($run->rows_failed) }} failed
                            </span>
                        </span>
                    </div>
                @empty
                    <p class="erp-td-muted mb-0">
                        No import has been run for this company yet. Download the template to see the shape of a file.
                    </p>
                @endforelse
            </section>
        </aside>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
