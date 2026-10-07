<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\Product;
use App\Domain\Masters\Actions\BulkPriceUpdate;
use App\Domain\Masters\Brand;
use App\Domain\Masters\PriceBulkUpdate;
use App\Domain\Masters\PriceList;
use App\Domain\Masters\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Bulk price update screen (02-109): preview (pure) → confirm (queued
 * batch, workflow gate when the change meets the threshold). The company
 * scope of the price list / products is enforced server-side; the preview
 * rows shown are exactly what a confirm would compute.
 */
class BulkPriceUpdateController extends Controller
{
    public function __construct(protected BulkPriceUpdate $bulk) {}

    public function index(Request $request): View
    {
        return view('pricing.bulk-update', $this->viewData($request, null));
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'mode' => ['required', 'in:preview,confirm'],
            'price_list_id' => ['required', 'integer'],
            'change_type' => ['required', 'in:percent,set'],
            'percent' => ['nullable', 'numeric', 'min:-99.99', 'max:1000'],
            'set_price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'product_codes' => ['nullable', 'string', 'max:4000'],
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['change_type'] === 'percent' && ! isset($data['percent'])) {
            throw ValidationException::withMessages(['percent' => 'Enter the percent change.']);
        }

        if ($data['change_type'] === 'set' && ! isset($data['set_price'])) {
            throw ValidationException::withMessages(['set_price' => 'Enter the new price.']);
        }

        $priceList = PriceList::query()
            ->where('company_id', $user->company_id)
            ->find((int) $data['price_list_id']);

        if ($priceList === null) {
            throw ValidationException::withMessages(['price_list_id' => 'Unknown price list.']);
        }

        if (! empty($data['category_id'])
            && ! ProductCategory::query()->where('company_id', $user->company_id)->where('id', $data['category_id'])->exists()) {
            throw ValidationException::withMessages(['category_id' => 'Unknown category.']);
        }

        if (! empty($data['brand_id'])
            && ! Brand::query()->where('company_id', $user->company_id)->where('id', $data['brand_id'])->exists()) {
            throw ValidationException::withMessages(['brand_id' => 'Unknown brand.']);
        }

        $payload = [
            'price_list_id' => $priceList->id,
            'change_type' => $data['change_type'],
        ];

        if (isset($data['percent'])) {
            $payload['percent'] = (float) $data['percent'];
        }

        if (isset($data['set_price'])) {
            $payload['set_price'] = (float) $data['set_price'];
        }

        $codes = $this->parseCodes($data['product_codes'] ?? null);

        if ($codes !== []) {
            $found = Product::query()
                ->where('company_id', $user->company_id)
                ->whereIn('code', $codes)
                ->pluck('id', 'code');

            $missing = array_values(array_diff($codes, $found->keys()->all()));

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'product_codes' => 'Unknown product code(s): '.implode(', ', $missing),
                ]);
            }

            $payload['product_ids'] = array_values($found->all());
        }

        if (! empty($data['category_id'])) {
            $payload['category_id'] = (int) $data['category_id'];
        }

        if (! empty($data['brand_id'])) {
            $payload['brand_id'] = (int) $data['brand_id'];
        }

        if (! empty($data['note'])) {
            $payload['note'] = $data['note'];
        }

        if ($data['mode'] === 'preview') {
            $result = $this->bulk->preview($user, $payload);

            return view('pricing.bulk-update', $this->viewData($request, [
                'payload' => $payload,
                'result' => $result,
                'codes_text' => implode(', ', $codes),
            ]));
        }

        $batch = $this->bulk->confirm($user, $payload)->fresh();

        $message = match ($batch->status) {
            PriceBulkUpdate::STATUS_APPLIED => sprintf(
                'Batch #%d applied: %d price(s) updated.',
                $batch->id,
                $batch->row_count,
            ),
            PriceBulkUpdate::STATUS_PENDING => sprintf(
                'Batch #%d held for approval: changes at or above %s%% require workflow sign-off.',
                $batch->id,
                $batch->threshold_pct,
            ),
            PriceBulkUpdate::STATUS_FAILED => sprintf('Batch #%d failed: %s', $batch->id, (string) $batch->error),
            default => sprintf('Batch #%d queued.', $batch->id),
        };

        return redirect()->route('pricing.bulk-update')->with('status', $message);
    }

    /** @return array<int, string> */
    private function parseCodes(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $codes = array_values(array_filter(array_map('trim', explode(',', $raw))));

        return array_values(array_unique($codes));
    }

    private function viewData(Request $request, ?array $preview): array
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $form = $preview['payload'] ?? [];

        return [
            'lists' => PriceList::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'categories' => ProductCategory::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(['id', 'name']),
            'brands' => Brand::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(['id', 'name']),
            'recent' => PriceBulkUpdate::query()
                ->with('creator')
                ->where('company_id', $companyId)
                ->latest()
                ->limit(10)
                ->get(),
            'threshold' => $this->bulk->thresholdPct(),
            'preview' => $preview,
            'form' => $form,
        ];
    }
}
