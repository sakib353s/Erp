<?php

namespace App\Domain\Reporting;

use App\Domain\Foundation\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DB-driven custom report builder (02-120).
 *
 * Security model: sources, columns and filters are a fixed whitelist —
 * user input only ever selects whitelist keys, never SQL. Tenant company
 * scope and the running user's branch scope are applied on EVERY query
 * inside run(), so neither stored definitions nor saved filters can widen
 * what a user may read (scope enforced at run time, Rule 5/D6).
 */
class CustomReportBuilder
{
    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 500;

    /** Snapshot rows kept on a report_runs record. */
    public const SNAPSHOT_ROWS = 200;

    /**
     * source => schema. `filters` types: date_from|date_to|equal|equal_int.
     * `branch_sql` is the column the running user's branch scope applies to.
     */
    public const SOURCES = [
        'invoices' => [
            'label' => 'Invoices',
            'base' => 'invoices',
            'joins' => [],
            'company_sql' => 'invoices.company_id',
            'branch_sql' => 'invoices.branch_id',
            'date_sql' => 'invoices.invoice_date',
            'order_sql' => 'invoices.invoice_date',
            'columns' => [
                'invoice_no' => ['label' => 'Invoice no', 'sql' => 'invoices.invoice_no'],
                'invoice_date' => ['label' => 'Invoice date', 'sql' => 'invoices.invoice_date'],
                'due_date' => ['label' => 'Due date', 'sql' => 'invoices.due_date'],
                'status' => ['label' => 'Status', 'sql' => 'invoices.status'],
                'invoice_type' => ['label' => 'Type', 'sql' => 'invoices.invoice_type'],
                'customer_id' => ['label' => 'Customer id', 'sql' => 'invoices.customer_id'],
                'branch_id' => ['label' => 'Branch id', 'sql' => 'invoices.branch_id'],
                'warehouse_id' => ['label' => 'Warehouse id', 'sql' => 'invoices.warehouse_id'],
                'subtotal' => ['label' => 'Subtotal', 'sql' => 'invoices.subtotal'],
                'grand_total' => ['label' => 'Grand total', 'sql' => 'invoices.grand_total'],
                'paid_amount' => ['label' => 'Paid', 'sql' => 'invoices.paid_amount'],
                'due_amount' => ['label' => 'Due', 'sql' => 'invoices.due_amount'],
            ],
            'filters' => [
                'date_from' => ['label' => 'From', 'type' => 'date_from', 'sql' => 'invoices.invoice_date'],
                'date_to' => ['label' => 'To', 'type' => 'date_to', 'sql' => 'invoices.invoice_date'],
                'status' => ['label' => 'Status', 'type' => 'equal', 'sql' => 'invoices.status'],
                'invoice_type' => ['label' => 'Type', 'type' => 'equal', 'sql' => 'invoices.invoice_type'],
                'branch_id' => ['label' => 'Branch id', 'type' => 'equal_int', 'sql' => 'invoices.branch_id'],
                'customer_id' => ['label' => 'Customer id', 'type' => 'equal_int', 'sql' => 'invoices.customer_id'],
            ],
        ],
        'invoice_lines' => [
            'label' => 'Invoice lines',
            'base' => 'invoice_lines',
            'joins' => [
                ['table' => 'invoices', 'first' => 'invoice_lines.invoice_id', 'second' => 'invoices.id'],
            ],
            'company_sql' => 'invoice_lines.company_id',
            'branch_sql' => 'invoices.branch_id',
            'date_sql' => 'invoices.invoice_date',
            'order_sql' => 'invoices.invoice_date',
            'columns' => [
                'invoice_no' => ['label' => 'Invoice no', 'sql' => 'invoices.invoice_no'],
                'invoice_date' => ['label' => 'Invoice date', 'sql' => 'invoices.invoice_date'],
                'line_no' => ['label' => 'Line', 'sql' => 'invoice_lines.line_no'],
                'description' => ['label' => 'Description', 'sql' => 'invoice_lines.description'],
                'product_id' => ['label' => 'Product id', 'sql' => 'invoice_lines.product_id'],
                'qty' => ['label' => 'Qty', 'sql' => 'invoice_lines.qty'],
                'unit_price' => ['label' => 'Unit price', 'sql' => 'invoice_lines.unit_price'],
                'discount' => ['label' => 'Discount', 'sql' => 'invoice_lines.discount'],
                'tax' => ['label' => 'Tax', 'sql' => 'invoice_lines.tax'],
                'line_total' => ['label' => 'Line total', 'sql' => 'invoice_lines.line_total'],
            ],
            'filters' => [
                'date_from' => ['label' => 'From', 'type' => 'date_from', 'sql' => 'invoices.invoice_date'],
                'date_to' => ['label' => 'To', 'type' => 'date_to', 'sql' => 'invoices.invoice_date'],
                'status' => ['label' => 'Invoice status', 'type' => 'equal', 'sql' => 'invoices.status'],
                'branch_id' => ['label' => 'Branch id', 'type' => 'equal_int', 'sql' => 'invoices.branch_id'],
                'product_id' => ['label' => 'Product id', 'type' => 'equal_int', 'sql' => 'invoice_lines.product_id'],
            ],
        ],
        'sales_orders' => [
            'label' => 'Sales orders',
            'base' => 'sales_orders',
            'joins' => [],
            'company_sql' => 'sales_orders.company_id',
            'branch_sql' => 'sales_orders.branch_id',
            'date_sql' => 'sales_orders.order_date',
            'order_sql' => 'sales_orders.order_date',
            'columns' => [
                'order_no' => ['label' => 'Order no', 'sql' => 'sales_orders.order_no'],
                'order_date' => ['label' => 'Order date', 'sql' => 'sales_orders.order_date'],
                'status' => ['label' => 'Status', 'sql' => 'sales_orders.status'],
                'customer_id' => ['label' => 'Customer id', 'sql' => 'sales_orders.customer_id'],
                'branch_id' => ['label' => 'Branch id', 'sql' => 'sales_orders.branch_id'],
                'warehouse_id' => ['label' => 'Warehouse id', 'sql' => 'sales_orders.warehouse_id'],
                'subtotal' => ['label' => 'Subtotal', 'sql' => 'sales_orders.subtotal'],
                'discount' => ['label' => 'Discount', 'sql' => 'sales_orders.discount'],
                'tax' => ['label' => 'Tax', 'sql' => 'sales_orders.tax'],
                'grand_total' => ['label' => 'Grand total', 'sql' => 'sales_orders.grand_total'],
            ],
            'filters' => [
                'date_from' => ['label' => 'From', 'type' => 'date_from', 'sql' => 'sales_orders.order_date'],
                'date_to' => ['label' => 'To', 'type' => 'date_to', 'sql' => 'sales_orders.order_date'],
                'status' => ['label' => 'Status', 'type' => 'equal', 'sql' => 'sales_orders.status'],
                'branch_id' => ['label' => 'Branch id', 'type' => 'equal_int', 'sql' => 'sales_orders.branch_id'],
                'customer_id' => ['label' => 'Customer id', 'type' => 'equal_int', 'sql' => 'sales_orders.customer_id'],
            ],
        ],
        'quotations' => [
            'label' => 'Quotations',
            'base' => 'quotations',
            'joins' => [],
            'company_sql' => 'quotations.company_id',
            'branch_sql' => 'quotations.branch_id',
            'date_sql' => 'quotations.quote_date',
            'order_sql' => 'quotations.quote_date',
            'columns' => [
                'quote_no' => ['label' => 'Quote no', 'sql' => 'quotations.quote_no'],
                'quote_date' => ['label' => 'Quote date', 'sql' => 'quotations.quote_date'],
                'valid_until' => ['label' => 'Valid until', 'sql' => 'quotations.valid_until'],
                'status' => ['label' => 'Status', 'sql' => 'quotations.status'],
                'revision' => ['label' => 'Revision', 'sql' => 'quotations.revision'],
                'customer_id' => ['label' => 'Customer id', 'sql' => 'quotations.customer_id'],
                'branch_id' => ['label' => 'Branch id', 'sql' => 'quotations.branch_id'],
                'subtotal' => ['label' => 'Subtotal', 'sql' => 'quotations.subtotal'],
                'grand_total' => ['label' => 'Grand total', 'sql' => 'quotations.grand_total'],
            ],
            'filters' => [
                'date_from' => ['label' => 'From', 'type' => 'date_from', 'sql' => 'quotations.quote_date'],
                'date_to' => ['label' => 'To', 'type' => 'date_to', 'sql' => 'quotations.quote_date'],
                'status' => ['label' => 'Status', 'type' => 'equal', 'sql' => 'quotations.status'],
                'branch_id' => ['label' => 'Branch id', 'type' => 'equal_int', 'sql' => 'quotations.branch_id'],
                'customer_id' => ['label' => 'Customer id', 'type' => 'equal_int', 'sql' => 'quotations.customer_id'],
            ],
        ],
        'payments' => [
            'label' => 'Payments (receipts)',
            'base' => 'payments',
            'joins' => [],
            'company_sql' => 'payments.company_id',
            'branch_sql' => 'payments.branch_id',
            'date_sql' => 'payments.paid_at',
            'order_sql' => 'payments.paid_at',
            'columns' => [
                'receipt_no' => ['label' => 'Receipt no', 'sql' => 'payments.receipt_no'],
                'paid_at' => ['label' => 'Paid at', 'sql' => 'payments.paid_at'],
                'direction' => ['label' => 'Direction', 'sql' => 'payments.direction'],
                'method' => ['label' => 'Method', 'sql' => 'payments.method'],
                'amount' => ['label' => 'Amount', 'sql' => 'payments.amount'],
                'status' => ['label' => 'Status', 'sql' => 'payments.status'],
                'customer_id' => ['label' => 'Customer id', 'sql' => 'payments.customer_id'],
                'branch_id' => ['label' => 'Branch id', 'sql' => 'payments.branch_id'],
                'account_id' => ['label' => 'Account id', 'sql' => 'payments.account_id'],
                'reference' => ['label' => 'Reference', 'sql' => 'payments.reference'],
            ],
            'filters' => [
                'date_from' => ['label' => 'From', 'type' => 'date_from', 'sql' => 'payments.paid_at'],
                'date_to' => ['label' => 'To', 'type' => 'date_to', 'sql' => 'payments.paid_at'],
                'status' => ['label' => 'Status', 'type' => 'equal', 'sql' => 'payments.status'],
                'method' => ['label' => 'Method', 'type' => 'equal', 'sql' => 'payments.method'],
                'direction' => ['label' => 'Direction', 'type' => 'equal', 'sql' => 'payments.direction'],
                'branch_id' => ['label' => 'Branch id', 'type' => 'equal_int', 'sql' => 'payments.branch_id'],
                'customer_id' => ['label' => 'Customer id', 'type' => 'equal_int', 'sql' => 'payments.customer_id'],
            ],
        ],
    ];

