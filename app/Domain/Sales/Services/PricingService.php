<?php

namespace App\Domain\Sales\Services;

use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Masters\DeliveryZone;
use App\Domain\Masters\PriceList;
use App\Domain\Masters\PriceListItem;
use App\Domain\Masters\PricingRule;
use App\Domain\Masters\ZoneCharge;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Server-authoritative unit price resolution (02-29 / 02-30) with the
 * deterministic pricing-rules cascade (02-111):
 *
 *   base price list → customer_group → quantity_break → geographic
 *                    → time_based → special
 *
 * Architecture chain (base → group → qty → geographic → time-based →
 * promo/coupon → authorized manual override) with the per-customer
 * "special price" as the strongest unit-price stage. Every matching
 * stage cascades onto the running price: an absolute `price` replaces
 * it, `percent_off` discounts it. Within a stage the lowest priority
 * number wins (ties → lowest id). Promotion/coupon (document discount)
 * and the authorized manual override are later, separate layers.
 * `explain()` reports every stage — applied or not — so the breakdown
 * is auditable, never a black box.
 */
class PricingService
{
    public function resolveUnitPrice(
        Product $product,
        ?Customer $customer = null,
        ?float $explicit = null,
        ?string $at = null,
        int $qty = 1,
    ): float {
        return $this->explain($product, $customer, $explicit, $at, $qty)['final'];
    }

    /**
     * Zone-wise shipping charge (02-88): the customer's district maps to
     * a delivery zone whose base_charge + per_kg_charge × weight, plus the
     * first active weight-slab zone_charge covering the weight, become the
     * shipping default whenever the caller supplies no explicit amount.
     * No customer/district/zone → 0. Never a fabricated charge.
     */
    public function resolveShipping(int $companyId, ?Customer $customer, float $weightKg = 0.0): float
    {
        if ($customer === null || $customer->district_id === null) {
            return 0.0;
        }

        return $this->shippingForDistrict($companyId, $customer->district_id, $weightKg) ?? 0.0;
    }

    /**
     * District → active-zone charge (02-92 rates adapter): null when no
     * active zone of this company covers the district, so callers can
     * tell "no zone" apart from "free". resolveShipping folds null into
     * 0.0 for order defaults.
     */
    public function shippingForDistrict(int $companyId, int $districtId, float $weightKg = 0.0): ?float
    {
        $zoneIds = DB::table('delivery_zone_district')
            ->where('district_id', $districtId)
            ->pluck('delivery_zone_id');

        if ($zoneIds->isEmpty()) {
            return null;
        }

        $zone = DeliveryZone::query()
            ->whereIn('id', $zoneIds)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($zone === null) {
            return null;
        }

        $weightKg = max(0.0, $weightKg);
        $shipping = (float) $zone->base_charge + (float) $zone->per_kg_charge * $weightKg;

        $slab = ZoneCharge::query()
            ->where('company_id', $companyId)
            ->where('delivery_zone_id', $zone->id)
            ->where('is_active', true)
            ->where('weight_from', '<=', $weightKg)
            ->where(function ($query) use ($weightKg) {
                $query->whereNull('weight_to')->orWhere('weight_to', '>=', $weightKg);
            })
            ->orderBy('weight_from')
            ->orderBy('id')
            ->first();

        if ($slab !== null) {
            $shipping += (float) $slab->amount;
        }

        return round($shipping, 4);
    }

    /**
     * Full price explanation: base source + one entry per stage with
     * before/after amounts + the final price.
     *
     * @return array{base: array<string, mixed>, stages: list<array<string, mixed>>, final: float}
     */
    public function explain(
        Product $product,
        ?Customer $customer = null,
        ?float $explicit = null,
        ?string $at = null,
        int $qty = 1,
    ): array {
        if ($explicit !== null && $explicit >= 0) {
            $price = round($explicit, 4);

            return [
                'base' => [
                    'source' => 'explicit',
                    'price' => $price,
                    'price_list_id' => null,
                    'price_list_code' => null,
                ],
                'stages' => [],
                'final' => $price,
            ];
        }

        $at ??= now()->toDateTimeString();

        $base = $this->basePrice($product, $customer, $at);
        $running = (float) $base['price'];

        $zoneId = $this->zoneFor($product, $customer);
        $rules = $this->candidateRules($product);
        $categoryId = $product->product_category_id;

        $stages = [];

        foreach (PricingRule::STAGES as $stage) {
            $rule = $this->winnerFor(
                $rules,
                $stage,
                $product,
                $categoryId,
                $customer,
                $zoneId,
                $qty,
                $at,
                $base['price_list_id'],
            );

            if ($rule === null) {
                $stages[] = [
                    'stage' => $stage,
                    'rule_id' => null,
                    'rule_name' => null,
                    'applied' => false,
                    'mode' => null,
                    'before' => $running,
                    'after' => $running,
                ];

                continue;
            }

            $mode = $rule->price !== null ? 'price' : 'percent_off';
            $after = $rule->price !== null
                ? round((float) $rule->price, 4)
                : round($running * (1 - ((float) $rule->percent_off) / 100), 4);

            $stages[] = [
                'stage' => $stage,
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'applied' => true,
                'mode' => $mode,
                'before' => $running,
                'after' => $after,
            ];

            $running = $after;
        }

        return ['base' => $base, 'stages' => $stages, 'final' => $running];
    }

