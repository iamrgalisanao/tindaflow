<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\ProductBarcode;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * openapi.yaml ProductInput, shared by productCreate and productUpdate. Both operations use the same
 * schema (required: sku, name, unit_of_measure, selling_price, tax_class), so the update also
 * requires them; optional fields are applied only when present.
 *
 * SKU and barcode uniqueness are per store (products migration). A duplicate is reported as a
 * structural VALIDATION_FAILED field error -- error-catalog.md registers no SKU/barcode-specific
 * code and `422 UnprocessableEntity` is the contract's response for both operations.
 */
class ProductInputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $storeId = Auth::guard('web')->user()->store_id;
        $productId = $this->route('productId');
        $money = 'regex:/^\d{1,10}\.\d{2}$/';

        return [
            'sku' => ['required', 'string', 'max:255', Rule::unique('products', 'sku')->where('store_id', $storeId)->ignore($productId)],
            'barcode' => ['nullable', 'string', 'max:255', $this->barcodeIsUnclaimed($storeId, $productId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'uuid', Rule::exists('categories', 'id')->where('store_id', $storeId)],
            'brand_id' => ['nullable', 'uuid', Rule::exists('brands', 'id')->where('store_id', $storeId)],
            'unit_of_measure' => ['required', 'string', 'max:255'],
            'cost' => ['nullable', $money],
            'selling_price' => ['required', $money],
            'tax_class' => ['required', Rule::in(['VATABLE', 'VAT_EXEMPT', 'ZERO_RATED', 'NON_VAT'])],
            'track_inventory' => ['sometimes', 'boolean'],
            'reorder_level' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** A barcode may sit on `products.barcode` or an alternate `product_barcodes` row; both are per-store unique. */
    private function barcodeIsUnclaimed(string $storeId, ?string $productId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($storeId, $productId): void {
            $onAnotherProduct = Product::where('store_id', $storeId)
                ->where('barcode', $value)
                ->when($productId, fn ($query) => $query->where('id', '!=', $productId))
                ->exists();

            $asAlternate = ProductBarcode::where('store_id', $storeId)
                ->where('barcode', $value)
                ->when($productId, fn ($query) => $query->where('product_id', '!=', $productId))
                ->exists();

            if ($onAnotherProduct || $asAlternate) {
                $fail('This barcode is already assigned to another product.');
            }
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sku.unique' => 'This SKU is already used by another product.',
            'selling_price.regex' => 'The selling price must be a decimal with exactly two places, e.g. 55.00.',
            'cost.regex' => 'The cost must be a decimal with exactly two places, e.g. 40.00.',
        ];
    }
}
