<div class="erp-filter">
    <label class="form-label" for="state">State</label>
    <select class="form-select" id="state" name="state">
        <option value="">Open and closed</option>
        <option value="open" @selected(($filters['state'] ?? null) === 'open')>Still open</option>
        <option value="closed" @selected(($filters['state'] ?? null) === 'closed')>Closed</option>
    </select>
</div>