    /** @return array<string, string> source => label */
    public function sources(): array
    {
        return array_map(fn (array $schema): string => $schema['label'], self::SOURCES);
    }

    public function schema(string $source): array
    {
        return self::SOURCES[$source]
            ?? throw ValidationException::withMessages(['source' => 'Unknown report source.']);
    }

    /**
     * Execute a report for THIS user. Company + branch scope applied here,
     * always — stored definitions and saved filters cannot bypass them.
     *
     * @param  array<int, string>  $columns  whitelist keys only
     * @param  array<string, mixed>  $filters
     * @return array{
     *   source: string,
     *   columns: list<array{key: string, label: string}>,
     *   rows: list<object>,
     *   row_count: int,
     *   returned: int,
     *   truncated: bool,
     *   limit: int,
     *   filters: array<string, mixed>,
     * }
     */
    public function run(
        ?User $user,
        string $source,
        array $columns,
        array $filters = [],
        ?int $limit = null,
    ): array {
        if ($user === null) {
            throw ValidationException::withMessages(['report' => 'Authentication required to run a report.']);
        }

        $schema = $this->schema($source);

        $requested = array_values(array_unique($columns));

        if ($requested === []) {
            throw ValidationException::withMessages(['columns' => 'Select at least one column.']);
        }

        foreach ($requested as $key) {
            if (! isset($schema['columns'][$key])) {
                throw ValidationException::withMessages(['columns' => "Unknown column: {$key}."]);
            }
        }

        $limit = min(self::MAX_LIMIT, max(1, (int) ($limit ?? self::DEFAULT_LIMIT)));
        $filters = $this->cleanFilters($schema, $filters);

        $query = $this->baseQuery($user, $schema, $filters);

        $total = (clone $query)->count();

        $selects = [];
        foreach ($requested as $key) {
            $selects[] = DB::raw($schema['columns'][$key]['sql'].' as "'.$key.'"');
        }

        $rows = $query
            ->select($selects)
            ->orderBy($schema['order_sql'], 'desc')
            ->orderBy($schema['base'].'.id', 'desc')
            ->limit($limit)
            ->get();

        return [
            'source' => $source,
            'columns' => array_map(
                fn (string $key): array => ['key' => $key, 'label' => $schema['columns'][$key]['label']],
                $requested,
            ),
            'rows' => $rows->all(),
            'row_count' => $total,
            'returned' => $rows->count(),
            'truncated' => $total > $rows->count(),
            'limit' => $limit,
            'filters' => $filters,
        ];
    }

