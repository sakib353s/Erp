<?php

namespace App\Domain\Reporting;

use App\Domain\Foundation\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * The report centre's index (§13-01…§13-12).
 *
 * A hub is only worth having if everything on it is real, so this registry is
 * written against the router rather than against hope: every entry names the
 * route it lives on, {@see entries()} drops anything whose route is not
 * registered, and the permission shown on a row is read back off that route's
 * own middleware instead of being typed a second time. Two things follow from
 * that, and both are the point:
 *
 *  - a report that has not been built cannot appear on a hub, so the hub never
 *    offers a door that 404s; and
 *  - a report that exists but whose route is locked can still be *listed*, as a
 *    named row the reader cannot open, saying which key it needs. Hiding it
 *    would make a manager think the system lacks a report it actually has.
 *
 * `planned` is how a family says what is missing and why, in words, on the page
 * — instead of an empty card that reads as a broken screen.
 */
class ReportRegistry
{
    /**
     * The catalogue's report families. Each leaf of “13. REPORTS” is its own
     * route and its own permission: reading the sales reports is not the same
     * job as reading the payroll.
     *
     * @var array<string, array{title: string, blurb: string, icon: string, permission: string, planned?: string, needs?: string}>
     */
    public const FAMILIES = [
        'sales' => [
            'title' => 'Sales reports',
            'blurb' => 'What sold, to whom, through which channel and at what hour — every figure a posted invoice, a payment or a counter sale.',
            'icon' => 'bi-cart3',
            'permission' => 'reports.sales',
        ],
        'purchase' => [
            'title' => 'Purchase reports',
            'blurb' => 'What the company bought, from whom, and what it still owes for it.',
            'icon' => 'bi-bag-check',
            'permission' => 'reports.purchase',
            'planned' => 'Purchase reports proper — bills by supplier, purchases by product, price-movement analysis — are §03 rows that have not been built; what is on this page today is the buying side read through the registers that do exist.',
        ],
        'inventory' => [
            'title' => 'Inventory reports',
            'blurb' => 'What is on the shelf, how long it has been there, what it is worth and what it cost to pack.',
            'icon' => 'bi-boxes',
            'permission' => 'reports.inventory',
        ],
        'customers' => [
            'title' => 'Customer reports',
            'blurb' => 'Who owes what and for how long, with the ledger and the statement behind each name.',
            'icon' => 'bi-people',
            'permission' => 'reports.customers',
            'planned' => 'Collections, referral and loyalty reporting (§05) are not built yet; the account itself — ledger, ageing and statement — is.',
        ],
        'suppliers' => [
            'title' => 'Supplier reports',
            'blurb' => 'What is owed to each supplier, spread by how long it has been owed.',
            'icon' => 'bi-truck',
            'permission' => 'reports.suppliers',
            'planned' => 'Contract, scoring and document reporting (§06) are not built; the payable side is.',
        ],
        'finance' => [
            'title' => 'Finance reports',
            'blurb' => 'The books themselves: trial balance, the general ledger, the money desk and the expense register.',
            'icon' => 'bi-journal-text',
            'permission' => 'reports.finance',
        ],
        'tax' => [
            'title' => 'VAT &amp; tax reports',
            'blurb' => 'What the company has to account for to the tax authority, and what has already been filed.',
            'icon' => 'bi-file-earmark-ruled',
            'permission' => 'reports.tax',
            'planned' => 'A VAT return is a statutory document with its own numbering and its own filing dates (§09-18…§09-28). The invoice-level Mushak 9.1 print exists; the period register behind it does not yet.',
        ],
        'hr' => [
            'title' => 'Employee reports',
            'blurb' => 'Attendance and leave today; salary, overtime and loan reporting once payroll posts.',
            'icon' => 'bi-person-badge',
            'permission' => 'reports.hr',
            'planned' => 'Payroll itself (§10) is not built, so there is nothing to report on yet. Attendance and leave are real and are on this page.',
        ],
        'marketing' => [
            'title' => 'Marketing reports',
            'blurb' => 'What a campaign cost and what it brought back in, measured on the orders it actually produced.',
            'icon' => 'bi-megaphone',
            'permission' => 'reports.marketing',
            'planned' => 'The campaign engine itself (§11) is not built, so there is nothing to measure yet. Promotion performance — the discount given against the orders it produced — is real, and sits on the sales hub.',
        ],
    ];

