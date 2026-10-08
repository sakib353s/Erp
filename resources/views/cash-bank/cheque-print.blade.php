{{--
    §08-14 — the cheque's printed record.

    Standalone HTML with its own styles, like every other generated document in
    this application: it is written to disk, checksummed and printed from a
    browser, so it cannot depend on the app layout, on a build step or on a CDN.

    What it is *not*: a cheque. It carries no bank authority, it is not
    negotiable, and it says so on its face — the thing it records is the slip the
    bank printed, and this sheet is the office's copy of what that slip said.
    Both languages are on it because a bank reads the words when the figures are
    unclear, and this desk writes for banks in Bangladesh.
--}}
<!DOCTYPE html>
<html lang="{{ $company?->locale ?? 'en' }}">
<head>
<meta charset="utf-8">
<title>Cheque {{ $cheque->cheque_no }} — {{ $cheque->party_name }}</title>
<style>
    @page { size: A4; margin: 14mm; }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        background: #fff;
        color: #101828;
        font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
        font-size: 12px;
        line-height: 1.45;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .bar {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        font-size: 10.5px;
        color: #667085;
        border-bottom: 1px solid #d0d5dd;
        padding-bottom: 5px;
        margin-bottom: 14px;
    }

    .head { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; }

    .co-name { font-size: 17px; font-weight: 700; margin: 0 0 2px; letter-spacing: -.01em; }
    .co-line { margin: 0; color: #475467; }
    .co-id { margin: 4px 0 0; color: #475467; font-size: 11px; }

    .doc { text-align: right; }
    .doc-title {
        margin: 0;
        font-size: 15px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
    }
    .doc-meta { margin: 3px 0 0; color: #475467; }

    .rule { height: 2px; background: #101828; margin: 12px 0 16px; }

    table.facts { width: 100%; border-collapse: collapse; }
    table.facts th,
    table.facts td { border: 1px solid #d0d5dd; padding: 7px 9px; vertical-align: top; text-align: left; }
    table.facts th {
        width: 26%;
        background: #f9fafb;
        font-weight: 600;
        color: #344054;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .03em;
    }
    .mono { font-family: "DejaVu Sans Mono", "Courier New", monospace; }
    .strong { font-weight: 700; }
    .big { font-size: 15px; }

    .words {
        border: 1px solid #d0d5dd;
        border-left: 4px solid #101828;
        padding: 10px 12px;
        margin-top: 14px;
    }
    .words-label {
        margin: 0 0 4px;
        font-size: 10.5px;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: #667085;
        font-weight: 600;
    }
    .words p { margin: 0; }
    .words .bn { margin-top: 8px; font-size: 13px; }

    .note { margin-top: 14px; padding: 9px 11px; border: 1px dashed #98a2b3; color: #475467; font-size: 11px; }

    footer {
        margin-top: 18px;
        padding-top: 8px;
        border-top: 1px solid #d0d5dd;
        display: flex;
        justify-content: space-between;
        gap: 12px;
        color: #667085;
        font-size: 10.5px;
    }
</style>
</head>
<body>
    <div class="bar">
        <span>Cheque record · {{ $cheque->isReceived() ? 'received from a customer' : 'issued by the company' }}</span>
        <span>Generated {{ now()->format('d M Y H:i') }} · filed in the document register</span>
    </div>

    <div class="head">
        <div>
            <p class="co-name">{{ $company?->name ?? 'The company' }}</p>
            @if ($company?->address_line1)
                <p class="co-line">{{ $company->address_line1 }}</p>
            @endif
            @if ($company?->address_line2)
                <p class="co-line">{{ $company->address_line2 }}</p>
            @endif
            @if ($company?->area || $company?->district)
                <p class="co-line">{{ trim(($company?->area ?? '').' '.($company?->district ?? '')) }}</p>
            @endif
            @if ($company?->phone || $company?->email)
                <p class="co-line">{{ $company?->phone }}@if ($company?->phone && $company?->email) · @endif{{ $company?->email }}</p>
            @endif
            @if ($company?->tin || $company?->bin)
                <p class="co-id">
                    @if ($company?->tin) TIN {{ $company->tin }} @endif
                    @if ($company?->tin && $company?->bin) · @endif
                    @if ($company?->bin) BIN {{ $company->bin }} @endif
                </p>
            @endif
        </div>
        <div class="doc">
            <p class="doc-title">Cheque record</p>
            <p class="doc-meta">No. <span class="mono strong">{{ $cheque->cheque_no }}</span></p>
            <p class="doc-meta">{{ $cheque->bank_name }}</p>
            <p class="doc-meta">{{ $cheque->cheque_date?->format('d M Y') }}</p>
        </div>
    </div>

    <div class="rule"></div>

    <table class="facts">
        <tr>
            <th>{{ $cheque->isReceived() ? 'Received from' : 'Pay to' }}</th>
            <td class="strong big">{{ $cheque->party_name }}</td>
        </tr>
        <tr>
            <th>Amount</th>
            <td class="strong big mono">{{ number_format((float) $cheque->amount, 2) }} {{ $cheque->currency }}</td>
        </tr>
        <tr>
            <th>Cheque number</th>
            <td class="mono">{{ $cheque->cheque_no }}</td>
        </tr>
        <tr>
            <th>Date written</th>
            <td>{{ $cheque->cheque_date?->format('d M Y') }}@if ($cheque->isPostDated()) <span class="strong">(post-dated)</span>@endif</td>
        </tr>
        <tr>
            <th>Drawn on</th>
            <td>{{ $cheque->bank_name }}</td>
        </tr>
        <tr>
            <th>Clears through</th>
            <td>
                {{ $cheque->account?->name }}
                @if ($cheque->account?->code) <span class="mono">({{ $cheque->account->code }})</span>@endif
            </td>
        </tr>
        <tr>
            <th>Settles</th>
            <td>
                {{ $cheque->counterAccount?->name }}
                @if ($cheque->counterAccount?->code) <span class="mono">({{ $cheque->counterAccount->code }})</span>@endif
            </td>
        </tr>
        @if ($cheque->reference)
            <tr>
                <th>Reference</th>
                <td class="mono">{{ $cheque->reference }}</td>
            </tr>
        @endif
        @if ($cheque->narration)
            <tr>
                <th>What it was for</th>
                <td>{{ $cheque->narration }}</td>
            </tr>
        @endif
        <tr>
            <th>State at printing</th>
            <td>
                {{ $cheque->statusLabel() }}
                @if ($cheque->deposited_on) · deposited {{ $cheque->deposited_on->format('d M Y') }} @endif
                @if ($cheque->presented_on) · presented {{ $cheque->presented_on->format('d M Y') }} @endif
                @if ($cheque->cleared_on) · cleared {{ $cheque->cleared_on->format('d M Y') }} @endif
                @if ($cheque->bounced_on) · failed {{ $cheque->bounced_on->format('d M Y') }} ({{ $cheque->bounced_reason }}) @endif
            </td>
        </tr>
        <tr>
            <th>Ledger effect</th>
            <td>
                @if ($cheque->journalEntry)
                    Posted as <span class="mono strong">{{ $cheque->journalEntry->entry_no }}</span>
                    @if ($cheque->reversalEntry)
                        · reversed by <span class="mono strong">{{ $cheque->reversalEntry->entry_no }}</span>
                    @endif
                @else
                    Nothing has posted — a cheque reaches the ledger only when a bank pays it.
                @endif
            </td>
        </tr>
    </table>

    <div class="words">
        <p class="words-label">Amount in words</p>
        <p>{{ $wordsEn }}</p>
        <p class="bn">{{ $wordsBn }}</p>
        <p class="words-label" style="margin-top:8px">Figures in Bangla</p>
        <p>{{ $figuresBn }}</p>
    </div>

    <div class="note">
        This sheet is the office's record of the cheque named above. It is not a cheque, not a
        negotiable instrument and not an instruction to any bank — the slip itself is. Keep it with
        the voucher it was written for; when the bank paid it, the entry above is what the books hold.
    </div>

    <footer>
        <span>{{ $company?->name ?? 'The company' }} · cheque register</span>
        <span>Printed {{ now()->format('d M Y H:i') }}</span>
    </footer>
</body>
</html>
