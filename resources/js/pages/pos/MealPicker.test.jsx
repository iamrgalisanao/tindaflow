import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import MealPicker from './MealPicker';

afterEach(cleanup);

const categories = [
    { id: 'beef', code: 'BEEF', name: 'Beef', sort_order: 1 },
    { id: 'cpf', code: 'CPF', name: 'Chicken/Pork/Fish', sort_order: 2 },
    { id: 'veg', code: 'VEG', name: 'Vegetable', sort_order: 3 },
];
const dish = (id, name, categoryId, price) => ({ id: `o-${id}`, label: name, sort_order: 1, price, dish: { id, name, dish_category_id: categoryId } });
const rice = {
    id: 'g-rice', name: 'Extra rice', kind: 'ADDON', min_select: 0, max_select: 1, prices_the_meal: false, popup_after_choice: true, step_order: 2,
    options: [
        { id: 'o-whole', label: 'Whole rice', sort_order: 1, price: '15.00', dish: null },
        { id: 'o-half', label: 'Half rice', sort_order: 2, price: '10.00', dish: null },
    ],
};

const standard = {
    id: 'm-std', name: 'Standard Meal', menu_group_id: null, menu_group_path: null,
    prices: [{ dish_category_id: 'beef', amount: '68.00' }, { dish_category_id: 'cpf', amount: '60.00' }],
    option_groups: [
        {
            id: 'g-viand', name: 'Viand', kind: 'CHOICE', min_select: 1, max_select: 1, prices_the_meal: true, popup_after_choice: false, step_order: 1,
            options: [dish('nilaga', 'Beef nilaga', 'beef', '68.00'), dish('caldereta', 'Beef caldereta', 'beef', '68.00'), dish('adobo', 'Chicken adobo', 'cpf', '60.00')],
        },
        rice,
    ],
};

const combo = {
    id: 'm-combo', name: 'Combo Meal', menu_group_id: null, menu_group_path: null,
    prices: [{ dish_category_id: 'beef', amount: '85.00' }, { dish_category_id: 'cpf', amount: '80.00' }],
    option_groups: [
        {
            id: 'g-main', name: 'Main', kind: 'CHOICE', min_select: 1, max_select: 1, prices_the_meal: true, popup_after_choice: false, step_order: 1,
            options: [dish('c-nilaga', 'Beef nilaga', 'beef', '85.00'), dish('c-adobo', 'Chicken adobo', 'cpf', '80.00')],
        },
        {
            id: 'g-side', name: 'Side', kind: 'CHOICE', min_select: 1, max_select: 1, prices_the_meal: false, popup_after_choice: false, step_order: 2,
            options: [dish('pinakbet', 'Pinakbet', 'veg', null), dish('chopsuey', 'Chopsuey', 'veg', null)],
        },
        { ...rice, step_order: 3 },
    ],
};

const open = (meal, props = {}) => {
    const onAdd = vi.fn();
    render(<MealPicker meal={meal} categories={categories} onAdd={onAdd} onCancel={() => {}} {...props} />);
    return onAdd;
};

