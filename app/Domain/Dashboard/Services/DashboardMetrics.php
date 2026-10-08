<?php

namespace App\Domain\Dashboard\Services;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\JournalLine;
use App\Domain\Customers\Queries\CustomerQuery;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\BatchService;
use App\Domain\Inventory\Services\ReorderService;
use App\Domain\Inventory\StockBatch;
use App\Domain\Purchase\Models\PurchaseBill;
use App\Domain\Purchase\Models\PurchaseOrder;
use App\Domain\Purchase\Models\PurchaseReturn;
use App\Domain\Purchase\Queries\PurchaseQuery;
use App\Domain\Sales\Payment;
use App\Domain\Sales\Queries\LeaderboardQuery;
use App\Domain\Sales\SalesOrder;
use App\Domain\Settings\Services\SettingService;
use App\Domain\Workflow\ApprovalRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's real figures (§01, decision D22).
 *
 * Every one of the 25 containers resolves to one of three honest states:
 *   · `ok`          — a number computed from the company's own documents;
 *   · `empty`       — the source exists, it has no rows yet;
 *   · `unavailable` — the source does not exist yet, and the panel says which
 *                     module will fill it instead of inventing a statistic.
 *
 * Nothing here estimates, projects or fills gaps. A panel with no data says so.
 */
class DashboardMetrics
{
    /**
     * The invoice states that represent money actually charged. `draft` and
     * `void` are paperwork, `pending` has not been issued — the sales reports
     * use the same three, so a dashboard figure always matches the report it
     * links to.
     */
    protected const SOLD_STATUSES = ['issued', 'partial', 'paid'];

    /** Invoices with an outstanding balance: issued but not settled. */
    protected const OUTSTANDING_STATUSES = ['issued', 'partial'];

    /**
     * Which permission unlocks each container — the *module's own* view key,
     * not a private dashboard vocabulary. A panel that sums invoices is
     * therefore invisible to someone who may not read invoices, and the rule
     * lives in one place instead of being re-derived per screen
     * (see docs/TRACEABILITY/01-dashboard.md, deviation D22.1).
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'todays_sales' => 'sales.invoices.view',
        'todays_purchase' => 'purchase.bills.view',
        'todays_cash_position' => 'accounting.coa.view',
        'todays_collection' => 'accounting.receipts.view',
        'todays_payments_due' => 'purchase.bills.view',
        'todays_expense' => 'accounting.ledger.view',
        'todays_profit' => 'accounting.reports.view',
        'receivable_aging' => 'accounting.ar.view',
        'payable_aging' => 'accounting.ap.view',
        'top_10_customers' => 'customers.view',
        'top_10_products' => 'inventory.products.view',
        'top_10_employees' => 'sales.team.view',
        'pending_orders' => 'sales.orders.view',
        'pending_purchase_orders' => 'purchase.orders.view',
        'pending_approvals' => 'approvals.view',
        'pending_returns' => 'purchase.returns.view',
        'pending_refunds' => 'returns.refunds.view',
        'low_stock_alert' => 'inventory.stock.view',
        'out_of_stock_alert' => 'inventory.stock.view',
        'expiring_products_alert' => 'inventory.batch.view',
        'payment_reminders' => 'customers.due.view',
        'sales_chart' => 'sales.invoices.view',
        'purchase_chart' => 'purchase.receipts.view',
        'cash_flow_chart' => 'accounting.ledger.view',
        'branch_activity' => 'audit.view',
    ];

    /** The permission that unlocks a container, or null when it has no key yet. */
    public function permissionFor(string $code): ?string
    {
        return self::PERMISSIONS[$code] ?? null;
    }

    public function __construct(
        protected TenantContext $context,
        protected CustomerQuery $customers,
        protected PurchaseQuery $purchases,
        protected ReorderService $reorder,
        protected LeaderboardQuery $leaderboard,
        protected BatchService $batches,
        protected SettingService $settings,
    ) {}

