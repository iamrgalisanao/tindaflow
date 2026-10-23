/**
 * Meal menus the register offers as a two-step picker (choose the protein, then the dish) instead of one tile per dish.
 *
 * This is a register-only presentation layer: each protein points at an ordinary catalog product by SKU, so the price and
 * the sale line come from that product and the server is unchanged. The dish the cashier picks travels with the cart line
 * as a note for the screen; it is not sent to the server, so it is not on the receipt or in reports.
 *
 * @type {Array<{id: string, name: string, description: string, proteins: Array<{label: string, sku: string, dishes: string[]}>}>}
 */
const beefDishes = ['Beef caldereta', 'Beef nilaga', 'Beef steak'];
const otherDishes = ['Chicken adobo', 'Pork sinigang', 'Fried fish', 'Pork menudo'];

export const mealMenus = [
    {
        id: 'standard-meal',
        name: 'Standard Meal',
        description: 'Viand + rice',
        proteins: [
            { label: 'Beef', sku: 'MEAL-STD-BEEF', dishes: beefDishes },
            { label: 'Chicken / Pork / Fish', sku: 'MEAL-STD-OTHER', dishes: otherDishes },
        ],
    },
    {
        id: 'complete-meal',
        name: 'Complete Meal',
        description: 'Viand + vegetable + rice',
        proteins: [
            { label: 'Beef', sku: 'MEAL-CMP-BEEF', dishes: beefDishes },
            { label: 'Chicken / Pork / Fish', sku: 'MEAL-CMP-OTHER', dishes: otherDishes },
        ],
    },
];

export const mealSkus = mealMenus.flatMap((menu) => menu.proteins.map((protein) => protein.sku));
