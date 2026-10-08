<?php

namespace App\Domain\Sales\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Product;
use App\Domain\Sales\DeliveryChallan;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\ProductWarranty;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Warranty;
use App\Domain\Sales\WarrantyClaim;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §16-16/§16-17/§16-18 — the warranty engine.
 *
 * Four rules, in this order, because each one is meaningless without the one
 * before it:
 *
 *  1. **a promise exists before it can be applied.** Cover comes from the
 *     product's own policy row. No policy, no warranty — the desk says so and
 *     activation writes nothing, because a warranty the company never offered is
 *     a liability the software invented.
 *  2. **the promise attaches to a delivery, once.** Activation happens at the
 *     delivery event and carries the line it came from; the unique key on
 *     `source_line_key` means a second delivery event cannot double-cover the
 *     same goods, and re-running the delivery is a no-op rather than a second
 *     year of cover.
 *  3. **the dates are fixed at that moment** and never recomputed: the start is
 *     the day the goods reached the customer, the end is that day plus the
 *     policy's months, clamped to the end of the month (a 31 January cover of
 *     one month ends on 28 February, not on 3 March).
 *  4. **a claim never edits the cover.** It records what was reported, and the
 *     decision closes it with a resolution and, where money moved, a cost.
 */
class WarrantyService
{
    public const CODE_PREFIX = 'WR-';

    public const CLAIM_PREFIX = 'WC-';

    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    public function companyId(): int
    {
        return (int) ($this->context->companyId() ?? abort(500, 'No company context for the warranty desk.'));
    }

    /* --------------------------------------------------------- the policy */

    /** The policy for a product, live or not — the desk shows both. */
    public function policyFor(int $productId): ?ProductWarranty
    {
        return ProductWarranty::query()
            ->where('company_id', $this->companyId())
            ->where('product_id', $productId)
            ->first();
    }

    /**
     * Write the promise down. One policy per product: setting it twice replaces
     * it rather than stacking two different promises on one product.
     *
     * @param  array<string, mixed>  $data
     */
    public function savePolicy(Product $product, array $data, User $actor): ProductWarranty
    {
        $months = (int) ($data['months'] ?? 0);

        if ($months < 1 || $months > ProductWarranty::MAX_MONTHS) {
            throw ValidationException::withMessages([
                'months' => 'A warranty runs for a whole number of months between 1 and '.ProductWarranty::MAX_MONTHS.'.',
            ]);
        }

        $kind = (string) ($data['kind'] ?? 'manufacturer');

        if (! array_key_exists($kind, ProductWarranty::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'That is not a kind of warranty this build offers.']);
        }