    /**
     * Only the containers the caller may see are computed, so a restricted user
     * does not pay for figures they cannot read — and cannot read them.
     *
     * @param  array<int, string>|null  $codes
     * @return array<string, array<string, mixed>> keyed by widget code
     */
    public function for(?array $codes = null): array
    {
        $builders = $this->builders();

        if ($codes !== null) {
            $builders = array_intersect_key($builders, array_flip($codes));
        }

        $out = [];

        foreach ($builders as $code => $builder) {
            $out[$code] = $builder();
        }

        return $out;
    }

    /** @return array<string, callable(): array<string, mixed>> */
    protected function builders(): array
    {
        return [
            'todays_sales' => fn () => $this->todaysSales(),
            'todays_purchase' => fn () => $this->todaysPurchase(),
            'todays_cash_position' => fn () => $this->todaysCashPosition(),
            'todays_collection' => fn () => $this->todaysCollection(),
            'todays_payments_due' => fn () => $this->todaysPaymentsDue(),
            'todays_expense' => fn () => $this->todaysExpense(),
            'todays_profit' => fn () => $this->todaysProfit(),
            'receivable_aging' => fn () => $this->receivableAging(),
            'payable_aging' => fn () => $this->payableAging(),
            'top_10_customers' => fn () => $this->topCustomers(),
            'top_10_products' => fn () => $this->topProducts(),
            'top_10_employees' => fn () => $this->topEmployees(),
            'pending_orders' => fn () => $this->pendingOrders(),
            'pending_purchase_orders' => fn () => $this->pendingPurchaseOrders(),
            'pending_approvals' => fn () => $this->pendingApprovals(),
            'pending_returns' => fn () => $this->pendingReturns(),
            'pending_refunds' => fn () => $this->pendingRefunds(),
            'low_stock_alert' => fn () => $this->lowStock(),
            'out_of_stock_alert' => fn () => $this->outOfStock(),
            'expiring_products_alert' => fn () => $this->expiringProducts(),
            'payment_reminders' => fn () => $this->paymentReminders(),
            'sales_chart' => fn () => $this->salesChart(),
            'purchase_chart' => fn () => $this->purchaseChart(),
            'cash_flow_chart' => fn () => $this->cashFlowChart(),
            'branch_activity' => fn () => $this->branchActivity(),
        ];
    }

    /* ------------------------------------------------------------- money ---- */

    protected function todaysSales(): array
    {
        $today = now()->toDateString();

        $rows = DB::table('invoices')
            ->where('company_id', $this->companyId())
            ->whereDate('invoice_date', $today)
            ->whereIn('status', self::SOLD_STATUSES)
            ->selectRaw('COUNT(*) as documents, COALESCE(SUM(grand_total), 0) as total, COALESCE(SUM(due_amount), 0) as due')
            ->first();

        $count = (int) ($rows->documents ?? 0);
        $total = (float) ($rows->total ?? 0);

        return $count === 0
            ? $this->empty('Nothing issued today yet.', 'Sales invoices', route('sales.invoices.index'))
            : $this->figure(
                $this->money($total),
                $count.' invoice'.($count === 1 ? '' : 's').' today',
                'Unpaid of that: '.$this->money((float) $rows->due),
                'Sales invoices',
                route('sales.invoices.index'),
            );
    }

    protected function todaysPurchase(): array
    {
        $today = now()->toDateString();

        $row = DB::table('purchase_bills')
            ->where('company_id', $this->companyId())
            ->whereDate('bill_date', $today)
            ->where('posting_state', 'posted')
            ->selectRaw('COUNT(*) as documents, COALESCE(SUM(total), 0) as total, COALESCE(SUM(due_amount), 0) as due')
            ->first();

        $count = (int) ($row->documents ?? 0);

        return $count === 0
            ? $this->empty('No supplier bill posted today.', 'Purchase bills', route('purchase.bills.index'))
            : $this->figure(
                $this->money((float) $row->total),
                $count.' bill'.($count === 1 ? '' : 's').' posted today',
                'Still owed on them: '.$this->money((float) $row->due),
                'Purchase bills',
                route('purchase.bills.index'),
            );
    }