    /**
     * Every report screen the centre knows about.
     *
     *  - `route`  the contract: no route, no entry;
     *  - `answers` what question the reader is asking when they open it;
     *  - `reads`  the honest answer to “where does this number come from”;
     *  - `via`    for a report that lives on a detail page (one account, one
     *             customer, one employee) — the register it is opened from, so
     *             the hub links to something a reader can actually use.
     *
     * `permission` is deliberately absent: it is read off the route.
     *
     * @var array<int, array{family: string, title: string, route: string, answers: string, reads: string, params?: array<string, mixed>, via?: string}>
     */
    public const REPORTS = [
        // ------------------------------------------------------------- sales
        ['family' => 'sales', 'title' => 'Sales summary', 'route' => 'sales.reports.summary',
            'answers' => 'How much was invoiced, how much was collected, and how much is still due over a window.',
            'reads' => 'Invoices, payments and returns'],
        ['family' => 'sales', 'title' => 'Sales trend', 'route' => 'sales.reports.trend',
            'answers' => 'Whether this period is better or worse than the one before it.',
            'reads' => 'Posted invoices, day by day'],
        ['family' => 'sales', 'title' => 'Peak hours', 'route' => 'sales.reports.peak-hours',
            'answers' => 'Which hours of the day the counter actually earns in.',
            'reads' => 'Counter sales'],
        ['family' => 'sales', 'title' => 'Invoice ageing', 'route' => 'sales.reports.invoice-aging',
            'answers' => 'How much of what customers owe is late, and by how long.',
            'reads' => 'Invoices and the payments against them'],
        ['family' => 'sales', 'title' => 'Promotion performance', 'route' => 'sales.reports.promotions',
            'answers' => 'What each promotion gave away and what it produced.',
            'reads' => 'Promotions, their usage, and the orders behind them'],
        ['family' => 'sales', 'title' => 'Sales by product', 'route' => 'sales.reports.by-product', 'params' => ['dim' => 'product'],
            'answers' => 'Which products carry the revenue.',
            'reads' => 'Invoice lines'],
        ['family' => 'sales', 'title' => 'Sales by category', 'route' => 'sales.reports.by-category', 'params' => ['dim' => 'category'],
            'answers' => 'Which parts of the catalogue earn.',
            'reads' => 'Invoice lines and product categories'],
        ['family' => 'sales', 'title' => 'Sales by brand', 'route' => 'sales.reports.by-brand', 'params' => ['dim' => 'brand'],
            'answers' => 'Which brands earn their shelf space.',
            'reads' => 'Invoice lines and brands'],
        ['family' => 'sales', 'title' => 'Sales by customer', 'route' => 'sales.reports.by-customer', 'params' => ['dim' => 'customer'],
            'answers' => 'Who the business actually depends on.',
            'reads' => 'Invoices and customers'],
        ['family' => 'sales', 'title' => 'Sales by salesperson', 'route' => 'sales.reports.by-employee', 'params' => ['dim' => 'employee'],
            'answers' => 'Which salesperson wrote the business.',
            'reads' => 'Invoices and their sales people'],
        ['family' => 'sales', 'title' => 'Sales by branch', 'route' => 'sales.reports.by-branch', 'params' => ['dim' => 'branch'],
            'answers' => 'Which outlet earns and which one only costs.',
            'reads' => 'Invoices and branches'],
        ['family' => 'sales', 'title' => 'Sales by zone', 'route' => 'sales.reports.by-zone', 'params' => ['dim' => 'zone'],
            'answers' => 'Where in the city the demand is.',
            'reads' => 'Invoices and delivery zones'],
        ['family' => 'sales', 'title' => 'Sales by payment method', 'route' => 'sales.reports.by-method', 'params' => ['dim' => 'method'],
            'answers' => 'How customers pay — which is what the tills and wallets have to reconcile against.',
            'reads' => 'Payments'],
        ['family' => 'sales', 'title' => 'Counter sessions', 'route' => 'pos.drawer',
            'answers' => 'What the tills took session by session, and the state of each drawer.',
            'reads' => 'Counter sessions and their movements'],
        ['family' => 'sales', 'title' => 'Sales returns', 'route' => 'sales.returns.index',
            'answers' => 'What came back, why, and what it cost the business.',
            'reads' => 'Return documents and the credit notes behind them'],
        ['family' => 'sales', 'title' => 'Custom sales report', 'route' => 'sales.reports.custom',
            'answers' => 'Any combination of the register’s own columns, saved and re-run.',
            'reads' => 'The sources the builder whitelists, under your scopes'],

        // ---------------------------------------------------------- purchase
        ['family' => 'purchase', 'title' => 'Purchase orders', 'route' => 'purchase.orders.index',
            'answers' => 'What was ordered, at what price, and what has not arrived.',
            'reads' => 'Purchase orders and their receipts'],
        ['family' => 'purchase', 'title' => 'Purchase bills', 'route' => 'purchase.bills.index',
            'answers' => 'What has been billed, what the three-way match said, and what is approved.',
            'reads' => 'Bills, receipts and orders'],
        ['family' => 'purchase', 'title' => 'Payables', 'route' => 'purchase.payables',
            'answers' => 'What the company owes, supplier by supplier.',
            'reads' => 'Approved bills and the payments against them'],
        ['family' => 'purchase', 'title' => 'Supplier payments', 'route' => 'purchase.payments.index',
            'answers' => 'What has been paid out, when and from which account.',
            'reads' => 'Payment documents and their postings'],
        ['family' => 'purchase', 'title' => 'Supplier ageing', 'route' => 'suppliers.ledger.index',
            'answers' => 'The same debt, spread by how long it has been owed.',
            'reads' => 'Bills and their due dates'],
        ['family' => 'purchase', 'title' => 'Purchase returns', 'route' => 'purchase.returns.index',
            'answers' => 'What went back to suppliers and what the debit note said.',
            'reads' => 'Return documents and their debit notes'],

        // --------------------------------------------------------- inventory
        ['family' => 'inventory', 'title' => 'Stock report', 'route' => 'inventory.reports.stock',
            'answers' => 'What is on hand, reserved and available, and what it is worth.',
            'reads' => 'Stock balances and valuation layers'],
        ['family' => 'inventory', 'title' => 'Stock ageing', 'route' => 'inventory.reports.aging',
            'answers' => 'How long the stock on the shelf has been sitting there.',
            'reads' => 'The last movement per product and warehouse'],
        ['family' => 'inventory', 'title' => 'Dead stock', 'route' => 'inventory.reports.dead-stock',
            'answers' => 'What has not moved in longer than the company tolerates.',
            'reads' => 'The movement ledger'],
        ['family' => 'inventory', 'title' => 'Damage &amp; loss', 'route' => 'inventory.reports.damage',
            'answers' => 'What broke, why, and what it cost.',
            'reads' => 'Damage and loss entries valued from the layers'],
        ['family' => 'inventory', 'title' => 'Packaging report', 'route' => 'inventory.reports.packaging',
            'answers' => 'What boxes and tape cost, and whether it was volume or price.',
            'reads' => 'Packaging consumption on packed orders'],
        ['family' => 'inventory', 'title' => 'Packaging cost', 'route' => 'inventory.packaging.cost',
            'answers' => 'The same question at the packaging desk, beside the types it belongs to.',
            'reads' => 'Packaging types and their consumption'],
        ['family' => 'inventory', 'title' => 'Stock movements', 'route' => 'inventory.movements',
            'answers' => 'Every quantity in and out, with the document that moved it.',
            'reads' => 'The movement ledger'],
        ['family' => 'inventory', 'title' => 'Cost history', 'route' => 'inventory.cost-history',
            'answers' => 'When a product’s cost moved, by how much, and who said so.',
            'reads' => 'Append-only cost records beside the valuation layers'],
        ['family' => 'inventory', 'title' => 'Batch &amp; expiry', 'route' => 'inventory.batches.index',
            'answers' => 'Every batch on the shelf, its date, and what is about to expire.',
            'reads' => 'Batches hanging off their valuation layers'],

        // --------------------------------------------------------- customers
        ['family' => 'customers', 'title' => 'Customer list with balances', 'route' => 'customers.index',
            'answers' => 'Every customer with what they owe — open one for its ledger.',
            'reads' => 'Posted receivables per customer'],
        ['family' => 'customers', 'title' => 'Customer ledger', 'route' => 'customers.ledger', 'via' => 'customers.index',
            'answers' => 'One customer’s account, movement by movement, with a running balance.',
            'reads' => 'Invoices, payments and returns against that party'],
        ['family' => 'customers', 'title' => 'Customer statement', 'route' => 'customers.statement', 'via' => 'customers.index',
            'answers' => 'The same account as a printable statement over a window.',
            'reads' => 'That customer’s documents'],
        ['family' => 'customers', 'title' => 'Open invoices', 'route' => 'customers.open-invoices',
            'answers' => 'Who has unpaid invoices, oldest first.',
            'reads' => 'Invoices with an amount still due'],
        ['family' => 'customers', 'title' => 'Due balances', 'route' => 'customers.due',
            'answers' => 'What is owed right now, and how much of it is overdue.',
            'reads' => 'Receivables and their due dates'],
        ['family' => 'customers', 'title' => 'Customer groups', 'route' => 'customers.groups',
            'answers' => 'How the book is segmented, and what each segment is worth.',
            'reads' => 'Customer groups and their members’ balances'],
        ['family' => 'customers', 'title' => 'Blacklist', 'route' => 'customers.blacklist',
            'answers' => 'Who has been stopped, by whom and why.',
            'reads' => 'The blacklist with its reasons'],

        // --------------------------------------------------------- suppliers
        ['family' => 'suppliers', 'title' => 'Supplier ageing', 'route' => 'suppliers.ledger.index',
            'answers' => 'What is owed to each supplier, spread by how long it has been owed.',
            'reads' => 'Posted bills and their payments'],
        ['family' => 'suppliers', 'title' => 'Supplier register', 'route' => 'suppliers.index',
            'answers' => 'Every supplier with its balance — open one for its ledger.',
            'reads' => 'Payables per supplier'],
        ['family' => 'suppliers', 'title' => 'Supplier ledger', 'route' => 'suppliers.ledger', 'via' => 'suppliers.index',
            'answers' => 'One supplier’s account, movement by movement.',
            'reads' => 'Bills, payments and returns against that supplier'],
        ['family' => 'suppliers', 'title' => 'Supplier statement', 'route' => 'suppliers.statement', 'via' => 'suppliers.index',
            'answers' => 'The same account as a printable statement to send.',
            'reads' => 'That supplier’s documents'],

        // ----------------------------------------------------------- finance
        ['family' => 'finance', 'title' => 'Trial balance', 'route' => 'accounting.reports.trial-balance',
            'answers' => 'Whether the books balance, account by account, as at a date.',
            'reads' => 'Posted journal lines'],
        ['family' => 'finance', 'title' => 'Opening trial balance', 'route' => 'accounting.reports.opening-trial-balance',
            'answers' => 'What the books opened with, before anything was posted.',
            'reads' => 'Opening entries'],
        ['family' => 'finance', 'title' => 'Chart of accounts', 'route' => 'accounting.coa',
            'answers' => 'The accounts themselves with their balances — the frame every other report is drawn on.',
            'reads' => 'The chart and its posted balances'],
        ['family' => 'finance', 'title' => 'General ledger', 'route' => 'accounting.ledger', 'via' => 'accounting.coa',
            'answers' => 'Every posting on one account, with a running balance.',
            'reads' => 'Posted journal lines'],
        ['family' => 'finance', 'title' => 'Cash book', 'route' => 'cash.reports.book',
            'answers' => 'One money account read the way a bank statement reads.',
            'reads' => 'Posted journal lines of that account'],
        ['family' => 'finance', 'title' => 'Bank book', 'route' => 'cash.reports.bank-book',
            'answers' => 'Every bank and wallet side by side.',
            'reads' => 'Posted journal lines of every money account'],
        ['family' => 'finance', 'title' => 'Cash flow', 'route' => 'cash.reports.flow',
            'answers' => 'Where the money came from and where it went.',
            'reads' => 'The counterpart of every posting on a money account'],
        ['family' => 'finance', 'title' => 'Till variances', 'route' => 'cash.reports.sessions',
            'answers' => 'What each counter session counted against what it should have held.',
            'reads' => 'Counter sessions — the screen says it is not the ledger'],
        ['family' => 'finance', 'title' => 'Expense report', 'route' => 'expenses.reports.index',
            'answers' => 'What the company spent, by category, branch and month, with the ledger check beside it.',
            'reads' => 'Posted expenses and the accounts their categories point at'],
        ['family' => 'finance', 'title' => 'Expense register', 'route' => 'cash-bank.expenses',
            'answers' => 'Every expense with its state and its entry.',
            'reads' => 'The expense register'],
        ['family' => 'finance', 'title' => 'Cheque register', 'route' => 'cash-bank.cheques',
            'answers' => 'Cheques received and issued, and which ones a bank has paid.',
            'reads' => 'The cheque register'],
        ['family' => 'finance', 'title' => 'Trial balance rebuild', 'route' => 'accounting.reports.rebuild',
            'answers' => 'Rebuild the derived balances and see what moved — the one page here that writes.',
            'reads' => 'The ledger, re-derived from its postings'],

        // --------------------------------------------------------------- tax
        ['family' => 'tax', 'title' => 'Tax invoices (Mushak 9.1)', 'route' => 'sales.invoices.index',
            'answers' => 'The statutory tax invoice, printed per invoice from the sales register.',
            'reads' => 'One posted invoice, its lines and its tax'],

        // ---------------------------------------------------------------- hr
        ['family' => 'hr', 'title' => 'Attendance register', 'route' => 'hr.attendance',
            'answers' => 'Who was in, who was late and who was away, day by day.',
            'reads' => 'Attendance records'],
        ['family' => 'hr', 'title' => 'Attendance summary', 'route' => 'hr.attendance.summary',
            'answers' => 'The same month rolled up per employee — present, late, leave, absent.',
            'reads' => 'Attendance records and the working-day calendar'],
        ['family' => 'hr', 'title' => 'Attendance report', 'route' => 'hr.attendance.report', 'via' => 'hr.attendance.summary',
            'answers' => 'One employee over a period, in full.',
            'reads' => 'That employee’s attendance'],
        ['family' => 'hr', 'title' => 'Leave register', 'route' => 'hr.leave',
            'answers' => 'What leave was asked for, and what was granted.',
            'reads' => 'Leave requests and their decisions'],
        ['family' => 'hr', 'title' => 'Leave calendar', 'route' => 'hr.leave.calendar',
            'answers' => 'Who is away when — the view a supervisor plans around.',
            'reads' => 'Approved leave'],
        ['family' => 'hr', 'title' => 'Service book', 'route' => 'employees.index', 'via' => 'employees.index',
            'answers' => 'One employee’s service record in full.',
            'reads' => 'The employee’s own record'],
    ];

