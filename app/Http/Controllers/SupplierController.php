<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Branch;
use App\Domain\Masters\District;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Queries\PurchaseQuery;
use App\Domain\Purchase\Services\SupplierService;
use App\Http\Requests\StoreSupplierRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/**
 * Suppliers (§06). List, profile, create/edit and the blacklist — every write
 * goes through SupplierService so duplicate detection and the reason-required
 * blacklist cannot be bypassed by a form.
 */
class SupplierController extends Controller
{
    public function __construct(
        protected SupplierService $suppliers,
        protected PurchaseQuery $query,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q')),
            'status' => (string) $request->query('status'),
            'category' => (string) $request->query('category'),
            'sort' => (string) $request->query('sort'),
        ];

        return view('purchase.suppliers.index', [
            'suppliers' => $this->query->suppliers($filters),
            'filters' => $filters,
            'categories' => Supplier::CATEGORIES,
            'summary' => $this->query->summary($this->branchIds($request)),
        ]);
    }

    public function create(): View
    {
        return view('purchase.suppliers.form', [
            'supplier' => new Supplier(['is_active' => true, 'payment_terms_days' => 0]),
            'districts' => District::query()->orderBy('name')->get(['id', 'name']),
            'categories' => Supplier::CATEGORIES,
            'mode' => 'create',
        ]);
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        try {
            $supplier = $this->suppliers->create($request->validated(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['supplier' => $e->getMessage()]);
        }

        return redirect()->route('suppliers.show', $supplier)->with('status', "Supplier {$supplier->name} created.");
    }

    public function show(Request $request, Supplier $supplier): View
    {
        return view('purchase.suppliers.show', [
            'supplier' => $supplier->loadCount(['orders', 'receipts'])->load(['district:id,name', 'blacklistedBy:id,name']),
            'openLines' => $this->query->openLines($supplier->id),
            'payables' => $this->query->supplierPayables($supplier->id),
            'spend' => $this->query->supplierSpend($supplier->id),
            'recentOrders' => $supplier->orders()->with('warehouse:id,name')->orderByDesc('order_date')->limit(10)->get(),
            'recentReceipts' => $supplier->receipts()->with('order:id,code')->orderByDesc('received_date')->limit(10)->get(),
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        return view('purchase.suppliers.form', [
            'supplier' => $supplier,
            'districts' => District::query()->orderBy('name')->get(['id', 'name']),
            'categories' => Supplier::CATEGORIES,
            'mode' => 'edit',
        ]);
    }

    public function update(StoreSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        try {
            $this->suppliers->update($supplier, $request->validated(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['supplier' => $e->getMessage()]);
        }

        return redirect()->route('suppliers.show', $supplier)->with('status', 'Supplier updated.');
    }

    public function blacklist(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->suppliers->blacklist($supplier, $data['reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', "{$supplier->name} is blacklisted — no new documents can be raised.");
    }

    public function unblacklist(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->suppliers->unblacklist($supplier, $request->user()?->id);

        return back()->with('status', "{$supplier->name} reinstated.");
    }

    /** @return array<int, int> */
    protected function branchIds(Request $request): array
    {
        $ids = $request->user()?->accessibleBranchIds();

        return $ids === null ? [] : array_map('intval', $ids);
    }

    /* ------------------------------- supplier account (06-08, 06-14) */

    public function ledger(Request $request, Supplier $supplier): View
    {
        $range = $this->range($request);

        return view('purchase.suppliers.ledger', [
            'supplier' => $supplier,
            'ledger' => $this->query->supplierLedger($supplier, $range),
            'payables' => $this->query->supplierPayables($supplier->id),
            'range' => $range,
        ]);
    }

    /**
     * The statement is a document (printable) and a data export in one route:
     * `?format=csv` streams the same rows the screen shows, so what a customer
     * of this feature reconciles in a spreadsheet is what the paper says.
     */
    public function statement(Request $request, Supplier $supplier)
    {
        $range = $this->range($request);
        $ledger = $this->query->supplierLedger($supplier, $range);

        if ($request->query('format') === 'csv') {
            $filename = 'supplier-statement-'.Str::slug($supplier->code ?? $supplier->name).'-'.$range['from'].'-to-'.$range['to'].'.csv';

            return response()->streamDownload(function () use ($ledger, $supplier, $range) {
                $out = fopen('php://output', 'w');

                fputcsv($out, ['Supplier', $supplier->name, $supplier->code]);
                fputcsv($out, ['Statement', $range['from'].' to '.$range['to']]);
                fputcsv($out, []);
                fputcsv($out, ['Date', 'Reference', 'Description', 'Settled (Dr)', 'Billed (Cr)', 'Balance owed']);
                fputcsv($out, ['', '', 'Opening balance', '', '', number_format($ledger['opening'], 2, '.', '')]);

                foreach ($ledger['lines'] as $line) {
                    fputcsv($out, [
                        $line['date'],
                        $line['reference'],
                        $line['description'],
                        number_format($line['debit'], 2, '.', ''),
                        number_format($line['credit'], 2, '.', ''),
                        number_format($line['balance'], 2, '.', ''),
                    ]);
                }

                fputcsv($out, ['', '', 'Closing balance', number_format($ledger['totals']['debit'], 2, '.', ''), number_format($ledger['totals']['credit'], 2, '.', ''), number_format($ledger['closing'], 2, '.', '')]);
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv']);
        }

        return view('purchase.suppliers.statement', [
            'supplier' => $supplier->load('district:id,name'),
            'ledger' => $ledger,
            'payables' => $this->query->supplierPayables($supplier->id),
            'range' => $range,
        ]);
    }

    /** @return array{from:string,to:string} */
    protected function range(Request $request): array
    {
        return [
            'from' => (string) ($request->query('from') ?: now()->startOfMonth()->toDateString()),
            'to' => (string) ($request->query('to') ?: now()->toDateString()),
        ];
    }

    /**
     * The account list: every supplier we have done money with, and the
     * balance those documents leave. This is the screen "Supplier Ledger" in
     * the menu opens — a per-supplier account needs a supplier, so the list is
     * what a menu leaf can honestly be.
     */
    public function ledgerIndex(Request $request): View
    {
        $rows = $this->query->supplierBalances($this->branchIds($request));
        $search = trim((string) $request->query('q'));
        $filtered = $search === ''
            ? $rows
            : $rows->filter(fn ($row) => str_contains(mb_strtolower($row['supplier']->name.' '.$row['supplier']->code), mb_strtolower($search)));

        return view('purchase.suppliers.ledger-index', [
            'rows' => $filtered->values(),
            'search' => $search,
            'totals' => [
                'billed' => round((float) $rows->sum('billed'), 2),
                'paid' => round((float) $rows->sum('paid'), 2),
                'credited' => round((float) $rows->sum('credited'), 2),
                'outstanding' => round((float) $rows->sum('outstanding'), 2),
            ],
        ]);
    }

    /** @return array<int, int> */
    protected function branchIds(Request $request): array
    {
        $ids = $request->user()?->accessibleBranchIds();

        return $ids === null ? [] : array_map('intval', $ids);
    }
}
