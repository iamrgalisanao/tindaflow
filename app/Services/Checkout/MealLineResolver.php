<?php

namespace App\Services\Checkout;

use App\Domain\Exceptions\MealNotAvailableException;
use App\Domain\Exceptions\MealSelectionInvalidException;
use App\Domain\Money;
use App\Models\Meal;
use App\Models\MealOption;
use App\Models\MealOptionGroup;
use App\Services\Menu\MenuGroupPath;
use App\Services\Menu\MenuPriceResolver;
use Carbon\CarbonInterface;

/**
 * ADR-016 section 3: turns a meal selection from the till into sale lines, entirely from the server's own menu data. The client
 * names a meal and the options it chose and never a price (invariants.md MENU-002); this class
 *   - checks the selection against the meal's configuration (MENU-003): every option belongs to the meal and to the group it
 *     was sent under, no option twice, each active group's pick count within min..max, quantity 1;
 *   - refuses anything hidden (the meal, its menu group, a group, an option, a dish or its category) as MEAL_NOT_AVAILABLE;
 *   - prices the meal from the pick in the `prices_the_meal` group by its dish category, and each add-on from its own row, with
 *     {@see MenuPriceResolver} at the sale instant (MENU-004), refusing a choice that has no price in force;
 *   - returns the meal line and one line per add-on, each carrying the names and prices to snapshot (MENU-001).
 *
 * The hidden backing products are inactive on purpose and are used here without being reactivated: this is the only path that
 * sells them. A plain `product_id` naming one is still refused by CheckoutService as an inactive product.
 */
final class MealLineResolver
{
    public function __construct(private readonly MenuPriceResolver $prices) {}

    /**
     * @param  array{meal_id: string, choices?: list<array{group_id: string, option_id: string}>, addons?: list<string>}  $selection
     */
    public function resolve(string $storeId, array $selection, string $quantity, CarbonInterface $soldAt): ResolvedMeal
    {
        $meal = Meal::where('store_id', $storeId)
            ->with(['menuGroup.parent', 'backingProduct', 'optionGroups.options.dish.dishCategory', 'optionGroups.options.addonProduct'])
            ->find($selection['meal_id']);

        if ($meal === null || ! $meal->active || ! MenuGroupPath::isVisible($meal->menuGroup)) {
            throw MealNotAvailableException::because('This meal is not on the menu.', ['meal_id' => $selection['meal_id']]);
        }
        if ($meal->backingProduct === null || $meal->backingProduct->store_id !== $storeId) {
            throw MealNotAvailableException::because('This meal cannot be sold: it has no product behind it.', ['meal_id' => $meal->id]);
        }
        if (bccomp($quantity, '1', 3) !== 0) {
            throw MealSelectionInvalidException::because('A meal line is one plate: send each plate as its own line.', ['meal_id' => $meal->id, 'quantity' => $quantity]);
        }

        $picks = $this->picks($meal, $selection);

        foreach ($meal->optionGroups as $group) {
            $count = count($picks[$group->id] ?? []);
            if (! $group->active) {
                if ($count > 0) {
                    throw MealNotAvailableException::because("\"{$group->name}\" is not on the menu.", ['meal_id' => $meal->id, 'group_id' => $group->id]);
                }

                continue;
            }
            if ($count < $group->min_select || $count > $group->max_select) {
                throw MealSelectionInvalidException::because(
                    "Choose between {$group->min_select} and {$group->max_select} for \"{$group->name}\".",
                    ['meal_id' => $meal->id, 'group_id' => $group->id, 'min_select' => $group->min_select, 'max_select' => $group->max_select, 'selected' => $count],
                );
            }
        }

        $mealPrice = null;
        $mealSelections = [];
        $dishNames = [];
        $sortOrder = 0;
        $addOns = [];
        $path = MenuGroupPath::of($meal->menuGroup);

        foreach ($meal->optionGroups as $group) {
            foreach ($picks[$group->id] ?? [] as $option) {
                $this->assertOfferable($meal, $group, $option);

                if ($group->kind === MealOptionGroup::CHOICE) {
                    $price = null;
                    if ($group->prices_the_meal) {
                        $row = $this->prices->mealPrice($meal->id, $option->dish->dish_category_id, $soldAt)
                            ?? throw MealNotAvailableException::because(
                                "\"{$option->dish->name}\" has no price right now.",
                                ['meal_id' => $meal->id, 'dish_id' => $option->dish->id, 'dish_category_id' => $option->dish->dish_category_id],
                            );
                        $price = $mealPrice = Money::fromApiString((string) $row->amount);
                    }
                    $dishNames[] = $option->dish->name;
                    $mealSelections[] = [
                        'group_name' => $group->name,
                        'option_label' => $option->dish->name,
                        'dish_id' => $option->dish->id,
                        'dish_name' => $option->dish->name,
                        'dish_category_name' => $option->dish->dishCategory->name,
                        'price' => $price,
                        'sort_order' => $sortOrder++,
                        // ADR-017 section 5: a chosen dish that counts daily stock draws the option's portion from the dish's pool.
                        'stock_product_id' => $option->dish->stock_mode === 'DAILY' ? $option->dish->stock_product_id : null,
                        'stock_portion' => $option->dish->stock_mode === 'DAILY' && $option->dish->stock_product_id !== null ? bcadd((string) $option->stock_portion, '0', 3) : null,
                    ];

                    continue;
                }

                $row = $this->prices->addonPrice($option->id, $soldAt)
                    ?? throw MealNotAvailableException::because("\"{$option->label}\" has no price right now.", ['meal_id' => $meal->id, 'meal_option_id' => $option->id]);
                $addOnPrice = Money::fromApiString((string) $row->amount);
                $addOns[] = new ResolvedMealLine(
                    product: $option->addonProduct,
                    unitPrice: $addOnPrice,
                    description: $option->label,
                    mealId: $meal->id,
                    mealName: $meal->name,
                    menuGroupPath: $path,
                    selections: [[
                        'group_name' => $group->name,
                        'option_label' => $option->label,
                        'dish_id' => null,
                        'dish_name' => null,
                        'dish_category_name' => null,
                        'price' => $addOnPrice,
                        'sort_order' => 0,
                        'stock_product_id' => null,
                        'stock_portion' => null,
                    ]],
                );
            }
        }

        if ($mealPrice === null) {
            throw MealNotAvailableException::because('This meal has no price right now.', ['meal_id' => $meal->id]);
        }

        return new ResolvedMeal(
            new ResolvedMealLine(
                product: $meal->backingProduct,
                unitPrice: $mealPrice,
                description: $meal->name.' - '.implode(' + ', $dishNames),
                mealId: $meal->id,
                mealName: $meal->name,
                menuGroupPath: $path,
                selections: $mealSelections,
            ),
            $addOns,
        );
    }

