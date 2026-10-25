<?php

namespace App\Services\Checkout;

use App\Domain\Money;
use App\Models\Product;

/**
 * One sale line produced from a meal selection: the meal itself, or one of its add-ons. Everything on it -- price, names,
 * menu path, chosen options -- is already the server's own resolution at `sold_at`; CheckoutService only turns it into
 * a sale_items row and its sale_item_selections rows.
 *
 * @phpstan-type Selection array{group_name: string, option_label: string, dish_id: ?string, dish_name: ?string, dish_category_name: ?string, price: ?Money, sort_order: int}
 */
final readonly class ResolvedMealLine
{
    /**
     * @param  Product  $product  the hidden backing product (inactive on purpose; it is never looked up as a plain product)
     * @param  string  $description  what the line is called on the invoice, e.g. "Standard Meal - Beef nilaga" or "Whole rice"
     * @param  list<array{group_name: string, option_label: string, dish_id: ?string, dish_name: ?string, dish_category_name: ?string, price: ?Money, sort_order: int}>  $selections
     */
    public function __construct(
        public Product $product,
        public Money $unitPrice,
        public string $description,
        public string $mealId,
        public string $mealName,
        public ?string $menuGroupPath,
        public array $selections,
    ) {}
}
