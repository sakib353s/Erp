<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Masters\CustomerGroup;
use App\Domain\Masters\DeliveryZone;
use App\Domain\Masters\PriceList;
use App\Domain\Masters\PricingRule;
use App\Domain\Masters\ProductCategory;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Pricing rules admin (02-111) + governance (02-113): CRUD for the
 * deterministic pricing cascade (special → customer_group →
 * quantity_break → geographic → time_based) plus the customer-group
 * registry the group stage targets. Governance adds enable/disable and
 * priority endpoints, and a manual-override gate: over-threshold value
 * changes without pricing.override go through the generic workflow
 * (entity_type=pricing_rule, action=override) before they take effect.
 * Every mutation is company-scoped and audited through Auditable.
 */
class PricingRuleController extends Controller
{
    public function __construct(
        protected PermissionCatalog $catalog,
        protected WorkflowEngine $workflow,
    ) {}

    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        $query = PricingRule::query()
            ->where('company_id', $companyId)
            ->with(['customerGroup', 'product', 'customer', 'deliveryZone', 'priceList'])
            ->orderBy('priority')
            ->orderBy('id');

        $type = trim((string) $request->query('type'));

        if ($type !== '' && in_array($type, PricingRule::TYPES, true)) {
            $query->where('rule_type', $type);
        } else {
            $type = null;
        }