describe('MealPicker, a priced-by-category meal', () => {
    it('goes category (with its price) -> dish -> rice pop-up, and the Add button shows the total', () => {
        const onAdd = open(standard);

        const add = screen.getByRole('button', { name: 'Choose viand' });
        expect(add.disabled).toBe(true);
        expect(screen.queryByText('Beef nilaga')).toBeNull();
        expect(screen.getByRole('button', { name: /^Beef.*68\.00/ })).toBeTruthy();
        expect(screen.getByRole('button', { name: /^Chicken\/Pork\/Fish.*60\.00/ })).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Vegetable/ })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /^Beef/ }));
        expect(screen.getByRole('button', { name: 'Beef caldereta' })).toBeTruthy();
        expect(screen.queryByText('Chicken adobo')).toBeNull();
        expect(screen.queryByRole('dialog', { name: 'Extra rice' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Beef nilaga' }));
        const popup = screen.getByRole('dialog', { name: 'Extra rice' });
        expect(within(popup).getByRole('button', { name: /Whole rice.*15\.00/ })).toBeTruthy();
        expect(within(popup).getByRole('button', { name: /Half rice.*10\.00/ })).toBeTruthy();

        fireEvent.click(within(popup).getByRole('button', { name: /Whole rice/ }));
        expect(screen.queryByRole('dialog', { name: 'Extra rice' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Add to order ₱83.00' }));

        expect(onAdd).toHaveBeenCalledTimes(1);
        const line = onAdd.mock.calls[0][0];
        expect(line.key).toBe('meal|m-std|o-nilaga|o-whole');
        expect(line.product).toEqual({ id: 'meal:m-std', name: 'Standard Meal - Beef nilaga', selling_price: '83.00', unit_of_measure: 'order' });
        expect(line.meal.choices).toEqual([{ group_id: 'g-viand', group_name: 'Viand', option_id: 'o-nilaga', label: 'Beef nilaga', dish_id: 'nilaga', stock_portion: null }]);
        expect(line.meal.addons.map((addon) => addon.option_id)).toEqual(['o-whole']);
    });

    it('lets the rice be skipped, and adds the meal alone at its own price', () => {
        const onAdd = open(standard);
        fireEvent.click(screen.getByRole('button', { name: /^Chicken/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Chicken adobo' }));

        fireEvent.click(within(screen.getByRole('dialog', { name: 'Extra rice' })).getByRole('button', { name: 'Skip' }));
        expect(screen.queryByRole('dialog', { name: 'Extra rice' })).toBeNull();
        expect(screen.getByText(/Extra rice:/).textContent).toContain('none');

        fireEvent.click(screen.getByRole('button', { name: 'Add to order ₱60.00' }));
        expect(onAdd.mock.calls[0][0].key).toBe('meal|m-std|o-adobo|');
        expect(onAdd.mock.calls[0][0].meal.addons).toEqual([]);
    });

    it('can reopen the rice pop-up with Change, and does not pop it up again after a skip', () => {
        open(standard);
        fireEvent.click(screen.getByRole('button', { name: /^Beef/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Beef nilaga' }));
        fireEvent.click(within(screen.getByRole('dialog', { name: 'Extra rice' })).getByRole('button', { name: 'Skip' }));

        fireEvent.click(screen.getByRole('button', { name: 'Beef caldereta' }));
        expect(screen.queryByRole('dialog', { name: 'Extra rice' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Change' }));
        fireEvent.click(within(screen.getByRole('dialog', { name: 'Extra rice' })).getByRole('button', { name: /Half rice/ }));
        expect(screen.getByRole('button', { name: 'Add to order ₱78.00' })).toBeTruthy();
    });

    it('changing the category clears the chosen dish and disables Add again', () => {
        open(standard);
        fireEvent.click(screen.getByRole('button', { name: /^Beef/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Beef nilaga' }));
        fireEvent.click(within(screen.getByRole('dialog', { name: 'Extra rice' })).getByRole('button', { name: 'Skip' }));
        expect(screen.getByRole('button', { name: 'Add to order ₱68.00' }).disabled).toBe(false);

        fireEvent.click(screen.getByRole('button', { name: /^Chicken/ }));
        expect(screen.getByRole('button', { name: 'Choose viand' }).disabled).toBe(true);
    });
});

describe('MealPicker, a combo of a main and a side', () => {
    it('shows the groups in step order, needs both, prices from the main, and asks about rice only once both are chosen', () => {
        const onAdd = open(combo);
        expect(screen.getAllByText(/Main · category|Side/).map((node) => node.textContent)[0]).toContain('Main');
        expect(screen.getByRole('button', { name: 'Choose main and side' }).disabled).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: /^Beef/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Beef nilaga' }));
        expect(screen.queryByRole('dialog', { name: 'Extra rice' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Choose side' }).disabled).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: 'Pinakbet' }));
        fireEvent.click(within(screen.getByRole('dialog', { name: 'Extra rice' })).getByRole('button', { name: 'Skip' }));
        fireEvent.click(screen.getByRole('button', { name: 'Add to order ₱85.00' }));

        const line = onAdd.mock.calls[0][0];
        expect(line.product.name).toBe('Combo Meal - Beef nilaga + Pinakbet');
        expect(line.meal.choices.map((choice) => choice.option_id)).toEqual(['o-c-nilaga', 'o-pinakbet']);
    });

    it('swaps the side when another is tapped', () => {
        const onAdd = open(combo);
        fireEvent.click(screen.getByRole('button', { name: /^Chicken/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Chicken adobo' }));
        fireEvent.click(screen.getByRole('button', { name: 'Pinakbet' }));
        fireEvent.click(within(screen.getByRole('dialog', { name: 'Extra rice' })).getByRole('button', { name: 'Skip' }));
        fireEvent.click(screen.getByRole('button', { name: 'Chopsuey' }));

        fireEvent.click(screen.getByRole('button', { name: 'Add to order ₱80.00' }));
        expect(onAdd.mock.calls[0][0].meal.choices.map((choice) => choice.label)).toEqual(['Chicken adobo', 'Chopsuey']);
    });
});

describe('MealPicker, leaving', () => {
    it('Back calls onCancel and adds nothing', () => {
        const onCancel = vi.fn();
        const onAdd = open(standard, { onCancel });
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(onCancel).toHaveBeenCalled();
        expect(onAdd).not.toHaveBeenCalled();
    });
});

// ADR-017 slice B: a dish that counts daily stock shows the portions left in the picker, and a sold-out dish cannot be picked when the store blocks.
describe('MealPicker with daily stock', () => {
    const stockOf = (blocking = true) => ({
        loaded: true,
        blocking,
        overrideAvailable: true,
        defaultThreshold: 3,
        byProduct: {},
        dishes: { nilaga: { remaining: 3000n, name: 'Beef nilaga' }, caldereta: { remaining: 0n, name: 'Beef caldereta' } },
    });
    const dailyStandard = {
        ...standard,
        option_groups: [
            {
                ...standard.option_groups[0],
                options: [
                    { ...dish('nilaga', 'Beef nilaga', 'beef', '68.00'), stock_portion: '1.000' },
                    { ...dish('caldereta', 'Beef caldereta', 'beef', '68.00'), stock_portion: '1.000' },
                    dish('adobo', 'Chicken adobo', 'cpf', '60.00'),
                ],
            },
            rice,
        ],
    };

    it('shows "3 left" and "Sold out" on the dishes and greys the sold-out one when the store blocks', () => {
        open(dailyStandard, { stock: stockOf(), cart: [] });
        fireEvent.click(screen.getByRole('button', { name: /Beef/ }));

        expect(screen.getByText('3 left').getAttribute('data-stock')).toBe('ok');
        const soldOut = screen.getByText('Sold out');
        expect(soldOut.getAttribute('data-stock')).toBe('out');
        expect(soldOut.closest('button').disabled).toBe(true);
        expect(screen.getByRole('button', { name: /Beef nilaga/ }).disabled).toBe(false);
    });

    it('takes what the cart already holds away from the count, and does not grey a dish when the store does not block', () => {
        const plate = { key: 'p', product: { id: 'meal:m' }, quantity: '2', meal: { choices: [{ dish_id: 'nilaga', stock_portion: '1.000' }], addons: [] } };
        open(dailyStandard, { stock: stockOf(false), cart: [plate] });
        fireEvent.click(screen.getByRole('button', { name: /Beef/ }));

        expect(screen.getByText('1 left')).toBeTruthy();
        expect(screen.getByRole('button', { name: /Beef caldereta/ }).disabled).toBe(false);
    });
});
