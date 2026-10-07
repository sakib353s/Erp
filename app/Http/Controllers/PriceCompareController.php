<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Masters\PriceList;
use App\Domain\Masters\PriceListItem;
use App\Domain\Masters\PricingRule;
use App\Domain\Sales\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Price comparison reader (02-112): for one product, show every price
 * list's item price, every active rule whose product/category scope
 * covers it (with the price that rule alone would produce), and the
 * fully resolved price for the selected customer/qty/date context —
 * all strictly company-scoped, read-only, behind pricing.view.
 */
class PriceCompareController extends Controller
{
    protected const STAGE_LABELS = [
        PricingRule::TYPE_CUSTOMER_GROUP => 'Customer group',
        PricingRule::TYPE_QUANTITY_BREAK => 'Quantity break',
        PricingRule::TYPE_GEOGRAPHIC => 'Geographic',
        PricingRule::TYPE_TIME_BASED => 'Time-based',
        PricingRule::TYPE_SPECIAL => 'Special',
    ];

    public function __construct(protected PricingService $pricing) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $data = $request->validate([
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('company_id', $user->company_id)],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('company_id', $user->company_id)],
            'qty' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'at' => ['nullable', 'date'],
        ]);

        $product = isset($data['product_id']) && $data['product_id'] !== null
            ? Product::query()
                ->where('company_id', $user->company_id)
                ->findOrFail((int) $data['product_id'])
            : null;

        $customer = isset($data['customer_id']) && $data['customer_id'] !== null
            ? Customer::query()
                ->where('company_id', $user->company_id)
                ->findOrFail((int) $data['customer_id'])
            : null;

        $qty = max(1, (int) ($data['qty'] ?? 1));
        $at = $data['at'] ?? null;

        $products = Product::query()
            ->where('company_id', $user->company_id)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $customers = Customer::query()
            ->where('company_id', $user->company_id)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $context = [
            'product_id' => $product?->id,
            'customer_id' => $customer?->id,
            'qty' => $qty,
            'at' => $at,
        ];

        if ($product === null) {
            return view('pricing.compare', [
                'product' => null,
                'customer' => null,
                'products' => $products,
                'customers' => $customers,
                'context' => $context,
                'lists' => collect(),
                'rules' => collect(),
                'explain' => null,
                'basePrice' => null,
            ]);
        }

        $lists = PriceList::query()
            ->where('company_id', $user->company_id)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_default', 'is_active']);

        $items = PriceListItem::query()
            ->where('product_id', $product->id)
            ->whereIn('price_list_id', $lists->pluck('id'))
            ->get(['price_list_id', 'price'])
            ->keyBy('price_list_id');

        $explain = $this->pricing->explain($product, $customer, null, $at, $qty);
        $basePrice = (float) $explain['base']['price'];

        $stageOrder = array_flip(PricingRule::STAGES);
        $rules = PricingRule::query()
            ->where('company_id', $user->company_id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $product->id))
            ->where(fn ($q) => $q->whereNull('product_category_id')->orWhere('product_category_id', $product->product_category_id))
            ->with([
                'customer:id,code',
                'customerGroup:id,code',
                'deliveryZone:id,code',
                'product:id,code',
                'priceList:id,code',
            ])
            ->get()
            ->sortBy(fn (PricingRule $rule) => [
                $stageOrder[$rule->rule_type] ?? 99,
                (int) $rule->priority,
                (int) $rule->id,
            ])
            ->values();

        return view('pricing.compare', [
            'product' => $product,
            'customer' => $customer,
            'products' => $products,
            'customers' => $customers,
            'context' => $context,
            'lists' => $lists,
            'items' => $items,
            'rules' => $rules,
            'explain' => $explain,
            'basePrice' => $basePrice,
            'stageLabels' => self::STAGE_LABELS,
        ]);
    }
}
