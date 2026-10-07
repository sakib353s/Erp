@extends('layouts.app')

@section('page_title', 'Price Comparison')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Price Comparison</h1>
            <p class="erp-page-sub">List, group and rule prices for one product across every scope — resolved for the selected context.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('pricing.history') }}">
                <i class="bi bi-clock-history" aria-hidden="true"></i> History
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('pricing.rules.index') }}">
                <i class="bi bi-sliders" aria-hidden="true"></i> Rules
            </a>
        </div>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="product_id">Product</label>
                <select class="form-select" id="product_id" name="product_id">
                    <option value="">Choose a product…</option>
                    @foreach ($products as $option)
                        <option value="{{ $option->id }}" @selected($context['product_id'] === $option->id)>
                            {{ $option->code }} — {{ $option->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="customer_id">Customer context</label>
                <select class="form-select" id="customer_id" name="customer_id">
                    <option value="">Walk-in (no customer)</option>
                    @foreach ($customers as $option)
                        <option value="{{ $option->id }}" @selected($context['customer_id'] === $option->id)>
                            {{ $option->code }} — {{ $option->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label" for="qty">Qty</label>
                <input class="form-control" type="number" id="qty" name="qty" min="1"
                       value="{{ $context['qty'] }}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="at">Date</label>
                <input class="form-control" type="date" id="at" name="at" value="{{ $context['at'] }}">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100" type="submit">Compare</button>
            </div>
        </form>
    </div>

    @if ($product === null)
        <div class="erp-card">
            <p class="mb-0 text-body-secondary">Choose a product to compare its prices across every price list and rule.</p>
        </div>
    @else
        <div class="row g-3 mb-3">
            <div class="col-lg-4">
                <div class="erp-card h-100">
                    <h2 class="erp-h3">Price lists</h2>
                    <div class="table-responsive">
                        <table class="table erp-table align-middle mb-0">
                            <thead>
                            <tr>
                                <th>List</th>
                                <th class="text-end">Item price</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($lists as $list)
                                <tr>
                                    <td>
                                        {{ $list->code }} — {{ $list->name }}
                                        @if ($list->is_default)
                                            <span class="badge text-bg-primary">default</span>
                                        @endif
                                        @unless ($list->is_active)
                                            <span class="badge text-bg-secondary">inactive</span>
                                        @endunless
                                    </td>
                                    <td class="text-end">
                                        @if (isset($items[$list->id]))
                                            {{ number_format((float) $items[$list->id]->price, 2) }}
                                        @else
                                            <span class="text-body-secondary">Not listed</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="text-body-secondary">No price lists for this company.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="erp-card h-100">
                    <h2 class="erp-h3">Resolved price for this context</h2>
                    <table class="table erp-table align-middle mb-2">
                        <tbody>
                        <tr>
                            <td>Base ({{ $explain['base']['source'] }}@isset($explain['base']['price_list_code']) — {{ $explain['base']['price_list_code'] }}@endisset)</td>
                            <td class="text-end">{{ number_format((float) $explain['base']['price'], 2) }}</td>
                        </tr>
                        @foreach ($explain['stages'] as $stage)
                            <tr class="{{ $stage['applied'] ? '' : 'opacity-50' }}">
                                <td>
                                    {{ $stageLabels[$stage['stage']] ?? $stage['stage'] }} stage
                                    @if ($stage['applied'])
                                        <span class="badge text-bg-success">applied</span>
                                        <span class="text-body-secondary">({{ $stage['mode'] }})</span>
                                    @else
                                        <span class="badge text-bg-light text-body-secondary">not matched</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($stage['applied'])
                                        {{ number_format((float) $stage['after'], 2) }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <p class="mb-0 d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">Final unit price</span>
                        <span class="fs-4 fw-bold">{{ number_format((float) $explain['final'], 2) }}</span>
                    </p>
                </div>
            </div>
        </div>

        <div class="erp-card">
            <h2 class="erp-h3">Rules covering this product</h2>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Stage</th>
                        <th>Rule</th>
                        <th>Scope</th>
                        <th>Value</th>
                        <th class="text-end">If applied →</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($rules as $rule)
                        <tr>
                            <td><span class="badge text-bg-{{ ['customer_group' => 'info', 'quantity_break' => 'success', 'geographic' => 'warning', 'time_based' => 'secondary', 'special' => 'danger'][$rule->rule_type] ?? 'secondary' }}">{{ $stageLabels[$rule->rule_type] ?? $rule->rule_type }}</span></td>
                            <td>{{ $rule->name }} <span class="text-body-secondary">#{{ $rule->id }} · p{{ $rule->priority }}</span></td>
                            <td>{{ $rule->scopeDescription() }}</td>
                            <td>{{ $rule->valueDescription() }}</td>
                            <td class="text-end">{{ number_format($rule->applyTo($basePrice), 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-body-secondary">No active rule covers this product.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
