@extends('layouts.app')

@section('page_title', 'New Manual Journal')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Manual Journal</h1>
            <p class="erp-page-sub">Σdebits must equal Σcredits. The target fiscal period must be open.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('accounting.journals.index') }}">Back</a>
    </div>

    <div class="erp-card" style="max-width: 960px">
        <form method="POST" action="{{ route('accounting.journals.store') }}" id="journal-form">
            @csrf

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label" for="entry_date">Entry date</label>
                    <input type="date" class="form-control" id="entry_date" name="entry_date"
                           value="{{ old('entry_date', $entryDate) }}" required>
                    @error('entry_date') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-9">
                    <label class="form-label" for="description">Description</label>
                    <input class="form-control" id="description" name="description"
                           value="{{ old('description') }}" required maxlength="500"
                           placeholder="What is this journal for?">
                    @error('description') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-12">
                    <label class="form-label" for="narration">Narration</label>
                    <input class="form-control" id="narration" name="narration"
                           value="{{ old('narration') }}" maxlength="500">
                </div>
            </div>

            <div class="table-responsive mb-3">
                <table class="table erp-table" id="lines-table">
                    <thead>
                        <tr>
                            <th style="width: 35%">Account</th>
                            <th style="width: 12%">Dr/Cr</th>
                            <th style="width: 18%">Amount</th>
                            <th>Narration</th>
                            <th style="width: 56px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @for ($i = 0; $i < 2; $i++)
                            <tr class="line-row">
                                <td>
                                    <select class="form-select line-account" name="lines[{{ $i }}][account_id]" required>
                                        <option value="">— select —</option>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}">
                                                {{ $account->code }} · {{ $account->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select class="form-select" name="lines[{{ $i }}][dc]" required>
                                        <option value="debit">Debit</option>
                                        <option value="credit">Credit</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0.01" class="form-control line-amount"
                                           name="lines[{{ $i }}][amount]" required placeholder="0.00">
                                </td>
                                <td>
                                    <input class="form-control" name="lines[{{ $i }}][narration]" maxlength="500">
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-line" aria-label="Remove line">&times;</button>
                                </td>
                            </tr>
                        @endfor
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="2" class="text-end">Totals</th>
                            <th>
                                <div class="small">Dr: <span id="total-debit">0.00</span></div>
                                <div class="small">Cr: <span id="total-credit">0.00</span></div>
                                <div class="small fw-bold" id="balance-status">—</div>
                            </th>
                            <th colspan="2"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="add-line">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add line
            </button>

            @error('lines') <div class="alert alert-danger">{{ $message }}</div> @enderror

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Post journal</button>
                <a class="btn btn-outline-secondary" href="{{ route('accounting.journals.index') }}">Cancel</a>
            </div>
        </form>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection

@push('scripts')
<script>
(function () {
    const tbody = document.querySelector('#lines-table tbody');
    const addBtn = document.getElementById('add-line');
    let index = tbody.querySelectorAll('.line-row').length;

    function reindex() {
        tbody.querySelectorAll('.line-row').forEach(function (row, i) {
            row.querySelectorAll('[name]').forEach(function (el) {
                el.name = el.name.replace(/lines\[\d+\]/, 'lines[' + i + ']');
            });
        });
        index = tbody.querySelectorAll('.line-row').length;
        recalc();
    }

    function recalc() {
        let dr = 0, cr = 0;
        tbody.querySelectorAll('.line-row').forEach(function (row) {
            const amount = parseFloat(row.querySelector('.line-amount').value || '0') || 0;
            const dc = row.querySelector('[name$="[dc]"]').value;
            if (dc === 'debit') dr += amount; else cr += amount;
        });
        document.getElementById('total-debit').textContent = dr.toFixed(2);
        document.getElementById('total-credit').textContent = cr.toFixed(2);
        const status = document.getElementById('balance-status');
        if (dr === 0 && cr === 0) {
            status.textContent = '—';
            status.className = 'small fw-bold';
        } else if (Math.abs(dr - cr) < 0.005) {
            status.textContent = 'Balanced ✓';
            status.className = 'small fw-bold text-success';
        } else {
            status.textContent = 'Out of balance';
            status.className = 'small fw-bold text-danger';
        }
    }

    addBtn.addEventListener('click', function () {
        const first = tbody.querySelector('.line-row');
        const clone = first.cloneNode(true);
        clone.querySelectorAll('select, input').forEach(function (el) {
            if (el.tagName === 'SELECT') el.selectedIndex = 0;
            else el.value = '';
        });
        tbody.appendChild(clone);
        reindex();
    });

    tbody.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-line')) {
            if (tbody.querySelectorAll('.line-row').length > 2) {
                e.target.closest('tr').remove();
                reindex();
            }
        }
    });

    tbody.addEventListener('input', recalc);
    tbody.addEventListener('change', recalc);
})();
</script>
@endpush
