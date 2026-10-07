<?php

namespace App\Domain\Returns;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Masters\Customer;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\PosSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 02-39 counter exchange document. Two legs on one header: returned
 * goods (lines direction=return, valued at the original invoice price)
 * and new goods (direction=issue, priced onto new_invoice_id). The
 * signed price_differential is what actually settles at the drawer.
 */
class Exchange extends Model
{
    use Auditable;

    protected $table = 'exchanges';

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'customer_id', 'invoice_id',
        'new_invoice_id', 'pos_session_id', 'exchange_no', 'status',
        'payment_method', 'return_total', 'exchange_total', 'price_differential',
        'idempotency_key', 'notes', 'created_by',
    ];

    protected $casts = [
        'return_total' => 'decimal:4',
        'exchange_total' => 'decimal:4',
        'price_differential' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function newInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'new_invoice_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExchangeLine::class);
    }
}
