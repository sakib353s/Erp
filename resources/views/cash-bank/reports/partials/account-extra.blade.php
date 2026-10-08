<div class="erp-filter erp-filter-wide">
    <label class="form-label" for="account">Account</label>
    <select class="form-select" id="account" name="account">
        @foreach ($accounts as $option)
            <option value="{{ $option->id }}" @selected(($filters['account'] ?? null) === $option->id)>
                {{ $option->code }} — {{ $option->name }}
            </option>
        @endforeach
    </select>
</div>
