@extends('layouts.app')

@section('page_title', $mode === 'create' ? 'Add Pricing Rule' : 'Edit Pricing Rule')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $mode === 'create' ? 'Add Pricing Rule' : 'Edit Pricing Rule' }}</h1>
            <p class="erp-page-sub">Blank scope = applies to everything in the company. Exactly one value: a fixed price or a percent discount.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('pricing.rules.index') }}">Back to rules</a>
    </div>

    <form method="POST"
          action="{{ $mode === 'create' ? route('pricing.rules.store') : route('pricing.rules.update', $rule) }}">
        @csrf
        @if ($mode === 'edit')
            @method('PUT')
        @endif

        <div class="erp-card mb-3">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label" for="rule_type">Stage</label>
                    <select class="form-select" id="rule_type" name="rule_type" required>
                        @foreach (\App\Domain\Masters\PricingRule::TYPES as $t)
                            <option value="{{ $t }}" @selected(old('rule_type', $rule->rule_type) === $t)>
                                {{ str_replace('_', ' ', ucfirst($t)) }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">Special needs a customer · group needs a group · quantity break needs a min qty · geographic needs a zone.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" maxlength="120"
                           value="{{ old('name', $rule->name) }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="priority">Priority</label>
                    <input class="form-control" id="priority" name="priority" type="number" min="0" max="9999"
                           value="{{ old('priority', $rule->priority ?? 100) }}">
                    <div class="form-text">Lower wins within a stage.</div>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                            @checked(old('is_active', $rule->is_active ?? true))>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="erp-card mb-3">
            <h2 class="erp-h3">Value</h2>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label" for="price">Fixed price</label>
                    <input class="form-control" id="price" name="price" type="number" step="0.01" min="0"
                           value="{{ old('price', $rule->price) }}" placeholder="e.g. 89.00">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="percent_off">Or percent off (%)</label>
                    <input class="form-control" id="percent_off" name="percent_off" type="number" step="0.01" min="0" max="100"
                           value="{{ old('percent_off', $rule->percent_off) }}" placeholder="e.g. 10">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Window</label>
                    <div class="row g-2">
                        <div class="col">
                            <input class="form-control" type="date" name="valid_from"
                                   value="{{ old('valid_from', $rule->valid_from?->toDateString()) }}" title="Valid from">
                        </div>
                        <div class="col">
                            <input class="form-control" type="date" name="valid_to"
                                   value="{{ old('valid_to', $rule->valid_to?->toDateString()) }}" title="Valid to">
                        </div>
                        <div class="col">
                            <input class="form-control" type="time" name="time_from"
                                   value="{{ old('time_from', $rule->time_from) }}" title="Time from">
                        </div>
                        <div class="col">
                            <input class="form-control" type="time" name="time_to"
                                   value="{{ old('time_to', $rule->time_to) }}" title="Time to">
                        </div>
                    </div>
                    <div class="form-text">Dates and time-of-day are inclusive; blank = no limit.</div>
                </div>
            </div>
        </div>

        <div class="erp-card mb-3">
            <h2 class="erp-h3">Scope <span class="text-muted fs-6">(blank = everyone / every product)</span></h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="customer_id">Customer</label>
                    <select class="form-select" id="customer_id" name="customer_id">
                        <option value="">Any customer</option>
                        @foreach ($options['customers'] as $c)
                            <option value="{{ $c->id }}" @selected((int) old('customer_id', $rule->customer_id) === $c->id)>
                                {{ $c->code }} — {{ $c->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="customer_group_id">Customer group</label>
                    <select class="form-select" id="customer_group_id" name="customer_group_id">
                        <option value="">Any group</option>
                        @foreach ($options['groups'] as $g)
                            <option value="{{ $g->id }}" @selected((int) old('customer_group_id', $rule->customer_group_id) === $g->id)>
                                {{ $g->code }} — {{ $g->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="delivery_zone_id">Delivery zone</label>
                    <select class="form-select" id="delivery_zone_id" name="delivery_zone_id">
                        <option value="">Any zone</option>
                        @foreach ($options['zones'] as $z)
                            <option value="{{ $z->id }}" @selected((int) old('delivery_zone_id', $rule->delivery_zone_id) === $z->id)>
                                {{ $z->code }} — {{ $z->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="product_id">Product</label>
                    <select class="form-select" id="product_id" name="product_id">
                        <option value="">Any product</option>
                        @foreach ($options['products'] as $p)
                            <option value="{{ $p->id }}" @selected((int) old('product_id', $rule->product_id) === $p->id)>
                                {{ $p->code }} — {{ $p->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="product_category_id">Category</label>
                    <select class="form-select" id="product_category_id" name="product_category_id">
                        <option value="">Any category</option>
                        @foreach ($options['categories'] as $c)
                            <option value="{{ $c->id }}" @selected((int) old('product_category_id', $rule->product_category_id) === $c->id)>
                                {{ $c->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="price_list_id">Price list</label>
                    <select class="form-select" id="price_list_id" name="price_list_id">
                        <option value="">Any list</option>
                        @foreach ($options['priceLists'] as $l)
                            <option value="{{ $l->id }}" @selected((int) old('price_list_id', $rule->price_list_id) === $l->id)>
                                {{ $l->code }} — {{ $l->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="qty_min">Min qty</label>
                    <input class="form-control" id="qty_min" name="qty_min" type="number" min="1"
                           value="{{ old('qty_min', $rule->qty_min) }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="qty_max">Max qty</label>
                    <input class="form-control" id="qty_max" name="qty_max" type="number" min="1"
                           value="{{ old('qty_max', $rule->qty_max) }}">
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">{{ $mode === 'create' ? 'Create rule' : 'Save changes' }}</button>
            <a class="btn btn-outline-secondary" href="{{ route('pricing.rules.index') }}">Cancel</a>
        </div>
    </form>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