        return DB::transaction(function () use ($product, $data, $actor, $months, $kind) {
            $policy = ProductWarranty::query()->firstOrNew([
                'company_id' => $this->companyId(),
                'product_id' => $product->id,
            ]);

            $before = $policy->exists ? $policy->only(['months', 'kind', 'is_active']) : null;

            $policy->fill([
                'months' => $months,
                'kind' => $kind,
                'covers_parts' => (bool) ($data['covers_parts'] ?? false),
                'covers_labour' => (bool) ($data['covers_labour'] ?? false),
                'terms' => $this->blankToNull($data['terms'] ?? null),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record([
                'action' => $policy->wasRecentlyCreated ? 'sales.warranty_policy_created' : 'sales.warranty_policy_updated',
                'entity_type' => 'product',
                'entity_id' => $product->id,
                'actor_id' => $actor->id,
                'before' => $before,
                'after' => [
                    'product' => $product->code,
                    'months' => $months,
                    'kind' => $kind,
                    'covers_parts' => (bool) $policy->covers_parts,
                    'covers_labour' => (bool) $policy->covers_labour,
                    'is_active' => (bool) $policy->is_active,
                ],
            ]);

            return $policy;
        });
    }

    /* ------------------------------------------------------ the activation */

    /**
     * §16-16 — the goods reached the customer.
     *
     * Called from the delivery event itself, inside its transaction, so a
     * warranty cannot exist for goods that were never delivered and a delivery
     * cannot be remembered without the cover it created. Returns the warranties
     * this call actually created — an empty list means nothing was promised for
     * these goods, which is a normal answer and not an error.
     *
     * @return array<int, Warranty>
     */
    public function activateForChallan(DeliveryChallan $challan, ?User $actor = null): array
    {
        $challan->loadMissing(['lines.product', 'order.customer']);

        $startsOn = Carbon::parse($challan->delivered_at ?? now())->startOfDay();
        $order = $challan->order;
        $customerId = $order?->customer_id;

        $created = [];

        foreach ($challan->lines as $line) {
            $product = Product::query()->find($line->product_id);

            if ($product === null) {
                continue;
            }

            $warranty = $this->apply(
                product: $product,
                customerId: $customerId,
                branchId: $challan->branch_id,
                salesOrderId: $challan->sales_order_id,
                invoiceId: null,
                challanId: $challan->id,
                source: 'challan',
                sourceLineId: (int) $line->id,
                sourceLineKey: 'challan_line:'.$line->id,
                serialNo: null,
                qty: (float) $line->qty,
                startsOn: $startsOn,
                actor: $actor,
            );

            if ($warranty !== null) {
                $created[] = $warranty;
            }
        }

        return $created;
    }

    /**
     * The other half of the same rule: when an invoice is issued for goods that
     * no delivered challan carried, the invoice *is* the delivery event.
     *
     * It refuses to double-cover: a line whose product already has a warranty
     * for the same order is skipped, because the customer was already promised
     * cover for those goods and a second promise is not a second year of cover,
     * it is a data-entry mistake that will be discovered by a claim.
     *
     * @return array<int, Warranty>
     */
    public function activateForInvoice(Invoice $invoice, ?User $actor = null): array
    {
        $invoice->loadMissing(['lines.product', 'customer']);

        $startsOn = Carbon::parse($invoice->invoice_date ?? now())->startOfDay();

        $created = [];

        foreach ($invoice->lines as $line) {
            $product = Product::query()->find($line->product_id);

            if ($product === null) {
                continue;
            }

            if ($this->alreadyCovered($product->id, $invoice->sales_order_id, $invoice->customer_id)) {
                continue;
            }

            $warranty = $this->apply(
                product: $product,
                customerId: $invoice->customer_id,
                branchId: $invoice->branch_id,
                salesOrderId: $invoice->sales_order_id,
                invoiceId: $invoice->id,
                challanId: null,
                source: 'invoice',
                sourceLineId: (int) $line->id,
                sourceLineKey: 'invoice_line:'.$line->id,
                serialNo: null,
                qty: (float) $line->qty,
                startsOn: $startsOn,
                actor: $actor,
            );

            if ($warranty !== null) {
                $created[] = $warranty;
            }
        }

        return $created;
    }

    /**
     * Register cover by hand — for goods delivered before this build had a
     * warranty desk, or for a unit whose serial is being recorded at the counter.
     *
     * @param  array<string, mixed>  $data
     */
    public function activateManually(Product $product, array $data, User $actor): Warranty
    {
        $startsOn = isset($data['starts_on']) && $data['starts_on'] !== ''
            ? Carbon::parse($data['starts_on'])->startOfDay()
            : Carbon::now()->startOfDay();

        $warranty = $this->apply(
            product: $product,
            customerId: isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            branchId: isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            salesOrderId: null,
            invoiceId: null,
            challanId: null,
            source: 'manual',
            sourceLineId: 0,
            sourceLineKey: 'manual:'.$product->id.':'.($data['serial_no'] ?? 'n-a').':'.$startsOn->format('YmdHis'),
            serialNo: $this->blankToNull($data['serial_no'] ?? null),
            qty: (float) ($data['qty'] ?? 1),
            startsOn: $startsOn,
            actor: $actor,
            monthsOverride: isset($data['months']) ? (int) $data['months'] : null,
            notes: $this->blankToNull($data['notes'] ?? null),
        );

        if ($warranty === null) {
            throw ValidationException::withMessages([
                'product_id' => "{$product->name} has no warranty policy, so there is nothing to register. Set one on the product first, or state the months here.",
            ]);
        }

        return $warranty;
    }

    /**
     * The one place cover is written. Returns null when the product carries no
     * policy — the caller decides whether that is an answer or a refusal.
     *
     * @return ?Warranty
     */
    protected function apply(
        Product $product,
        ?int $customerId,
        ?int $branchId,
        ?int $salesOrderId,
        ?int $invoiceId,
        ?int $challanId,
        string $source,
        int $sourceLineId,
        string $sourceLineKey,
        ?string $serialNo,
        float $qty,
        Carbon $startsOn,
        ?User $actor,
        ?int $monthsOverride = null,
        ?string $notes = null,
    ): ?Warranty {
        $companyId = $this->companyId();

        return DB::transaction(function () use (
            $companyId, $product, $customerId, $branchId, $salesOrderId, $invoiceId, $challanId,
            $source, $sourceLineId, $sourceLineKey, $serialNo, $qty, $startsOn, $actor,
            $monthsOverride, $notes
        ) {
            $policy = $this->policyFor($product->id);

            $months = $monthsOverride ?? ($policy?->is_active === true ? $policy->months : null);

            if ($months === null || $months < 1) {
                return null;
            }

            // Exactly once: whatever happens upstream, one delivered line has
            // one warranty, and this is where that is enforced.
            $existing = Warranty::query()
                ->where('company_id', $companyId)
                ->where('source_line_key', $sourceLineKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $endsOn = $this->addMonths($startsOn, $months);

            $warranty = Warranty::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'code' => $this->nextCode(Warranty::class, 'code', self::CODE_PREFIX),
                'customer_id' => $customerId,
                'product_id' => $product->id,
                'sales_order_id' => $salesOrderId,
                'invoice_id' => $invoiceId,
                'challan_id' => $challanId,
                'source' => $source,
                'source_line_id' => $sourceLineId,
                'source_line_key' => $sourceLineKey,
                'serial_no' => $serialNo,
                'qty' => $qty,
                'months' => $months,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'status' => Warranty::STATUS_ACTIVE,
                'activated_at' => now(),
                'activated_by' => $actor?->id,
                'notes' => $notes,
            ]);

            $this->audit->record([
                'action' => 'sales.warranty_activated',
                'entity_type' => 'warranty',
                'entity_id' => $warranty->id,
                'actor_id' => $actor?->id,
                'branch_id' => $branchId,
                'after' => [
                    'code' => $warranty->code,
                    'product' => $product->code,
                    'months' => $months,
                    'starts_on' => $warranty->starts_on?->toDateString(),
                    'ends_on' => $warranty->ends_on?->toDateString(),
                    'source' => $source,
                    'source_line_id' => $sourceLineId,
                ],
            ]);

            return $warranty;
        });
    }