    /** Cash + bank position from the general ledger, never from a hand count. */
    protected function todaysCashPosition(): array
    {
        $position = $this->glPosition(fn ($q) => $q->where(fn ($w) => $w->where('is_cash', true)->orWhere('is_bank', true)));

        if ($position['accounts'] === 0) {
            return $this->unavailable(
                'The chart of accounts has no cash or bank account flagged yet.',
                'Chart of accounts',
                route('accounting.coa'),
            );
        }

        return $this->figure(
            $this->money($position['balance']),
            $position['accounts'].' cash/bank account'.($position['accounts'] === 1 ? '' : 's'),
            'Derived from posted journal entries — the GL is the only source.',
            'General ledger',
            route('accounting.coa'),
        );
    }

    protected function todaysCollection(): array
    {
        $today = now()->toDateString();

        $row = DB::table('payments')
            ->where('company_id', $this->companyId())
            ->where('direction', 'in')
            ->whereDate('paid_at', $today)
            ->where('status', 'posted')
            ->selectRaw('COUNT(*) as documents, COALESCE(SUM(amount), 0) as total')
            ->first();

        $count = (int) ($row->documents ?? 0);

        return $count === 0
            ? $this->empty('No customer receipt recorded today.', 'Customer dues', route('customers.due'))
            : $this->figure(
                $this->money((float) $row->total),
                $count.' receipt'.($count === 1 ? '' : 's').' today',
                'Money actually received, posted to the ledger.',
                'Customer dues',
                route('customers.due'),
            );
    }

    protected function todaysPaymentsDue(): array
    {
        $today = now()->toDateString();

        $payable = DB::table('purchase_bills')
            ->where('company_id', $this->companyId())
            ->whereDate('due_date', $today)
            ->where('posting_state', 'posted')
            ->whereRaw('total - paid_amount - credited_amount > 0')
            ->selectRaw('COUNT(*) as documents, COALESCE(SUM(total - paid_amount - credited_amount), 0) as total')
            ->first();

        $receivable = DB::table('invoices')
            ->where('company_id', $this->companyId())
            ->whereDate('due_date', $today)
            ->whereIn('status', self::OUTSTANDING_STATUSES)
            ->whereRaw('grand_total > paid_amount')
            ->selectRaw('COUNT(*) as documents, COALESCE(SUM(grand_total - paid_amount), 0) as total')
            ->first();

        $bills = (int) ($payable->documents ?? 0);
        $invoices = (int) ($receivable->documents ?? 0);

        if ($bills === 0 && $invoices === 0) {
            return $this->empty('Nothing falls due today on either side.', 'Payables ageing', route('purchase.payables'));
        }

        return $this->figure(
            $this->money((float) ($payable->total ?? 0)).' out / '.$this->money((float) ($receivable->total ?? 0)).' in',
            $bills.' bill(s) and '.$invoices.' invoice(s) due today',
            'Due dates only — a document without a due date is never late.',
            'Payables ageing',
            route('purchase.payables'),
        );
    }

    protected function todaysExpense(): array
    {
        $total = $this->glNet(fn ($q) => $q->where('type', 'expense'), 'debit');

        if ($total === 0.0) {
            return $this->empty('No expense posted today.', 'General ledger', route('accounting.coa'));
        }

        return $this->figure(
            $this->money($total),
            'Expense accounts, posted today',
            'Debits less credits on every account of type expense.',
            'General ledger',
            route('accounting.coa'),
        );
    }

    /** Revenue less expenses from the ledger — labelled exactly that way. */
    protected function todaysProfit(): array
    {
        $revenue = $this->glNet(fn ($q) => $q->where('type', 'income'), 'credit');
        $expense = $this->glNet(fn ($q) => $q->where('type', 'expense'), 'debit');

        if ($revenue === 0.0 && $expense === 0.0) {
            return $this->empty('Nothing has been posted today, so there is no profit figure to show.', 'General ledger', route('accounting.coa'));
        }

        return $this->figure(
            $this->money($revenue - $expense),
            'Revenue '.$this->money($revenue).' − expenses '.$this->money($expense),
            'Not a full P&L: cost of goods sold and closing adjustments belong to the accounting reports.',
            'General ledger',
            route('accounting.coa'),
        );
    }

