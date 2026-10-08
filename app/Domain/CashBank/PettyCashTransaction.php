<?php

namespace App\Domain\CashBank;

use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §08-21 — one movement of float money, as the desk needs to read it.
 *
 * The money itself is elsewhere on purpose: a disbursement is a payment out of
 * the float account (Dr the category, Cr the float) and a replenishment is a
 * transfer into it (Dr the float, Cr the bank). This row records which fund, what
 * it was for and which request produced it — the three things the ledger cannot
 * say, and the reason a petty cash register is not just a filtered journal.
 */
class PettyCashTransaction extends Model
{
    public const KIND_DISBURSEMENT = 'disbursement';

    public const KIND_REPLENISHMENT = 'replenishment';

    public const KINDS = [
        self::KIND_DISBURSEMENT => 'Paid out of the float',
        self::KIND_REPLENISHMENT => 'Put back into the float',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'fund_id', 'kind', 'occurred_on', 'amount',
        'expense_category_id', 'payment_id', 'transfer_id', 'request_id',
        'payee', 'narration', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'occurred_on' => 'date',
        ];
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(PettyCashFund::class, 'fund_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** The payment voucher, when money left the float. */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /** The transfer that topped the float up, when money came in. */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(CashTransfer::class, 'transfer_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PettyCashRequest::class, 'request_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDisbursement(): bool
    {
        return $this->kind === self::KIND_DISBURSEMENT;
    }

    /**
     * The number a person would quote: the payment voucher for a disbursement,
     * the transfer number for a replenishment.
     */
    public function documentNo(): ?string
    {
        return $this->payment?->receipt_no ?? $this->transfer?->transfer_no;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? (string) $this->kind;
    }
}
