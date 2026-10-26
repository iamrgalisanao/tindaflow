import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ProductFormPanel from './ProductFormPanel';

// ADR-017 slice C: the rough shelf life is one optional whole-number field on a running-stock product; blank means none.
const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));

const base = { id: 'p1', sku: 'BRW-1', name: 'Brownies', unit_of_measure: 'pc', selling_price: '35.00', tax_class: 'VATABLE', track_inventory: true, stock_mode: 'RUNNING', reorder_level: null, active: true };

let calls;
beforeEach(() => {
    calls = [];
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options = {}) => {
            calls.push({ url, method: options.method ?? 'GET', body: options.body ? JSON.parse(options.body) : undefined });
            return json(options.body ? { ...base, ...JSON.parse(options.body) } : { data: [], meta: { last_page: 1 } });
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const open = (product = base, onSaved = () => {}) =>
    render(<ProductFormPanel product={product} categories={[]} brands={[]} onSaved={onSaved} onClose={() => {}} onUnauthorized={() => {}} onCategoryCreated={() => {}} onBrandCreated={() => {}} />);
const saves = () => calls.filter((call) => call.method === 'PATCH');

describe('ProductFormPanel shelf life', () => {
    it('shows the field with explanatory help, blank when the product has none', () => {
        open();

        const field = screen.getByLabelText('Shelf life (days)');
        expect(field.value).toBe('');
        expect(field.getAttribute('placeholder')).toBe('No shelf life');
        expect(screen.getByText(/Check freshness/).textContent).toContain('never stops a sale');
    });

    it('shows the saved value and sends a number; clearing it sends null', async () => {
        const onSaved = vi.fn();
        open({ ...base, shelf_life_days: 3 }, onSaved);
        const field = screen.getByLabelText('Shelf life (days)');
        expect(field.value).toBe('3');

        fireEvent.change(field, { target: { value: '2' } });
        fireEvent.click(screen.getByRole('button', { name: /save/i }));
        await waitFor(() => expect(saves()).toHaveLength(1));
        expect(saves()[0].body.shelf_life_days).toBe(2);

        fireEvent.change(field, { target: { value: '' } });
        fireEvent.click(screen.getByRole('button', { name: /save/i }));
        await waitFor(() => expect(saves()).toHaveLength(2));
        expect(saves()[1].body.shelf_life_days).toBeNull();
    });

    it.each(['0', '-1', '2.5', 'abc', '3651'])('refuses %s without calling the server', async (bad) => {
        open();

        fireEvent.change(screen.getByLabelText('Shelf life (days)'), { target: { value: bad } });
        fireEvent.click(screen.getByRole('button', { name: /save/i }));

        expect((await screen.findByRole('alert')).textContent).toContain('whole number of days from 1 to 3650');
        expect(saves()).toHaveLength(0);
    });

    it('is not offered for a daily product or one that is not counted', () => {
        const { unmount } = open({ ...base, stock_mode: 'DAILY' });
        expect(screen.queryByLabelText('Shelf life (days)')).toBeNull();
        unmount();

        open({ ...base, track_inventory: false, stock_mode: 'NONE' });
        expect(screen.queryByLabelText('Shelf life (days)')).toBeNull();
    });
});