    /**
     * The reports of one family that this installation can actually open, in
     * listing order. Everything else — a route that is not registered, a family
     * that does not exist — is absent, and {@see dangling()} is what proves it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function entries(?string $family = null): array
    {
        $entries = [];

        foreach (self::REPORTS as $entry) {
            if ($family !== null && $entry['family'] !== $family) {
                continue;
            }

            $route = Route::has($entry['route']) ? Route::getRoutes()->getByName($entry['route']) : null;

            if (! $route instanceof RoutingRoute) {
                continue;
            }

            // A report that lives on a detail page (one account, one customer,
            // one employee) cannot be linked from a hub: the hub lists registers.
            // It is listed with the register it is opened from instead, and that
            // register is the link.
            $needsParameter = str_contains($route->uri(), '{');
            $target = $needsParameter ? ($entry['via'] ?? null) : $entry['route'];

            if ($target !== null && ! Route::has($target)) {
                $target = null;
            }

            $entries[] = $entry + [
                'url' => $target === null ? null : route($target, $entry['params'] ?? []),
                'permission' => $this->permissionOf($entry['route']),
                'opens_register' => $needsParameter,
                'register' => $needsParameter ? ($entry['via'] ?? null) : null,
            ];
        }

        return $entries;
    }

    /**
     * What a reader sees: every entry of the family, each marked with whether
     * they may open it. A locked row names the key it needs, so a manager can
     * ask for it rather than guess why a report is missing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forUser(User $user, string $family): array
    {
        return array_map(function (array $entry) use ($user): array {
            $entry['allowed'] = $entry['url'] !== null && $this->holds($user, $entry['permission']);

            return $entry;
        }, $this->entries($family));
    }

    /**
     * The catalogue's families, each with how many of its reports this
     * installation can open and whether the reader holds the hub's own key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function families(User $user): array
    {
        $families = [];

        foreach (self::FAMILIES as $slug => $family) {
            $mine = $this->forUser($user, $slug);

            $families[$slug] = $family + [
                'slug' => $slug,
                'route' => 'reports.'.$slug,
                'built' => count($mine),
                'openable' => count(array_filter($mine, fn (array $row): bool => $row['allowed'])),
                'allowed' => $user->isSuperAdmin() || $user->can($family['permission']),
            ];
        }

        return $families;
    }

    /** One family's definition, or null when the slug is not a family at all. */
    public function family(string $slug): ?array
    {
        return self::FAMILIES[$slug] ?? null;
    }