    /** @return array{source: string, price: float, price_list_id: ?int, price_list_code: ?string} */
    protected function basePrice(Product $product, ?Customer $customer, string $at): array
    {
        $priceList = $this->priceListFor($customer, $at);

        if ($priceList !== null) {
            $item = PriceListItem::query()
                ->where('price_list_id', $priceList->id)
                ->where('product_id', $product->id)
                ->first();

            if ($item !== null) {
                return [
                    'source' => 'price_list',
                    'price' => round((float) $item->price, 4),
                    'price_list_id' => $priceList->id,
                    'price_list_code' => $priceList->code,
                ];
            }
        }

        // Fallback: standard cost as floor, or zero for non-stocked
        return [
            'source' => 'standard_cost',
            'price' => round((float) ($product->standard_cost ?? 0), 4),
            'price_list_id' => null,
            'price_list_code' => null,
        ];
    }

    public function priceListFor(?Customer $customer, ?string $at = null): ?PriceList
    {
        // Future: customer may carry price_list_id; for now use company default
        return PriceList::query()
            ->where('is_default', true)
            ->active()
            ->effective($at)
            ->first();
    }

    /** Delivery zone derived from the customer's district (company-scoped). */
    protected function zoneFor(Product $product, ?Customer $customer): ?int
    {
        if ($customer === null || $customer->district_id === null) {
            return null;
        }

        $zoneIds = DB::table('delivery_zone_district')
            ->where('district_id', $customer->district_id)
            ->pluck('delivery_zone_id');

        if ($zoneIds->isEmpty()) {
            return null;
        }

        return (int) DeliveryZone::query()
            ->whereIn('id', $zoneIds)
            ->where('company_id', $product->company_id)
            ->value('id');
    }

    /** @return Collection<int, PricingRule> */
    protected function candidateRules(Product $product)
    {
        return PricingRule::query()
            ->where('company_id', $product->company_id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    protected function winnerFor(
        iterable $rules,
        string $stage,
        Product $product,
        ?int $categoryId,
        ?Customer $customer,
        ?int $zoneId,
        int $qty,
        string $at,
        ?int $baseListId,
    ): ?PricingRule {
        foreach ($rules as $rule) {
            if ($rule->rule_type !== $stage) {
                continue;
            }

            if (! $this->matches($rule, $product, $categoryId, $customer, $zoneId, $qty, $at, $baseListId)) {
                continue;
            }

            // candidateRules is ordered by priority then id → first match wins.
            return $rule;
        }

        return null;
    }

    protected function matches(
        PricingRule $rule,
        Product $product,
        ?int $categoryId,
        ?Customer $customer,
        ?int $zoneId,
        int $qty,
        string $at,
        ?int $baseListId,
    ): bool {
        // Effective dating: date window always; time-of-day only when the
        // caller gave a timestamp (a bare date covers the whole day).
        $date = substr($at, 0, 10);

        if ($rule->valid_from !== null && $rule->valid_from->toDateString() > $date) {
            return false;
        }

        if ($rule->valid_to !== null && $rule->valid_to->toDateString() < $date) {
            return false;
        }

        if (str_contains($at, ':')) {
            $time = date('H:i:s', strtotime($at));

            // Stored times may be H:i — normalise both sides to H:i:s so
            // the 17:00 boundary matches at exactly 17:00:00.
            $from = $rule->time_from !== null ? substr((string) $rule->time_from, 0, 5).':00' : null;
            $to = $rule->time_to !== null ? substr((string) $rule->time_to, 0, 5).':00' : null;

            if ($from !== null && $from > $time) {
                return false;
            }

            if ($to !== null && $to < $time) {
                return false;
            }
        }

        if ($rule->price_list_id !== null && (int) $rule->price_list_id !== (int) $baseListId) {
            return false;
        }

        if ($rule->product_id !== null && (int) $rule->product_id !== (int) $product->id) {
            return false;
        }

        if ($rule->product_category_id !== null && (int) $rule->product_category_id !== (int) $categoryId) {
            return false;
        }

        if ($rule->customer_id !== null && ($customer === null || (int) $rule->customer_id !== (int) $customer->id)) {
            return false;
        }

        if ($rule->customer_group_id !== null
            && ($customer === null || (int) $customer->customer_group_id !== (int) $rule->customer_group_id)) {
            return false;
        }

        if ($rule->delivery_zone_id !== null && (int) $rule->delivery_zone_id !== (int) $zoneId) {
            return false;
        }

        if ($rule->qty_min !== null && $rule->qty_min > $qty) {
            return false;
        }

        if ($rule->qty_max !== null && $rule->qty_max < $qty) {
            return false;
        }

        return true;
    }
}
