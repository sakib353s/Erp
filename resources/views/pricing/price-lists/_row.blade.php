<tr>
    <td>
        <select class="form-select" name="items[{{ $index }}][product_id]" required>
            <option value="">— select product —</option>
            @foreach ($products as $product)
                <option value="{{ $product->id }}" @selected((string) old("items.$index.product_id", $row['product_id'] ?? '') === (string) $product->id)>
                    {{ $product->sku }} — {{ $product->name }}
                </option>
            @endforeach
        </select>
        @error("items.$index.product_id") <div class="text-danger small">{{ $message }}</div> @enderror
    </td>
    <td>
        <input class="form-control" type="number" step="0.0001" min="0" name="items[{{ $index }}][price]"
               value="{{ old("items.$index.price", $row['price'] ?? '') }}" required>
        @error("items.$index.price") <div class="text-danger small">{{ $message }}</div> @enderror
    </td>
    <td class="text-end">
        <button class="btn btn-sm btn-outline-danger" type="button" data-remove-row aria-label="Remove row">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </td>
</tr>