    /** Reachable reports across the centre — the figure the docs quote. */
    public function total(): int
    {
        return count($this->entries());
    }

    /**
     * Entries naming a route this installation does not register. Empty is the
     * healthy answer — a hub that links to a 404 is worse than one that links to
     * nothing — and the test asserts exactly that.
     *
     * @return array<int, string>
     */
    public function dangling(): array
    {
        return array_values(array_map(
            fn (array $entry): string => $entry['family'].' → '.$entry['title'].' ('.$entry['route'].')',
            array_filter(self::REPORTS, fn (array $entry): bool => ! Route::has($entry['route'])),
        ));
    }

    /**
     * The permission key a report's own route enforces, read off its middleware
     * rather than typed again here — a hub cannot drift from the door it shows.
     * Null when the route has no such middleware, which is itself worth seeing.
     */
    public function permissionOf(string $routeName): ?string
    {
        $route = Route::has($routeName) ? Route::getRoutes()->getByName($routeName) : null;

        if (! $route instanceof RoutingRoute) {
            return null;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                return substr($middleware, strlen('permission:'));
            }
        }

        return null;
    }

    /**
     * Whether the reader holds every key a route asks for. A route with no
     * permission on it fails closed rather than open: an unguarded report is a
     * bug, and a hub must not be the thing that advertises it.
     */
    public function holds(User $user, ?string $keys): bool
    {
        $keys = array_values(array_filter(explode(',', (string) $keys)));

        if ($keys === []) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        foreach ($keys as $key) {
            if (! $user->can($key)) {
                return false;
            }
        }

        return true;
    }
}