    /* --------------------------------------------------------- receivables -- */

    protected function receivableAging(): array
    {
        $buckets = $this->customers->dueBuckets($this->companyId());

        return $this->aged($buckets, 'Customers', route('customers.due'));
    }

    protected function payableAging(): array
    {
        $ageing = $this->purchases->payablesAgeing($this->branchIds());
        $buckets = [];

        foreach ($ageing['totals'] ?? [] as $key => $value) {
            if (in_array($key, ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'], true)) {
                $buckets[$key] = ['label' => $this->bucketLabel($key), 'count' => null, 'amount' => (float) $value];
            }
        }

        return $this->aged($buckets, 'Suppliers', route('purchase.payables'));
    }

    protected function paymentReminders(): array
    {
        $buckets = $this->customers->dueBuckets($this->companyId());
        $overdue = 0.0;
        $count = 0;

        foreach (['1_30', '31_60', '61_90', '90_plus'] as $key) {
            $overdue += (float) ($buckets[$key]['amount'] ?? 0);
            $count += (int) ($buckets[$key]['count'] ?? 0);
        }

        return $count === 0
            ? $this->empty('Nothing is past its due date.', 'Customer dues', route('customers.due'))
            : $this->figure(
                $this->money($overdue),
                $count.' invoice(s) past due',
                'This is the ageing of real invoices — no reminder has been sent by the system.',
                'Customer dues',
                route('customers.due'),
            );
    }

    /* ------------------------------------------------------------- ranking -- */

    protected function topCustomers(): array
    {
        [$from, $to] = $this->monthRange();

        $rows = DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->where('invoices.company_id', $this->companyId())
            ->whereDate('invoices.invoice_date', '>=', $from)->whereDate('invoices.invoice_date', '<=', $to)
            ->whereIn('invoices.status', self::SOLD_STATUSES)
            ->groupBy('customers.id', 'customers.name')
            ->orderByDesc(DB::raw('SUM(invoices.grand_total)'))
            ->limit(10)
            ->selectRaw('customers.name as label, SUM(invoices.grand_total) as value')
            ->get();

        return $this->ranking($rows, 'No invoice has been issued this month yet.', 'Customer ledger', route('customers.ledger'));
    }

    protected function topProducts(): array
    {
        [$from, $to] = $this->monthRange();

        $rows = DB::table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->where('invoices.company_id', $this->companyId())
            ->whereDate('invoices.invoice_date', '>=', $from)->whereDate('invoices.invoice_date', '<=', $to)
            ->whereIn('invoices.status', self::SOLD_STATUSES)
            ->groupBy('invoice_lines.product_id', 'invoice_lines.description')
            ->orderByDesc(DB::raw('SUM(invoice_lines.line_total)'))
            ->limit(10)
            ->selectRaw('invoice_lines.product_id as product_id, invoice_lines.description as description, SUM(invoice_lines.line_total) as value')
            ->get();

        $names = Product::query()
            ->whereIn('id', $rows->pluck('product_id')->filter()->all())
            ->pluck('name', 'id');

        return $this->ranking(
            $rows->map(fn ($row) => (object) [
                'label' => $row->product_id !== null
                    ? (string) ($names[$row->product_id] ?? 'Product #'.$row->product_id)
                    : (string) ($row->description ?: 'Free-text item'),
                'value' => (float) $row->value,
            ]),
            'No invoice line has been sold this month yet.',
            'Products',
            route('inventory.products.index'),
        );


    }

    /**
     * The same ranking the sales team screen shows, from the same query — a
     * dashboard that ranked people differently from their own page would be a
     * bug, not a second opinion.
     */
    protected function topEmployees(): array
    {
        $board = $this->leaderboard->forPeriod($this->companyId(), 'monthly');

        $rows = collect($board['rows'])
            ->filter(fn (array $row) => $row['invoice_count'] > 0)
            ->take(10)
            ->map(fn (array $row) => (object) [
                'label' => (string) ($row['employee']->full_name ?? 'Employee'),
                'value' => (float) $row['revenue'],
            ])
            ->values();

        return $this->ranking(
            $rows,
            'No invoice is attributed to a sales person this month.',
            'Sales team',
            route('sales.team.index'),
        );
    }

    /* ----------------------------------------------------------- pipelines -- */

    protected function pendingOrders(): array
    {
        $count = SalesOrder::query()
            ->where('company_id', $this->companyId())
            ->whereNotIn('status', ['delivered', 'completed', 'cancelled', 'returned', 'refunded'])
            ->count();

        return $count === 0
            ? $this->empty('No order is still open.', 'Sales orders', route('sales.orders.index'))
            : $this->figure((string) $count, 'order(s) not yet fulfilled', 'Orders that are not delivered, completed or cancelled.', 'Sales orders', route('sales.orders.index'));
    }

    protected function pendingPurchaseOrders(): array
    {
        $count = PurchaseOrder::query()
            ->where('company_id', $this->companyId())
            ->whereIn('status', ['draft', 'pending_approval', 'approved', 'partially_received'])
            ->count();

        return $count === 0
            ? $this->empty('No purchase order is outstanding.', 'Purchase orders', route('purchase.orders.index'))
            : $this->figure((string) $count, 'order(s) not fully received', 'Draft, awaiting approval, approved or partly received.', 'Purchase orders', route('purchase.orders.index'));
    }

    protected function pendingApprovals(): array
    {
        $count = ApprovalRequest::query()->where('status', 'pending')->count();

        return $count === 0
            ? $this->empty('The approval queue is clear.', 'Approval inbox', route('approvals.index'))
            : $this->figure((string) $count, 'request(s) awaiting a decision', 'Across every module that raises approvals.', 'Approval inbox', route('approvals.index'));
    }

    protected function pendingReturns(): array
    {
        $purchase = PurchaseReturn::query()
            ->where('company_id', $this->companyId())
            ->whereIn('status', PurchaseReturn::OPEN_STATUSES)
            ->count();

        $sales = DB::table('sales_returns')
            ->where('company_id', $this->companyId())
            ->whereNotIn('status', ['refunded', 'cancelled', 'denied'])
            ->count();

        return ($purchase + $sales) === 0
            ? $this->empty('No return is in flight.', 'Purchase returns', route('purchase.returns.index'))
            : $this->figure(
                (string) ($purchase + $sales),
                'return(s) not closed',
                $purchase.' purchase · '.$sales.' sales',
                'Purchase returns',
                route('purchase.returns.index'),
            );
    }

    /**
     * A refund becomes a document the moment it is paid out (`refunds` rows are
     * written with status posted), so what can still be *pending* is the return
     * that has been accepted but not yet paid: the money the customer is owed.
     */
    protected function pendingRefunds(): array
    {
        $returns = DB::table('sales_returns')
            ->where('company_id', $this->companyId())
            ->whereIn('status', ['approved', 'received', 'inspected', 'credited'])
            ->selectRaw('COUNT(*) as documents, COALESCE(SUM(grand_total), 0) as total')
            ->first();

        $count = (int) ($returns->documents ?? 0);

        return $count === 0
            ? $this->empty('No accepted return is waiting to be paid out.', 'Customer dues', route('customers.due'))
            : $this->figure(
                $this->money((float) $returns->total),
                $count.' return(s) awaiting payout',
                'Accepted returns whose refund has not been paid yet.',
                'Customer dues',
                route('customers.due'),
            );
    }

    /* ----------------------------------------------------------- inventory -- */

    protected function lowStock(): array
    {
        $counts = $this->reorder->alertRows('low')['counts'];

        return $counts['low'] === 0
            ? $this->empty('No watched product is at or below its minimum.', 'Reorder levels', route('inventory.reorder.index'))
            : $this->figure((string) $counts['low'], 'product/warehouse row(s) low', 'Judged against each product\'s own policy.', 'Stock alerts', route('inventory.stock.alerts', ['type' => 'low']));
    }

    protected function outOfStock(): array
    {
        $counts = $this->reorder->alertRows('out')['counts'];

        return $counts['out'] === 0
            ? $this->empty('Nothing watched is out of stock.', 'Stock alerts', route('inventory.stock.alerts', ['type' => 'out']))
            : $this->figure((string) $counts['out'], 'product(s) with nothing on hand', 'Every sale of these is a lost sale until stock arrives.', 'Out of stock', route('inventory.stock.alerts', ['type' => 'out']));
    }

    /**
     * §04-39 as a panel: the batches that are past their date or inside the clock,
     * valued at what the layers say they cost. Expired stock is not sellable, but
     * it is still on the books — which is exactly why the money figure matters
     * more here than the count.
     */
    protected function expiringProducts(): array
    {
        $days = max(1, $this->settings->getInt('inventory', 'expiry_alert_days', 30));
        $buckets = $this->batches->buckets($days);

        $expired = $buckets[StockBatch::STATE_EXPIRED];
        $expiring = $buckets[StockBatch::STATE_EXPIRING];
        $together = $expired['batches'] + $expiring['batches'];

        if ($together === 0) {
            return $this->empty(
                'No batch with stock on hand has expired or is inside the expiry window.',
                'Expiry desk',
                route('inventory.batches.expiry'),
            );
        }

        return $this->figure(
            $this->money($expired['value'] + $expiring['value']),
            number_format($together).' batch(es) past their date or expiring soon',
            $expired['batches'] > 0
                ? number_format($expired['batches']).' already expired — not sellable, still on the books'
                : number_format($expiring['batches']).' expiring within '.$days.' days',
            'Expiry desk',
            route('inventory.batches.expiry', ['days' => $days]),
        );
    }

    /* -------------------------------------------------------------- charts -- */

    protected function salesChart(): array
    {
        [$from, $to] = $this->trailingDays(14);

        $series = DB::table('invoices')
            ->where('company_id', $this->companyId())
            ->whereDate('invoice_date', '>=', $from)->whereDate('invoice_date', '<=', $to)
            ->whereIn('status', self::SOLD_STATUSES)
            ->groupBy('invoice_date')
            ->orderBy('invoice_date')
            ->selectRaw('invoice_date as day, SUM(grand_total) as value')
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->day, 'value' => (float) $row->value])
            ->all();

