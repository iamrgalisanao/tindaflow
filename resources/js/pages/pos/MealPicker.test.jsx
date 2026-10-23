import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import MealPicker from './MealPicker';
import { mealMenus } from './mealMenus';

afterEach(cleanup);

const menu = mealMenus[0];
const productsBySku = {
    'MEAL-STD-BEEF': { id: 'p1', sku: 'MEAL-STD-BEEF', name: 'Standard Meal - Beef', selling_price: '68.00' },
    'MEAL-STD-OTHER': { id: 'p2', sku: 'MEAL-STD-OTHER', name: 'Standard Meal - Chicken/Pork/Fish', selling_price: '60.00' },
};

describe('MealPicker', () => {
    it('only offers the add button once a protein and a dish are chosen, and adds the protein product with the dish note', () => {
        const onAdd = vi.fn();
        render(<MealPicker menu={menu} productsBySku={productsBySku} onAdd={onAdd} onCancel={() => {}} />);

        const add = screen.getByRole('button', { name: 'Choose a protein and a dish' });
        expect(add.disabled).toBe(true);
        expect(screen.queryByText('Beef caldereta')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /^Beef/ }));
        expect(screen.getByText('Beef caldereta')).toBeTruthy();
        expect(screen.queryByText('Chicken adobo')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Beef caldereta' }));
        fireEvent.click(screen.getByRole('button', { name: 'Add to order ₱68.00' }));

        expect(onAdd).toHaveBeenCalledWith(productsBySku['MEAL-STD-BEEF'], 'Beef caldereta · Beef');
    });

    it('changing the protein clears the chosen dish', () => {
        render(<MealPicker menu={menu} productsBySku={productsBySku} onAdd={() => {}} onCancel={() => {}} />);
        fireEvent.click(screen.getByRole('button', { name: /^Beef/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Beef nilaga' }));
        fireEvent.click(screen.getByRole('button', { name: /^Chicken/ }));
        expect(screen.getByRole('button', { name: 'Choose a protein and a dish' }).disabled).toBe(true);
    });

    it('disables a protein whose catalog product does not exist', () => {
        render(<MealPicker menu={menu} productsBySku={{}} onAdd={() => {}} onCancel={() => {}} />);
        expect(screen.getAllByText('Not in catalog')).toHaveLength(2);
    });
});