    /**
     * The end of the cover: the start plus N months, clamped.
     *
     * A one-month cover that begins on 31 January ends on 28 February. Carbon's
     * plain `addMonth()` would roll that over into March — a longer promise than
     * the company made, and the kind of detail a customer notices first.
     */
    public function addMonths(Carbon $start, int $months): Carbon
    {
        $day = $start->day;
        $end = $start->copy()->startOfDay()->addMonthsNoOverflow($months);

        // Carbon 3 has no setDay(); the date is set explicitly, with the day
        // clamped to the target month's length.
        $end->setDate($end->year, $end->month, min($day, $end->daysInMonth));

        return $end->startOfDay();
    }

    /* ------------------------------------------------------------ the claims */

    /**
     * §16-18 — a fault is reported.
     *
     * Refused when the cover has run out or was voided: the customer may have a
     * complaint, but it is not a warranty claim, and writing it down as one would
     * make the register a record of what people asked for rather than what the
     * company owes.
     *
     * @param  array<string, mixed>  $data
     */
    public function raiseClaim(Warranty $warranty, array $data, User $actor): WarrantyClaim
    {
        $warranty->refresh();

        if ($warranty->stateNow() === Warranty::STATUS_VOIDED) {
            throw ValidationException::withMessages([
                'fault' => "Warranty {$warranty->code} was voided".($warranty->void_reason !== null ? ' — '.$warranty->void_reason : '').', so there is nothing to claim against.',
            ]);
        }

        if ($warranty->daysRemaining() < 0) {
            throw ValidationException::withMessages([
                'fault' => "The cover on {$warranty->code} ended on {$warranty->ends_on?->format('d M Y')} "
                    .abs($warranty->daysRemaining()).' day(s) ago. A goodwill repair is an expense, not a warranty claim.',
            ]);
        }

        $fault = trim((string) ($data['fault'] ?? ''));

        if ($fault === '') {
            throw ValidationException::withMessages(['fault' => 'What was reported? A claim with no fault cannot be looked at.']);
        }

        $reportedOn = isset($data['reported_on']) && $data['reported_on'] !== ''
            ? Carbon::parse($data['reported_on'])->startOfDay()
            : Carbon::now()->startOfDay();

        return DB::transaction(function () use ($warranty, $fault, $reportedOn, $actor) {
            $claim = WarrantyClaim::query()->create([
                'company_id' => $warranty->company_id,
                'branch_id' => $warranty->branch_id,
                'code' => $this->nextCode(WarrantyClaim::class, 'code', self::CLAIM_PREFIX),
                'warranty_id' => $warranty->id,
                'reported_on' => $reportedOn->toDateString(),
                'fault' => $fault,
                'status' => 'open',
                'created_by' => $actor->id,
            ]);

            $this->audit->record([
                'action' => 'sales.warranty_claim_raised',
                'entity_type' => 'warranty_claim',
                'entity_id' => $claim->id,
                'actor_id' => $actor->id,
                'branch_id' => $warranty->branch_id,
                'after' => [
                    'code' => $claim->code,
                    'warranty' => $warranty->code,
                    'product' => $warranty->product?->code,
                    'fault' => $fault,
                    'days_remaining' => $warranty->daysRemaining(),
                ],
            ]);

            return $claim;
        });
    }