        if ($series === []) {
            return $this->empty('No invoice in the last 14 days.', 'Sales reports', route('sales.invoices.index'));
        }

        return $this->chart($series, 'Daily invoiced value', 'Sales invoices', route('sales.invoices.index'));
    }

    protected function purchaseChart(): array
    {
        $byDay = $this->purchases->receiptsByDay(14, $this->branchIds());
        $series = [];

        foreach ($byDay as $label => $value) {
            if (is_array($value) && isset($value['value'])) {
                $series[] = ['label' => (string) ($value['label'] ?? $label), 'value' => (float) $value['value']];
                continue;
            }

            $series[] = ['label' => is_string($label) ? $label : (string) $label, 'value' => (float) $value];
        }

        if ($series === []) {
            return $this->empty('No goods received in the last 14 days.', 'Goods receipts', route('purchase.receipts.index'));
        }

        return $this->chart($series, 'Value received by day', 'Goods receipts', route('purchase.receipts.index'));
    }

    protected function cashFlowChart(): array
    {
        [$from, $to] = $this->trailingDays(14);

        $rows = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.company_id', $this->companyId())
            ->where('journal_entries.posting_state', JournalEntry::STATE_POSTED)
            ->whereDate('journal_entries.entry_date', '>=', $from)->whereDate('journal_entries.entry_date', '<=', $to)
            ->where(fn ($q) => $q->where('accounts.is_cash', true)->orWhere('accounts.is_bank', true))
            ->groupBy('journal_entries.entry_date')
            ->orderBy('journal_entries.entry_date')
            ->selectRaw("journal_entries.entry_date as day, SUM(CASE WHEN journal_lines.dc = 'debit' THEN journal_lines.amount ELSE 0 END) as inflow, SUM(CASE WHEN journal_lines.dc = 'credit' THEN journal_lines.amount ELSE 0 END) as outflow")
            ->get();

        if ($rows->isEmpty()) {
            return $this->empty('No cash or bank entry in the last 14 days.', 'General ledger', route('accounting.coa'));
        }

        $series = $rows->map(fn ($row) => [
            'label' => (string) $row->day,
            'value' => round((float) $row->inflow - (float) $row->outflow, 4),
            'detail' => 'in '.$this->money((float) $row->inflow).' · out '.$this->money((float) $row->outflow),
        ])->all();

        return $this->chart($series, 'Net cash movement by day (in less out)', 'General ledger', route('accounting.coa'));
    }

    protected function branchActivity(): array
    {
        [$from, $to] = $this->monthRange();

        $branches = DB::table('invoices')
            ->join('branches', 'branches.id', '=', 'invoices.branch_id')
            ->where('invoices.company_id', $this->companyId())
            ->whereDate('invoices.invoice_date', '>=', $from)->whereDate('invoices.invoice_date', '<=', $to)
            ->whereIn('invoices.status', self::SOLD_STATUSES)
            ->groupBy('branches.id', 'branches.name')
            ->orderByDesc(DB::raw('SUM(invoices.grand_total)'))
            ->selectRaw('branches.name as label, SUM(invoices.grand_total) as value')
            ->get();

        $rows = $branches->map(fn ($row) => ['label' => (string) $row->label, 'value' => (float) $row->value])->all();

        return $this->chart(
            $rows,
            'Invoiced this month per branch',
            'Branches',
            $rows === [] ? route('branches.index') : route('sales.invoices.index'),
            $rows === [] ? 'No invoice has been issued this month, so there is nothing to compare between branches.' : null,
        );
    }

    /* ------------------------------------------------------------ helpers --- */

    protected function companyId(): int
    {
        return (int) ($this->context->companyId() ?? 0);
    }

    /** Branch scope: the branch switched in the top bar, when one is chosen. */
    protected function branchIds(): array
    {
        $branchId = $this->context->branchId();

        return $branchId !== null ? [(int) $branchId] : [];
    }

    /** @return array{0: string, 1: string} */
    protected function monthRange(): array
    {
        return [now()->startOfMonth()->toDateString(), now()->toDateString()];
    }

    /** @return array{0: string, 1: string} */
    protected function trailingDays(int $days): array
    {
        return [now()->subDays($days - 1)->toDateString(), now()->toDateString()];
    }

    /** Balance of the accounts matching the filter, from posted entries up to today. */
    protected function glPosition(callable $accountFilter): array
    {
        $accounts = Account::query()
            ->where('company_id', $this->companyId())
            ->where('is_active', true)
            ->where($accountFilter)
            ->pluck('id');

        if ($accounts->isEmpty()) {
            return ['balance' => 0.0, 'accounts' => 0];
        }

        $row = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.company_id', $this->companyId())
            ->where('journal_entries.posting_state', JournalEntry::STATE_POSTED)
            ->whereIn('journal_lines.account_id', $accounts)
            ->selectRaw("COALESCE(SUM(CASE WHEN journal_lines.dc = 'debit' THEN journal_lines.amount ELSE -journal_lines.amount END), 0) as balance")
            ->first();

        return ['balance' => round((float) ($row->balance ?? 0), 4), 'accounts' => $accounts->count()];
    }

    /** Today's net movement on accounts matching the filter, in one direction. */
    protected function glNet(callable $accountFilter, string $direction): float
    {
        $today = now()->toDateString();

        $accounts = Account::query()
            ->where('company_id', $this->companyId())
            ->where('is_active', true)
            ->where($accountFilter)
            ->pluck('id');

        if ($accounts->isEmpty()) {
            return 0.0;
        }

        $row = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.company_id', $this->companyId())
            ->where('journal_entries.posting_state', JournalEntry::STATE_POSTED)
            ->whereDate('journal_entries.entry_date', $today)
            ->whereIn('journal_lines.account_id', $accounts)
            ->where('journal_lines.dc', $direction)
            ->selectRaw('COALESCE(SUM(journal_lines.amount), 0) as total')
            ->first();

        return round((float) ($row->total ?? 0), 4);
    }

    /** @param array<string, array{label:string,count:?int,amount:float}> $buckets */
    protected function aged(array $buckets, string $hrefLabel, string $href): array
    {
        $rows = [];
        $total = 0.0;

        foreach ($buckets as $bucket) {
            $total += (float) $bucket['amount'];
            $rows[] = [
                'label' => (string) $bucket['label'],
                'value' => $this->money((float) $bucket['amount']),
                'muted' => (float) $bucket['amount'] === 0.0,
            ];
        }

        if ($total === 0.0) {
            return $this->empty('Nothing is outstanding.', $hrefLabel, $href);
        }

        return [
            'state' => 'ok',
            'primary' => $this->money($total),
            'caption' => 'Outstanding, bucketed by each document\'s own due date',
            'note' => 'A document without a due date is never late.',
            'href' => $href,
            'href_label' => $hrefLabel,
            'rows' => $rows,
        ];
    }

    /** @param \Illuminate\Support\Collection<int, object> $rows */
    protected function ranking($rows, string $emptyNote, string $hrefLabel, string $href): array
    {
        if ($rows->isEmpty()) {
            return $this->empty($emptyNote, $hrefLabel, $href);
        }

        return [
            'state' => 'ok',
            'primary' => null,
            'caption' => 'This month, from posted documents',
            'note' => null,
            'href' => $href,
            'href_label' => $hrefLabel,
            'rows' => $rows->map(fn ($row) => [
                'label' => (string) $row->label,
                'value' => $this->money((float) $row->value),
                'muted' => false,
            ])->all(),
        ];
    }

    /** @param array<int, array{label:string,value:float,detail?:string}> $series */
    protected function chart(array $series, string $caption, string $hrefLabel, string $href, ?string $emptyNote = null): array
    {
        if ($series === []) {
            return $this->empty($emptyNote ?? 'No movement in this period.', $hrefLabel, $href);
        }

        $total = array_sum(array_column($series, 'value'));
        $max = max(array_map(fn ($point) => abs((float) $point['value']), $series));

        return [
            'state' => 'ok',
            'primary' => null,
            'caption' => $caption,
            'note' => $emptyNote ?? 'Total over the period: '.$this->money($total),
            'href' => $href,
            'href_label' => $hrefLabel,
            'series' => array_map(function ($point) use ($max) {
                $label = (string) $point['label'];
                $point['short'] = preg_match('/^\d{4}-\d{2}-\d{2}/', $label) === 1
                    ? Carbon::parse($label)->format('d M')
                    : $label;
                $point['width'] = $max > 0 ? (int) round(abs((float) $point['value']) / $max * 100) : 0;

                return $point;
            }, $series),
        ];
    }

    protected function figure(string $primary, string $caption, ?string $note, string $hrefLabel, string $href): array
    {
        return [
            'state' => 'ok',
            'primary' => $primary,
            'caption' => $caption,
            'note' => $note,
            'href' => $href,
            'href_label' => $hrefLabel,
        ];
    }

    protected function empty(string $note, string $hrefLabel, string $href): array
    {
        return [
            'state' => 'empty',
            'primary' => null,
            'caption' => 'No data yet',
            'note' => $note,
            'href' => $href,
            'href_label' => $hrefLabel,
        ];
    }

    protected function unavailable(string $note, string $hrefLabel, string $href): array
    {
        return [
            'state' => 'unavailable',
            'primary' => null,
            'caption' => 'Its module has no source yet',
            'note' => $note,
            'href' => $href,
            'href_label' => $hrefLabel,
        ];
    }

    protected function bucketLabel(string $key): string
    {
        return match ($key) {
            'current' => 'Not yet due',
            'd1_30' => 'Overdue 1-30 days',
            'd31_60' => 'Overdue 31-60 days',
            'd61_90' => 'Overdue 61-90 days',
            'd90_plus' => 'Overdue 90+ days',
            default => $key,
        };
    }

    protected function money(float $amount): string
    {
        return number_format(round($amount, 2), 2);
    }
}