    /**
     * The options picked, by group id. A choice must be sent under the CHOICE group it belongs to and an add-on must be an
     * option of an ADDON group; an id the meal does not own is an invalid selection, never a lookup of someone else's data.
     *
     * @param  array{choices?: list<array{group_id: string, option_id: string}>, addons?: list<string>}  $selection
     * @return array<string, list<MealOption>>
     */
    private function picks(Meal $meal, array $selection): array
    {
        $groups = $meal->optionGroups->keyBy('id');
        $options = [];
        foreach ($meal->optionGroups as $group) {
            foreach ($group->options as $option) {
                $options[$option->id] = $option;
            }
        }

        $picked = [];
        $take = function (MealOption $option) use (&$picked, $meal): void {
            if (isset($picked[$option->id])) {
                throw MealSelectionInvalidException::because('An option was chosen more than once.', ['meal_id' => $meal->id, 'meal_option_id' => $option->id]);
            }
            $picked[$option->id] = $option;
        };

        foreach ($selection['choices'] ?? [] as $choice) {
            $group = $groups->get($choice['group_id']);
            $option = $options[$choice['option_id']] ?? null;
            if ($group === null || $option === null || $option->meal_option_group_id !== $group->id || $group->kind !== MealOptionGroup::CHOICE) {
                throw MealSelectionInvalidException::because('A choice does not belong to this meal.', ['meal_id' => $meal->id, 'group_id' => $choice['group_id'], 'meal_option_id' => $choice['option_id']]);
            }
            $take($option);
        }
        foreach ($selection['addons'] ?? [] as $optionId) {
            $option = $options[$optionId] ?? null;
            if ($option === null || $groups[$option->meal_option_group_id]->kind !== MealOptionGroup::ADDON) {
                throw MealSelectionInvalidException::because('An add-on does not belong to this meal.', ['meal_id' => $meal->id, 'meal_option_id' => $optionId]);
            }
            $take($option);
        }

        // Group order, then the option order inside the group: the order lines and selections are written in, whatever order the client sent.
        $byGroup = [];
        foreach ($meal->optionGroups as $group) {
            foreach ($group->options as $option) {
                if (isset($picked[$option->id])) {
                    $byGroup[$group->id][] = $option;
                }
            }
        }

        return $byGroup;
    }

    private function assertOfferable(Meal $meal, MealOptionGroup $group, MealOption $option): void
    {
        $context = ['meal_id' => $meal->id, 'meal_option_id' => $option->id];
        $label = $option->dish?->name ?? $option->label;

        if (! $option->active) {
            throw MealNotAvailableException::because("\"{$label}\" is not on the menu.", $context);
        }
        if ($group->kind === MealOptionGroup::CHOICE && (! $option->dish->active || ! $option->dish->dishCategory->active)) {
            throw MealNotAvailableException::because("\"{$label}\" is not on the menu.", $context + ['dish_id' => $option->dish->id]);
        }
        if ($group->kind === MealOptionGroup::ADDON && ($option->addonProduct === null || $option->addonProduct->store_id !== $meal->store_id)) {
            throw MealNotAvailableException::because("\"{$label}\" cannot be sold: it has no product behind it.", $context);
        }
    }
}