    /**
     * §16-18 — the decision on a claim.
     *
     * `completed` is the only state that closes it, and it must carry a
     * resolution and a date; a refusal carries the reason. Both are recorded
     * with the person who decided them, because "who said this was covered" is
     * the first question anybody asks about a warranty.
     *
     * @param  array<string, mixed>  $data
     */
    public function decideClaim(WarrantyClaim $claim, array $data, User $actor): WarrantyClaim
    {
        $claim->refresh();

        if (! $claim->isOpen() && ($data['status'] ?? null) !== $claim->status) {
            throw ValidationException::withMessages([
                'status' => "Claim {$claim->code} was already closed as {$claim->statusLabel()}. A closed claim is a record, not a draft.",
            ]);
        }

        $status = (string) ($data['status'] ?? 'processing');

        if (! array_key_exists($status, WarrantyClaim::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'That is not a state a claim can be in.']);
        }

        $resolution = $this->blankToNull($data['resolution'] ?? null);

        if ($resolution !== null && ! array_key_exists($resolution, WarrantyClaim::RESOLUTIONS)) {
            throw ValidationException::withMessages(['resolution' => 'That is not a resolution this build offers.']);
        }

        if ($status === 'completed' && $resolution === null) {
            throw ValidationException::withMessages([
                'resolution' => 'Closing a claim means saying what was done — repaired, replaced, refunded, or not covered.',
            ]);
        }

        if ($status === 'rejected' && $resolution === null) {
            $resolution = 'reject';
        }

        $closing = in_array($status, ['completed', 'rejected'], true);

