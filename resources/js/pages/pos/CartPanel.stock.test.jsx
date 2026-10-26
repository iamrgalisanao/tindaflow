import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CartPanel from './CartPanel';
import { stockFromResponse } from './stock';

afterEach(cleanup);

// ADR-017 slice A: a cart line shows what is left, goes amber when the cart leaves "3 or fewer" and red when the cart asks for more than is there.
const stock = stockFromResponse({
    blocking: true,
    override_available: true,
    low_stock_default_threshold: 3,
    items: [
        { product_id: 'soda', remaining: '10.000', threshold: 3 },
        { product_id: 'juice', remaining: '5.000', threshold: 3 },
        { product_id: 'none', remaining: '0.000', threshold: 3 },
    ],
});
const product = (id, name) => ({ id, name, selling_price: '10.00', unit_of_measure: 'pc' });
const line = (id, name, quantity) => ({ key: id, product: product(id, name), quantity, discount: '' });

const props = (cart, extra = {}) => ({
    cart, subtotalCents: 1000n, discount: '', onDiscountChange: () => {}, onLineDiscountChange: () => {}, canDiscount: false,
    statutoryDiscount: { enabled: false, type: 'SENIOR_CITIZEN', rule: 'STANDARD_20', idNumber: '', name: '', weeklyUsed: '' },
    onStatutoryDiscountChange: () => {}, statutoryDiscountIncomplete: false, totalCents: 1000n, hasInvalidLine: false,
    onQuantity: () => {}, onRemove: () => {}, onCharge: () => {}, stock, ...extra,
});

describe('CartPanel stock lines', () => {
    it('shows the count in stock when there is plenty, amber when the cart leaves 3 or fewer, red when it asks for more than is there', () => {
        render(<CartPanel {...props([line('soda', 'Soda', '2'), line('juice', 'Juice', '3'), line('none', 'Pandesal', '1')])} />);

        expect(screen.getByText('10 in stock').getAttribute('data-stock')).toBe('ok');
        expect(screen.getByText('2 left after this sale.').getAttribute('data-stock')).toBe('low');
        const over = screen.getByRole('alert');
        expect(over.textContent).toContain('No stock.');
        expect(over.textContent).toContain('ask a manager to approve');
    });

    it('says "Only N left" when the cart asks for more than there is', () => {
        render(<CartPanel {...props([line('juice', 'Juice', '6')])} />);

        expect(screen.getByRole('alert').textContent).toContain('Only 5 left.');
    });

    it('adds up two lines of the same product against what is there', () => {
        render(<CartPanel {...props([line('juice', 'Juice', '3'), { ...line('juice', 'Juice', '3'), key: 'second' }])} />);

        expect(screen.getAllByRole('alert')).toHaveLength(2);
    });

    it('marks nothing on an untracked line, and a meal line has no stock line', () => {
        render(<CartPanel {...props([line('service', 'Printing', '4')])} />);

        expect(document.querySelector('[data-stock]')).toBeNull();
    });

    it('hands the "+" to the register when it is asked to, so an add the stock cannot cover can be refused', () => {
        const onIncrease = vi.fn();
        const onQuantity = vi.fn();
        const cart = [line('juice', 'Juice', '5')];
        render(<CartPanel {...props(cart, { onIncrease, onQuantity })} />);

        fireEvent.click(screen.getByRole('button', { name: 'One more Juice' }));

        expect(onIncrease).toHaveBeenCalledWith(cart[0]);
        expect(onQuantity).not.toHaveBeenCalled();
    });

    it('without a stock read, the cart looks and behaves as it always did', () => {
        const onQuantity = vi.fn();
        render(<CartPanel {...props([line('juice', 'Juice', '5')], { stock: undefined, onQuantity })} />);

        expect(document.querySelector('[data-stock]')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'One more Juice' }));
        expect(onQuantity).toHaveBeenCalledWith('juice', '6');
    });
});
