<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Inventory\Product;
use App\Domain\Masters\PriceList;
use App\Domain\Masters\ProductPriceHistory;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Append-only price history reader (02-110): every change written by the
 * bulk updater (and manual edits) shows up here newest-first, scoped to
 * the running user's company, with product/list/source/date filters that
 * never widen scope.
 */
class PriceHistoryController extends Controller
{
    public function __construct(protected PermissionCatalog $catalog) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'price_list_id' => ['nullable', 'integer'],
            'source' => ['nullable', 'in:manual,bulk_update'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $search = trim((string) ($data['q'] ?? ''));

        $query = ProductPriceHistory::query()
            ->where('company_id', $user->company_id)
            ->with([
                'product:id,code,name',
                'priceList:id,code,name',
                'changedBy:id,name',
                'bulkUpdate:id,status',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $needle = '%'.$search.'%';
            $query->whereHas('product', function ($q) use ($user, $needle) {
                $q->where('company_id', $user->company_id)
                    ->where(fn ($w) => $w->where('code', 'like', $needle)
                        ->orWhere('name', 'like', $needle));
            });
        }

        if (! empty($data['price_list_id'])) {
            $query->where('price_list_id', (int) $data['price_list_id']);
        }

        if (! empty($data['source'])) {
            $query->where('source', $data['source']);
        }

        if (! empty($data['from'])) {
            $query->whereDate('created_at', '>=', $data['from']);
        }

        if (! empty($data['to'])) {
            $query->whereDate('created_at', '<=', $data['to']);
        }

        return $this->render($query, $user, $data, $search, null);
    }

    /**
     * §04-14: the same append-only history, scoped to one product — the screen a
     * product page links to when somebody asks "why is this price what it is?".
     */
    public function forProduct(Request $request, Product $product): View
    {
        $user = $request->user();

        abort_unless((int) $product->company_id === (int) $user->company_id, 404, 'That product does not exist.');

        $data = $request->validate([
            'source' => ['nullable', 'in:manual,bulk_update'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = ProductPriceHistory::query()
            ->where('company_id', $user->company_id)
            ->where('product_id', $product->id)
            ->with(['product:id,code,name', 'priceList:id,code,name', 'changedBy:id,name', 'bulkUpdate:id,status'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($data['source'])) {
            $query->where('source', $data['source']);
        }

        if (! empty($data['from'])) {
            $query->whereDate('created_at', '>=', $data['from']);
        }

        if (! empty($data['to'])) {
            $query->whereDate('created_at', '<=', $data['to']);
        }

        return $this->render($query, $user, $data, '', $product);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<ProductPriceHistory>  $query
     * @param  array<string, mixed>  $data
     */
    protected function render($query, $user, array $data, string $search, ?Product $product): View
    {
        return view('pricing.history', [
            'rows' => $query->paginate(25)->withQueryString(),
            'product' => $product,
            'lists' => PriceList::query()
                ->where('company_id', $user->company_id)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'filters' => [
                'q' => $search,
                'product_id' => $product?->id,
                'price_list_id' => isset($data['price_list_id']) ? (int) $data['price_list_id'] : null,
                'source' => $data['source'] ?? null,
                'from' => $data['from'] ?? null,
                'to' => $data['to'] ?? null,
            ],
            'canBulk' => $this->catalog->allows($user, 'pricing.bulk_update'),
            'canAudit' => $this->catalog->allows($user, 'audit.view'),
        ]);
    }
}