        return DB::transaction(function () use ($claim, $status, $resolution, $closing, $data, $actor) {
            $before = $claim->only(['status', 'resolution', 'cost']);

            $claim->fill([
                'status' => $status,
                'resolution' => $resolution,
                'resolution_notes' => $this->blankToNull($data['resolution_notes'] ?? null),
                'cost' => (float) ($data['cost'] ?? $claim->cost ?? 0),
                'resolved_on' => $closing ? now()->toDateString() : null,
                'resolved_by' => $closing ? $actor->id : null,
            ])->save();

            // The cover records that something was claimed against it — but only
            // when the claim was actually honoured. A refusal leaves the
            // warranty exactly as it was.
            $honoured = $status === 'completed' && in_array($resolution, ['repair', 'replace', 'refund'], true);

            if ($honoured && $claim->warranty !== null) {
                $warranty = $claim->warranty;

                if (in_array($warranty->stateNow(), [Warranty::STATUS_ACTIVE], true)) {
                    $warranty->forceFill(['status' => Warranty::STATUS_CLAIMED])->save();
                }
            }

            $this->audit->record([
                'action' => $closing ? 'sales.warranty_claim_closed' : 'sales.warranty_claim_'.$status,
                'entity_type' => 'warranty_claim',
                'entity_id' => $claim->id,
                'actor_id' => $actor->id,
                'branch_id' => $claim->branch_id,
                'amount' => (float) $claim->cost,
                'before' => $before,
                'after' => [
                    'code' => $claim->code,
                    'status' => $status,
                    'resolution' => $resolution,
                    'cost' => (float) $claim->cost,
                ],
            ]);

            return $claim;
        });
    }

    /** Voiding is for a mistake — goods returned for credit, a mis-issued warranty. */
    public function void(Warranty $warranty, string $reason, User $actor): Warranty
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['void_reason' => 'Say why the cover is being withdrawn — the row keeps it.']);
        }

        if ($warranty->stateNow() === Warranty::STATUS_VOIDED) {
            throw ValidationException::withMessages(['void_reason' => "Warranty {$warranty->code} is already voided."]);
        }

        $warranty->forceFill([
            'status' => Warranty::STATUS_VOIDED,
            'voided_at' => now(),
            'void_reason' => $reason,
        ])->save();

        $this->audit->record([
            'action' => 'sales.warranty_voided',
            'entity_type' => 'warranty',
            'entity_id' => $warranty->id,
            'actor_id' => $actor->id,
            'branch_id' => $warranty->branch_id,
            'reason' => $reason,
            'after' => ['code' => $warranty->code, 'status' => Warranty::STATUS_VOIDED],
        ]);

        return $warranty;
    }

    /* ------------------------------------------------------------- the desk */

    /**
     * The numbers the desk opens with.
     *
     * @return array{live:int,expiring:int,expired:int,claimed:int,voided:int,working_claims:int,completed_claims:int,rejected_claims:int,covered_value_qty:float,open_claim_cost:float}
     */
    public function summary(): array
    {
        $companyId = $this->companyId();

        $base = fn () => Warranty::query()->where('company_id', $companyId);

        $claims = fn () => WarrantyClaim::query()->where('company_id', $companyId);

        return [
            'live' => $base()->live()->where('status', '!=', Warranty::STATUS_VOIDED)->count(),
            'expiring' => $base()->expiringWithin(Warranty::EXPIRING_DAYS)->count(),
            'expired' => $base()->expired()->where('status', '!=', Warranty::STATUS_VOIDED)->count(),
            'claimed' => $base()->where('status', Warranty::STATUS_CLAIMED)->count(),
            'voided' => $base()->where('status', Warranty::STATUS_VOIDED)->count(),
            'working_claims' => $claims()->working()->count(),
            'completed_claims' => $claims()->completed()->count(),
            'rejected_claims' => $claims()->where('status', 'rejected')->count(),
            'covered_qty' => (float) $base()->live()->sum('qty'),
            'open_claim_cost' => (float) $claims()->whereIn('status', WarrantyClaim::OPEN_STATES)->sum('cost'),
        ];
    }

    /** Warranties whose cover ends within the desk's warning window, soonest first. */
    public function expiringSoon(int $limit = 8)
    {
        return Warranty::query()
            ->where('company_id', $this->companyId())
            ->expiringWithin(Warranty::EXPIRING_DAYS)
            ->with(['product', 'customer'])
            ->orderBy('ends_on')
            ->limit($limit)
            ->get();
    }

    /* ------------------------------------------------------------- plumbing */

    /** Has this product already been promised to this customer for this order? */
    protected function alreadyCovered(int $productId, ?int $salesOrderId, ?int $customerId): bool
    {
        return Warranty::query()
            ->where('company_id', $this->companyId())
            ->where('product_id', $productId)
            ->when($salesOrderId !== null, fn ($query) => $query->where('sales_order_id', $salesOrderId))
            ->when($salesOrderId === null && $customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
            ->exists();
    }

    /** WR-000001 / WC-000001, per company, never reused. */
    protected function nextCode(string $model, string $column, string $prefix): string
    {
        $companyId = $this->companyId();

        $last = $model::query()
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->value($column);

        $number = 1;

        if (is_string($last) && preg_match('/(\d+)$/', $last, $matches) === 1) {
            $number = (int) $matches[1] + 1;
        }

        do {
            $code = $prefix.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            $number++;
        } while ($model::query()->where('company_id', $companyId)->where($column, $code)->exists());

        return $code;
    }

    /**
     * Every warranty for one order — what the register shows when somebody asks
     * "what did we promise on this order".
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Warranty>
     */
    public function forOrder(SalesOrder $order)
    {
        return Warranty::query()
            ->where('company_id', $this->companyId())
            ->where('sales_order_id', $order->id)
            ->with(['product', 'claims'])
            ->orderBy('id')
            ->get();
    }

    protected function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
