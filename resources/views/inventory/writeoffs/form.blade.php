@extends('layouts.app')

@section('page_title', 'Raise a write-off')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Damage & loss"
        title="Raise a write-off"
        subtitle="Name the compartment the goods leave and why. The document sits in the queue until someone else approves it — only then does stock move and the cost post."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.writeoffs.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to the queue
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-note erp-note-warn mb-3">
        <i class="bi bi-shield-lock" aria-hidden="true"></i>
        <div>
            <strong>Approval needs a second person.</strong>
            You may raise a write-off, but you cannot approve it yourself. Until it is approved nothing has moved,
            and a rejection leaves the stock exactly where it is.
        </div>
    </div>

    <form method="POST" action="{{ route('inventory.writeoffs.store') }}">
        @csrf

        <div class="erp-card mb-3">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label" for="warehouse_id">Warehouse</label>
                    <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                        <option value="">— select —</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected((int) old('warehouse_id') === $warehouse->id)>
                                {{ $warehouse->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('warehouse_id') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="writeoff_date">Date</label>
                    <input type="date" class="form-control" id="writeoff_date" name="writeoff_date"
                           value="{{ old('writeoff_date', now()->toDateString()) }}" required>
                    @error('writeoff_date') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="source_state">Take the goods from</label>
                    <select class="form-select" id="source_state" name="source_state" required>
                        @foreach ($sources as $key => $label)
                            <option value="{{ $key }}" @selected(old('source_state', 'damaged') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Damaged and quarantined goods are held outside sellable stock.</div>
                    @error('source_state') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="reason">Reason</label>
                    <input class="form-control" id="reason" name="reason" value="{{ old('reason') }}"
                           required maxlength="500" placeholder="Disposed of after inspection">
                    @error('reason') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        @if ($holdings->isNotEmpty())
            <div class="erp-note erp-note-info mb-3">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <div>
                    There are <strong>{{ $holdings->count() }}</strong> product/warehouse rows held as damaged
                    ({{ number_format($holdings->sum('qty'), 4) }} units, valued at {{ number_format($holdings->sum('value'), 2) }}).
                    Weighing the write-off against those holdings is the honest starting point — but a direct
                    write-off of sellable stock (expired goods never flagged) is allowed, and the ledger will refuse
                    it if there is not enough stock.
                </div>
            </div>
        @endif

        @error('lines') <div class="alert alert-danger">{{ $message }}</div> @enderror

        <x-ui.table-shell title="Lines to write off">
            <thead>
                <tr>
                    <th style="width: 40%">Product</th>
                    <th style="width: 15%">Quantity</th>
                    <th style="width: 30%">Note</th>
                    <th style="width: 56px"></th>
                </tr>
            </thead>
            <tbody id="lines-body">
                @for ($i = 0; $i < 2; $i++)
                    <tr class="line-row">
                        <td>
                            <select class="form-select" name="lines[{{ $i }}][product_id]" required>
                                <option value="">— select —</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}" @selected((int) old("lines.$i.product_id") === $product->id)>
                                        {{ $product->sku }} · {{ $product->name }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <input type="number" step="0.0001" min="0.0001" class="form-control"
                                   name="lines[{{ $i }}][qty]" required value="{{ old("lines.$i.qty") }}" placeholder="1">
                        </td>
                        <td>
                            <input class="form-control" name="lines[{{ $i }}][narration]" maxlength="500"
                                   value="{{ old("lines.$i.narration") }}" placeholder="Optional detail">
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-danger remove-line" aria-label="Remove line">&times;</button>
                        </td>
                    </tr>
                @endfor
            </tbody>
        </x-ui.table-shell>

        <div class="d-flex gap-2 mt-3">
            <button class="btn btn-outline-secondary btn-sm" id="add-line" type="button">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add line
            </button>
            <div class="ms-auto d-flex gap-2">
                <a class="btn btn-outline-secondary" href="{{ route('inventory.writeoffs.index') }}">Cancel</a>
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-send" aria-hidden="true"></i> Send for approval
                </button>
            </div>
        </div>
    </form>

    @push('scripts')
    <script>
        (function () {
            const tbody = document.getElementById('lines-body');
            const addBtn = document.getElementById('add-line');

            function reindex() {
                tbody.querySelectorAll('.line-row').forEach(function (row, i) {
                    row.querySelectorAll('[name]').forEach(function (el) {
                        el.name = el.name.replace(/lines\[\d+\]/, 'lines[' + i + ']');
                    });
                });
            }

            addBtn.addEventListener('click', function () {
                const clone = tbody.querySelector('.line-row').cloneNode(true);
                clone.querySelectorAll('select, input').forEach(function (el) {
                    if (el.tagName === 'SELECT') el.selectedIndex = 0;
                    else el.value = '';
                });
                tbody.appendChild(clone);
                reindex();
            });

            tbody.addEventListener('click', function (e) {
                if (e.target.classList.contains('remove-line') && tbody.querySelectorAll('.line-row').length > 1) {
                    e.target.closest('tr').remove();
                    reindex();
                }
            });
        })();
    </script>
    @endpush
@endsection