    /** Drop anything not whitelisted for this source (never trusted). */
    public function cleanFilters(array $schema, array $filters): array
    {
        $clean = [];

        foreach ($schema['filters'] as $key => $filter) {
            $value = $filters[$key] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if ($filter['type'] === 'equal_int') {
                $value = (int) $value;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /** @return array<int, string> whitelist filter keys for a source */
    public function filterKeys(string $source): array
    {
        return array_keys($this->schema($source)['filters']);
    }

    /** @return array<int, string> whitelist column keys for a source */
    public function columnKeys(string $source): array
    {
        return array_keys($this->schema($source)['columns']);
    }

    protected function baseQuery(User $user, array $schema, array $filters): QueryBuilder
    {
        $query = DB::table($schema['base']);

        foreach ($schema['joins'] as $join) {
            $query->join($join['table'], $join['first'], '=', $join['second']);
        }

        $query->where($schema['company_sql'], $user->company_id);

        $branchIds = $user->accessibleBranchIds();

        if ($branchIds !== null) {
            $query->whereIn($schema['branch_sql'], $branchIds);
        }

        foreach ($schema['filters'] as $key => $filter) {
            if (! array_key_exists($key, $filters)) {
                continue;
            }

            $value = $filters[$key];

            match ($filter['type']) {
                'date_from' => $query->whereDate($filter['sql'], '>=', (string) $value),
                'date_to' => $query->whereDate($filter['sql'], '<=', (string) $value),
                'equal_int' => $query->where($filter['sql'], (int) $value),
                default => $query->where($filter['sql'], $value),
            };
        }

        return $query;
    }
}
