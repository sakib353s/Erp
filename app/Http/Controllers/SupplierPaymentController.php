<?php

namespace App\Http\Controllers;

use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\PurchaseBill;
use App\Domain\Purchase\Queries\PurchaseQuery;
use App\Domain\Purchase\Services\SupplierPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Supplier payments (§03.7). Money out always goes through
 * SupplierPaymentService: it posts Dr Accounts Payable / Cr Cash or Bank,
 * allocates to the bill and advances the bill's balance — this controller only
 * collects the facts.
 */
class SupplierPaymentController extends Controller
{
    public function __construct(
        protected SupplierPaymentService $payments,
        protected PurchaseQuery $query,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q')),
            'supplier' => $request->query('supplier') !== null && $request->query('supplier') !== '' ? (int) $request->query('supplier') : null,
            'method' => (string) $request->query('method'),
            'bill' => $request->query('bill') !== null && $request->query('bill') !== '' ? (int) $request->query('bill') : null,
            'from' => (string) $request->query('from'),
            'to' => (string) $request->query('to'),
        ];

        return view('purchase.payments.index', [
            'payments' => $this->query->payments($filters, $this->branchIds($request)),
            'filters' => $filters,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'code']),
            'summary' => $this->query->paymentSummary($this->branchIds($request)),
        ]);
    }

    public function create(Request $request): View
    {
        $bill = $request->filled('bill')
            ? PurchaseBill::query()->with('supplier:id,name,code')->findOrFail((int) $request->query('bill'))
            : null;

        return view('purchase.payments.form', [
            'bill' => $bill,
            'payableBills' => $this->query->payableBills(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bill_id' => ['required', 'integer', 'exists:purchase_bills,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'in:cash,bank,cheque,mobile'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:64'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $payment = $this->payments->handle($data, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['payment' => $e->getMessage()]);
        }

        $bill = PurchaseBill::query()->find($data['bill_id']);

        return redirect()->route('purchase.payments.index')
            ->with('status', sprintf(
                'Payment %s of ৳ %s recorded against %s — the bill is now %s.',
                $payment->receipt_no,
                number_format((float) $payment->amount, 2),
                $bill?->code ?? 'the bill',
                $bill?->status === 'paid' ? 'fully paid' : 'partially paid',
            ));
    }

    /** @return array<int, int> */
    protected function branchIds(Request $request): array
    {
        $ids = $request->user()?->accessibleBranchIds();

        return $ids === null ? [] : array_map('intval', $ids);
    }
}
