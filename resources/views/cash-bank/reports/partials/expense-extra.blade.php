<div class="erp-filter">
    <label class="form-label" for="category">Category</label>
    <select class="form-select" id="category" name="category">
        <option value="">Every category</option>
        @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected(($filters['category_id'] ?? null) === $category->id)>{{ $category->name }}</option>
        @endforeach
    </select>
</div>
<div class="erp-filter">
    <label class="form-label" for="settled">Paid or owed</label>
    <select class="form-select" id="settled" name="settled">
        <option value="">Both</option>
        @foreach (\App\Domain\CashBank\Expense::SETTLED_WITH as $key => $label)
            <option value="{{ $key }}" @selected(($filters['settled_with'] ?? null) === $key)>{{ $label }}</option>
        @endforeach
    </select>
</div>
<div class="erp-filter erp-filter-wide">
    <label class="form-label" for="q">Search</label>
    <div class="erp-input-group">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}"
               placeholder="Expense number, payee or what it was for…" autocomplete="off">
    </div>
</div>
