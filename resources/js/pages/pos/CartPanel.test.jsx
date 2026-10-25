import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CartPanel from './CartPanel';
import { buildMealLine } from './mealMenu';

afterEach(cleanup);

const meal = {
    id: 'm1', name: 'Standard Meal', prices: [],
    option_groups: [
        { id: 'g1', name: 'Viand', kind: 'CHOICE', min_select: 1, max_select: 1, prices_the_meal: true, popup_after_choice: false, step_order: 1, options: [
            { id: 'o1', label: 'Beef nilaga', price: '68.00', dish: { id: 'd1', name: 'Beef nilaga', dish_category_id: 'beef' } },
            { id: 'o3', label: 'Beef caldereta', price: '68.00', dish: { id: 'd3', name: 'Beef caldereta', dish_category_id: 'beef' } },
        ] },
        { id: 'g2', name: 'Extra rice', kind: 'ADDON', min_select: 0, max_select: 1, prices_the_meal: false, popup_after_choice: true, step_order: 2, options: [{ id: 'o2', label: 'Whole rice', price: '15.00', dish: null }] },
    ],
};

const props = (cart, extra = {}) => ({
    cart, subtotalCents: 8300n, discount: '', onDiscountChange: () => {}, onLineDiscountChange: () => {}, canDiscount: true,
    statutoryDiscount: { enabled: false, type: 'SENIOR_CITIZEN', rule: 'STANDARD_20', idNumber: '', name: '', weeklyUsed: '' },
    onStatutoryDiscountChange: () => {}, statutoryDiscountIncomplete: false, totalCents: 8300n, hasInvalidLine: false,
    onQuantity: () => {}, onRemove: () => {}, onCharge: () => {}, ...extra,
});

describe('CartPanel meal lines', () => {
    it('shows the meal, its dish and add-ons under it, with no per-line discount box', () => {
        const line = { ...buildMealLine(meal, { g1: ['o1'], g2: ['o2'] }), quantity: '1', discount: '' };
        render(<CartPanel {...props([line])} />);

        expect(screen.getByText('Standard Meal - Beef nilaga')).toBeTruthy();
        const detail = screen.getByRole('list', { name: 'Standard Meal - Beef nilaga selection' });
        expect(detail.textContent).toContain('Beef nilaga');
        expect(detail.textContent).toContain('+ Whole rice ₱15.00');
        expect(screen.getByText(/@ ₱83.00 \/ order/)).toBeTruthy();
        expect(screen.queryByLabelText(/Discount on/)).toBeNull();
    });

    it('lets a long meal name wrap so the dish stays readable, while a product name still truncates', () => {
        const line = { ...buildMealLine(meal, { g1: ['o1'] }), quantity: '1', discount: '' };
        const product = { key: 'p1', product: { id: 'p1', name: 'Coke 330ml', selling_price: '25.00', unit_of_measure: 'can' }, quantity: '1', discount: '' };
        render(<CartPanel {...props([line, product])} />);

        const mealName = screen.getByText('Standard Meal - Beef nilaga');
        expect(mealName.className).toContain('break-words');
        expect(mealName.className).not.toContain('truncate');
        expect(screen.getByText('Coke 330ml').className).toContain('truncate');
    });

    it('two different dishes are two lines; the quantity steps like any line', () => {
        const a = { ...buildMealLine(meal, { g1: ['o1'] }), quantity: '1', discount: '' };
        const b = { ...buildMealLine(meal, { g1: ['o3'] }), quantity: '1', discount: '' };
        const onQuantity = vi.fn();
        render(<CartPanel {...props([a, b], { onQuantity })} />);

        expect(a.key).not.toBe(b.key);
        expect(screen.getAllByRole('listitem').filter((item) => item.textContent.includes('Standard Meal -'))).toHaveLength(2);
        fireEvent.click(screen.getByRole('button', { name: 'One more Standard Meal - Beef nilaga' }));
        expect(onQuantity).toHaveBeenCalledWith(a.key, '2');
    });

    it('flags a meal quantity that is not a whole number of plates', () => {
        const line = { ...buildMealLine(meal, { g1: ['o1'] }), quantity: '1.5', discount: '' };
        render(<CartPanel {...props([line], { hasInvalidLine: true })} />);

        expect(screen.getByLabelText('Quantity of Standard Meal - Beef nilaga').getAttribute('aria-invalid')).toBe('true');
        expect(screen.getByRole('alert').textContent).toContain('whole number from 1 to 99');
    });
});
