<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Company;
use App\Domain\Masters\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 02-41 layaway balance schedule: order total minus the deposit taken
 * at the counter, split into dated installments. Settlement (collecting
 * installments / delivering goods) is not yet wired — the deposit
 * invoice and this schedule are the honest record of what is owed.
 */
class LayawaySchedule extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'layaway_schedules';

    protected $fillable = [
        'company_id', 'branch_id', 'sales_order_id', 'deposit_invoice_id',
        'customer_id', 'warehouse_id', 'pos_session_id',
        'order_total', 'deposit_amount', 'balance_amount',
        'installment_count', 'interval_days', 'first_due_on',
        'status', 'created_by',
    ];

    protected $casts = [
        'order_total' => 'decimal:4',
        'deposit_amount' => 'decimal:4',
        'balance_amount' => 'decimal:4',
        'installment_count' => 'integer',
        'interval_days' => 'integer',
        'first_due_on' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function depositInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'deposit_invoice_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LayawayInstallment::class, 'layaway_schedule_id');
    }
}
