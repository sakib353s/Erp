<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\PaymentAllocation;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Masters\Customer;
use App\Domain\Masters\PaymentMethod;
use App\Domain\Masters\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'branch_id', 'customer_id', 'supplier_id', 'payment_method_id',
        'account_id', 'receipt_no', 'direction', 'method', 'amount',
        'status', 'paid_at', 'reference', 'narration', 'idempotency_key',
        'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'paid_at' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** The entry that moved this money — the cash book and the ledger are one story. */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