        return view('pricing.rules.index', [
            'rules' => $query->paginate(15)->withQueryString(),
            'type' => $type,
            'groups' => CustomerGroup::query()
                ->where('company_id', $companyId)
                ->orderBy('code')
                ->withCount('customers')
                ->get(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('pricing.rules.form', [
            'rule' => new PricingRule(['is_active' => true, 'priority' => 100]),
            'mode' => 'create',
            'options' => $this->formOptions((int) $request->user()->company_id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;
        $payload = $this->validatePayload($request, $companyId);

        $gated = $this->needsApproval($request, $payload, $companyId);

        if ($gated) {
            $rule = PricingRule::query()->create(array_merge($payload, [
                'company_id' => $companyId,
                'created_by' => $request->user()->id,
                'is_active' => false, // held: never resolves until approved
            ]));

            if ($this->submitOverride($request, $rule, $payload) !== null) {
                $rule->forceFill(['approval_status' => 'pending'])->save();

                return redirect()
                    ->route('pricing.rules.index', ['type' => $payload['rule_type']])
                    ->with('status', 'Pricing rule override held for approval.');
            }

            // No matching workflow definition — bypass, apply directly.
            $rule->forceFill(['is_active' => (bool) $payload['is_active']])->save();
        } else {
            PricingRule::query()->create($payload + [
                'company_id' => $companyId,
                'created_by' => $request->user()->id,
            ]);
        }

        return redirect()
            ->route('pricing.rules.index', ['type' => $payload['rule_type']])
            ->with('status', "Rule \"{$payload['name']}\" created.");
    }

    public function edit(Request $request, PricingRule $rule): View
    {
        abort_unless((int) $rule->company_id === (int) $request->user()->company_id, 404);

        return view('pricing.rules.form', [
            'rule' => $rule,
            'mode' => 'edit',
            'options' => $this->formOptions((int) $request->user()->company_id),
        ]);
    }

    public function update(Request $request, PricingRule $rule): RedirectResponse
    {
        abort_unless((int) $rule->company_id === (int) $request->user()->company_id, 404);

        $this->guardPending($rule);

        $companyId = (int) $request->user()->company_id;
        $payload = $this->validatePayload($request, $companyId, $rule);

        if ($this->needsApproval($request, $payload, $companyId, $rule)) {
            if ($this->submitOverride($request, $rule, $payload) !== null) {
                // Held: the rule row keeps its current values until the
                // approval listener applies the snapshot on approve.
                $rule->forceFill(['approval_status' => 'pending'])->save();

                return redirect()
                    ->route('pricing.rules.index', ['type' => $payload['rule_type']])
                    ->with('status', 'Pricing rule override held for approval.');
            }

            // No matching workflow definition — bypass, apply directly.
            $rule->update(array_merge($payload, ['approval_status' => null]));

            return redirect()
                ->route('pricing.rules.index', ['type' => $payload['rule_type']])
                ->with('status', "Rule \"{$payload['name']}\" updated.");
        }

        $rule->update(array_merge($payload, ['approval_status' => null]));

        return redirect()
            ->route('pricing.rules.index', ['type' => $payload['rule_type']])
            ->with('status', "Rule \"{$payload['name']}\" updated.");
    }

    public function toggleStatus(Request $request, PricingRule $rule): RedirectResponse
    {
        abort_unless((int) $rule->company_id === (int) $request->user()->company_id, 404);

        $this->guardPending($rule);

        $rule->update(['is_active' => ! $rule->is_active]);

        return redirect()
            ->route('pricing.rules.index', ['type' => $rule->rule_type])
            ->with('status', "Rule \"{$rule->name}\" ".($rule->is_active ? 'enabled' : 'disabled').'.');
    }

    public function updatePriority(Request $request, PricingRule $rule): RedirectResponse
    {
        abort_unless((int) $rule->company_id === (int) $request->user()->company_id, 404);

        $this->guardPending($rule);

        $data = $request->validate([
            'priority' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $rule->update(['priority' => (int) $data['priority']]);

        return redirect()
            ->route('pricing.rules.index', ['type' => $rule->rule_type])
            ->with('status', "Rule \"{$rule->name}\" priority set to {$data['priority']}.");
    }

    public function destroy(Request $request, PricingRule $rule): RedirectResponse
    {
        abort_unless((int) $rule->company_id === (int) $request->user()->company_id, 404);

        $name = $rule->name;
        $type = $rule->rule_type;
        $rule->delete();

        return redirect()
            ->route('pricing.rules.index', ['type' => $type])
            ->with('status', "Rule \"{$name}\" deleted.");
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $code = strtoupper(trim($data['code']));

        $duplicate = CustomerGroup::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['code' => "Group code {$code} already exists."]);
        }

        CustomerGroup::query()->create([
            'company_id' => $companyId,
            'code' => $code,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => true,
        ]);

        return redirect()
            ->route('pricing.rules.index', ['#' => 'groups'])
            ->with('status', "Customer group {$code} created.");
    }

    public function destroyGroup(Request $request, CustomerGroup $group): RedirectResponse
    {
        abort_unless((int) $group->company_id === (int) $request->user()->company_id, 404);

        if ($group->customers()->exists()) {
            return back()->withErrors(['group' => "Group {$group->code} still has customers."]);
        }

        $code = $group->code;
        $group->rules()->delete();
        $group->delete();

        return redirect()
            ->route('pricing.rules.index', ['#' => 'groups'])
            ->with('status', "Customer group {$code} deleted.");
    }

    /** Manual override authority (02-113): pricing.override skips the WF. */
    protected function needsApproval(Request $request, array $payload, int $companyId, ?PricingRule $rule = null): bool
    {
        if ($this->catalog->allows($request->user(), 'pricing.override')) {
            return false;
        }

        return PricingRule::exceedsOverrideThreshold($payload, $companyId, $rule);
    }

    protected function guardPending(PricingRule $rule): void
    {
        if ($rule->hasPendingApproval()) {
            throw ValidationException::withMessages([
                'rule' => 'This rule has a pending override approval — approve or reject it before editing.',
            ]);
        }
    }

    protected function submitOverride(Request $request, PricingRule $rule, array $payload): ?ApprovalRequest
    {
        $user = $request->user();

        return $this->workflow->submit([
            'entity_type' => PricingRule::ENTITY_TYPE,
            'entity_id' => $rule->id,
            'action' => PricingRule::APPROVAL_ACTION,
            'subject' => 'Pricing rule override: '.$rule->name,
            'branch_id' => $user->default_branch_id,
            'submitted_by' => $user,
            'snapshot' => $payload,
            'context' => [
                'entity_type' => PricingRule::ENTITY_TYPE,
                'branch_id' => $user->default_branch_id,
                'currency' => 'BDT',
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatePayload(Request $request, int $companyId, ?PricingRule $rule = null): array
    {
        $data = $request->validate([
            'rule_type' => ['required', Rule::in(PricingRule::TYPES)],
            'name' => ['required', 'string', 'max:120'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],

            'price_list_id' => [
                'nullable', 'integer',
                Rule::exists('price_lists', 'id')->where('company_id', $companyId),
            ],
            'product_id' => [
                'nullable', 'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId),
            ],
            'product_category_id' => [
                'nullable', 'integer',
                Rule::exists('product_categories', 'id')->where('company_id', $companyId),
            ],
            'customer_id' => [
                'nullable', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId),
            ],
            'customer_group_id' => [
                'nullable', 'integer',
                Rule::exists('customer_groups', 'id')->where('company_id', $companyId),
            ],
            'delivery_zone_id' => [
                'nullable', 'integer',
                Rule::exists('delivery_zones', 'id')->where('company_id', $companyId),
            ],
            'qty_min' => ['nullable', 'integer', 'min:1'],
            'qty_max' => ['nullable', 'integer', 'min:1'],

            'price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'percent_off' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'time_from' => ['nullable', 'date_format:H:i'],
            'time_to' => ['nullable', 'date_format:H:i'],
        ], [
            'time_from.date_format' => 'The time must be in HH:MM format.',
            'time_to.date_format' => 'The time must be in HH:MM format.',
        ]);

        $errors = [];

        $hasPrice = ($data['price'] ?? null) !== null;
        $hasPercent = ($data['percent_off'] ?? null) !== null;

        if ($hasPrice === $hasPercent) {
            $errors['price'][] = 'Set exactly one of a fixed price or a percent discount.';
        }

        if (($data['qty_max'] ?? null) !== null
            && ($data['qty_min'] ?? null) !== null
            && (int) $data['qty_max'] < (int) $data['qty_min']) {
            $errors['qty_max'][] = 'The maximum quantity must be at least the minimum.';
        }

        $requiredScope = match ($data['rule_type']) {
            PricingRule::TYPE_SPECIAL => ['customer_id' => 'Special pricing needs a customer.'],
            PricingRule::TYPE_CUSTOMER_GROUP => ['customer_group_id' => 'Customer group pricing needs a group.'],
            PricingRule::TYPE_QUANTITY_BREAK => ['qty_min' => 'Quantity break pricing needs a minimum quantity.'],
            PricingRule::TYPE_GEOGRAPHIC => ['delivery_zone_id' => 'Geographic pricing needs a delivery zone.'],
            default => [],
        };

        foreach ($requiredScope as $field => $message) {
            if (($data[$field] ?? null) === null) {
                $errors[$field][] = $message;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $data['priority'] = $data['priority'] ?? 100;
        $data['is_active'] = $data['is_active'] ?? true;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOptions(int $companyId): array
    {
        return [
            'priceLists' => PriceList::query()->where('company_id', $companyId)->orderBy('code')->get(['id', 'code', 'name']),
            'products' => Product::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'categories' => ProductCategory::query()->where('company_id', $companyId)->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::query()->where('company_id', $companyId)->active()->orderBy('name')->get(['id', 'code', 'name']),
            'groups' => CustomerGroup::query()->where('company_id', $companyId)->orderBy('code')->get(['id', 'code', 'name']),
            'zones' => DeliveryZone::query()->where('company_id', $companyId)->orderBy('name')->get(['id', 'code', 'name']),
        ];
    }
}
