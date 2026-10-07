@extends('layouts.app')

@section('page_title', 'POS Returns')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">POS Returns</h1>
            <p class="erp-page-sub">Counter returns run the full returns pipeline: request → receive (stock back) → credit note → refund. A cash refund leaves this session's drawer.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('pos.terminal') }}">Terminal</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="erp-card mb-3">
        <form method="GET" action="{{ route('pos.returns.index') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="invoice">POS invoice number</label>
                <input class="form-control" type="search" id="invoice" name="invoice"
                       value="{{ $search }}" placeholder="INV-…" autocomplete="off">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary w-100" type="submit">Find invoice</button>
            </div>
        </form>
        @if ($search !== '' && $invoice === null)
            <p class="mb-0 mt-3 text-muted">No POS invoice matches <code>{{ $search }}</code>.</p>
        @elseif ($search === '')
            <p class="mb-0 mt-3 text-muted">Look a POS invoice up by its number to return items against it.</p>
        @endif
    </div>

    @if ($invoice !== null)
        <div class="erp-card">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h2 class="erp-h2 mb-1">Invoice {{ $invoice->invoice_no }}</h2>
                    <p class="mb-0 text-muted">
                        {{ $invoice->invoice_date }} ·
                        <span class="erp-status erp-status-active">{{ $invoice->status }}</span> ·
                        total {{ number_format((float) $invoice->grand_total, 2) }}
                    </p>
                </div>
            </div>

            <form method="POST" action="{{ route('pos.return') }}">
                @csrf
                <input type="hidden" name="invoice_id" value="{{ $invoice->id }}">

                <div class="table-responsive mb-3">
                    <table class="table erp-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th class="text-end">Qty invoiced</th>
                                <th class="text-end">Unit price</th>
                                <th class="text-end" style="width: 10rem;">Return qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoice->lines as $i => $line)
                                <tr>
                                    <td>{{ $line->line_no }}</td>
                                    <td>{{ $line->product?->name ?? $line->description }}</td>
                                    <td class="text-end">{{ number_format((float) $line->qty, 4) }}</td>
                                    <td class="text-end">{{ number_format((float) $line->unit_price, 2) }}</td>
                                    <td class="text-end">
                                        <input class="form-control form-control-sm text-end" type="number"
                                               name="lines[{{ $i }}][qty]" min="0" step="any" value="0"
                                               aria-label="Return qty line {{ $line->line_no }}">
                                        <input type="hidden" name="lines[{{ $i }}][invoice_line_id]"
                                               value="{{ $line->id }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label" for="payment_method">Refund method</label>
                        <select class="form-select" id="payment_method" name="payment_method">
                            <option value="cash" @selected(request('payment_method', 'cash') === 'cash')>Cash (drawer)</option>
                            <option value="bank" @selected(request('payment_method') === 'bank')>Bank</option>
                            <option value="mobile" @selected(request('payment_method') === 'mobile')>Mobile wallet</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="notes">Notes</label>
                        <input class="form-control" type="text" id="notes" name="notes" maxlength="500">
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-primary w-100" type="submit">Process return</button>
                    </div>
                </div>
                <p class="text-muted small mt-2 mb-0">Qty 0 skips the line. Quantities beyond what was invoiced are refused.</p>
            </form>
        </div>
    @endif
@endsection
