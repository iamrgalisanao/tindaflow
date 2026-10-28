import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import TenderPanel from './TenderPanel';

// The quick-cash chips add up, and the keypad starts a new amount when the current one was pre-filled or built by chips.

const METHODS = ['CASH', 'GCASH', 'MAYA', 'CARD', 'OTHER'];

function Harness({ total = 6800, initial = '68.00', onComplete = vi.fn() }) {
    const [payments, setPayments] = useState([{ method: 'CASH', amount: initial }]);
    const cart = [{ key: 'a', quantity: '1', discount: '', product: { name: 'Item', selling_price: '68.00' } }];

    return (
        <TenderPanel
            cart={cart}
            totalCents={BigInt(total)}
            methods={METHODS}
            payments={payments}
            onPayments={setPayments}
            busy={false}
            onBack={() => {}}
            onComplete={(event) => {
                event.preventDefault();
                onComplete();
            }}
        />
    );
}

const box = () => screen.getByLabelText(/^Amount tendered/);
const tap = (name) => fireEvent.click(screen.getByRole('button', { name }));
const chip = (value) => tap(`₱${value.toLocaleString('en-US')}`);
const keys = (...list) => list.forEach((key) => tap(key));
const completeButton = () => screen.getByRole('button', { name: /Complete sale|Short by|Enter the amount/ });

afterEach(cleanup);

describe('TenderPanel amount entry', () => {
    it('shows the pre-filled exact total', () => {
        render(<Harness />);
        expect(box().value).toBe('68.00');
    });

    it('replaces the pre-filled amount with the first chip', () => {
        render(<Harness />);
        chip(50);
        expect(box().value).toBe('50.00');
    });

    it('adds chips together', () => {
        render(<Harness />);
        chip(50);
        chip(20);
        expect(box().value).toBe('70.00');
        cleanup();
        render(<Harness />);
        chip(50);
        chip(50);
        chip(100);
        expect(box().value).toBe('200.00');
    });

    it('adds a chip to typed digits', () => {
        render(<Harness />);
        keys('5');
        chip(50);
        expect(box().value).toBe('55.00');
    });

    it('starts a new entry with the first digit on a pre-filled amount', () => {
        render(<Harness />);
        keys('5');
        expect(box().value).toBe('5');
        keys('9');
        expect(box().value).toBe('59');
    });

    it('starts a new entry with the first dot on a pre-filled amount', () => {
        render(<Harness />);
        keys('.', '5');
        expect(box().value).toBe('0.5');
    });

    it('starts a new entry with the first digit after chips', () => {
        render(<Harness />);
        chip(50);
        chip(20);
        keys('3');
        expect(box().value).toBe('3');
        keys('0');
        expect(box().value).toBe('30');
    });

    it('adds a chip to zero after Clear', () => {
        render(<Harness />);
        chip(50);
        tap('Clear');
        expect(box().value).toBe('');
        chip(20);
        expect(box().value).toBe('20.00');
    });

    it('lets Exact be replaced by the next chip or digit', () => {
        render(<Harness />);
        chip(500);
        tap('Exact');
        expect(box().value).toBe('68.00');
        chip(20);
        expect(box().value).toBe('20.00');
        tap('Exact');
        keys('7');
        expect(box().value).toBe('7');
    });

    it('removes the last character with backspace', () => {
        render(<Harness />);
        keys('1', '2', '3');
        tap('Delete the last digit');
        expect(box().value).toBe('12');
        cleanup();
        render(<Harness />);
        tap('Delete the last digit');
        expect(box().value).toBe('68.0');
    });

    it('types decimals with at most two places, and the 00 key', () => {
        render(<Harness />);
        keys('1', '.', '2', '5', '9');
        expect(box().value).toBe('1.25');
        cleanup();
        render(<Harness />);
        keys('5', '00');
        expect(box().value).toBe('500');
        keys('.', '.');
        expect(box().value).toBe('500.');
    });

    it('never produces an invalid amount past ten whole digits', () => {
        render(<Harness />);
        keys('9', '9', '9', '9', '9', '9', '9', '9', '9', '9', '9');
        expect(box().value).toBe('9,999,999,999');
        chip(20);
        expect(box().value).toBe('9,999,999,999');
        cleanup();
        render(<Harness />);
        chip(1000);
        expect(box().value).toBe('1,000.00');
    });

    it('shows change due and enables Complete sale for 50 + 20 against a 60 total', () => {
        const onComplete = vi.fn();
        render(<Harness total={6000} initial="60.00" onComplete={onComplete} />);
        chip(50);
        expect(completeButton().disabled).toBe(true);
        expect(screen.getByText('Short by ₱10.00')).toBeTruthy();
        chip(20);
        expect(screen.getByText('Change due')).toBeTruthy();
        expect(screen.getByText('₱10.00')).toBeTruthy();
        expect(completeButton().disabled).toBe(false);
        fireEvent.click(completeButton());
        expect(onComplete).toHaveBeenCalledTimes(1);
    });

    it('charges the whole total for a single non-cash row, without typing', () => {
        render(<Harness />);
        chip(20);
        fireEvent.click(screen.getByRole('radio', { name: 'GCash' }));
        expect(box().value).toBe('68.00');
        expect(completeButton().disabled).toBe(false);
        keys('4');
        expect(box().value).toBe('4');
    });

    it('treats a split payment row as fresh and independent', () => {
        render(<Harness />);
        chip(50);
        tap('Split payment');
        expect(box().value).toBe('18.00');
        chip(20);
        expect(box().value).toBe('20.00');
        chip(50);
        expect(box().value).toBe('70.00');
        const rows = screen.getByRole('list', { name: 'Payment rows' });
        const [first, second] = rows.querySelectorAll('li');
        expect(first.textContent).toContain('₱50.00');
        expect(second.textContent).toContain('₱70.00');
        fireEvent.click(first.querySelector('button'));
        expect(box().value).toBe('50.00');
        chip(20);
        expect(box().value).toBe('70.00');
        expect(second.textContent).toContain('₱70.00');
    });

    it('starts a fresh second row with its first digit', () => {
        render(<Harness />);
        chip(50);
        tap('Split payment');
        keys('9');
        expect(box().value).toBe('9');
    });
});
