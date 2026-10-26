import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AlaCartePanel from './AlaCartePanel';

// ADR-017 slice B, client decision 2: "Create ala carte items for dishes". The manager sets the prices; nothing is assumed.
const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
const categories = [{ id: 'beef', name: 'Beef' }, { id: 'veg', name: 'Vegetable' }];
const dishes = [
    { id: 'n', name: 'Beef nilaga', dish_category_id: 'beef', active: true, stock_mode: 'DAILY', ala_carte_products: [] },
    { id: 'a', name: 'Beef adobo', dish_category_id: 'beef', active: true, stock_mode: 'DAILY', ala_carte_products: [] },
    { id: 'p', name: 'Pinakbet', dish_category_id: 'veg', active: true, stock_mode: 'DAILY', ala_carte_products: [{ id: 'x', name: 'Pinakbet (ala carte)' }] },
    { id: 'l', name: 'Laing', dish_category_id: 'veg', active: true, stock_mode: 'NONE', ala_carte_products: [] },
];

let calls;
beforeEach(() => {
    calls = [];
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options = {}) => {
            calls.push({ url, method: options.method ?? 'GET', body: options.body ? JSON.parse(options.body) : undefined });
            if (url.startsWith('/api/v1/menu-groups')) {
                return json({ data: [{ id: 'g1', name: 'Ala carte' }] });
            }
            if (url === '/api/v1/dishes/ala-carte') {
                return json({ created: [{ product_id: 'n1', sku: 'ALC-BEEF-NILAGA', name: 'Beef nilaga (ala carte)', selling_price: '120.00' }], skipped: [] }, 201);
            }
            return json({});
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const open = (onDone = () => {}) => render(<AlaCartePanel categories={categories} dishes={dishes} onDone={onDone} onCancel={() => {}} />);

describe('AlaCartePanel', () => {
    it('lists only the daily dishes that have no ala carte item yet, named "<Dish> (ala carte)"', () => {
        open();

        const list = screen.getByRole('list', { name: 'Dishes to create' });
        expect(list.textContent).toContain('Beef nilaga (ala carte)');
        expect(list.textContent).toContain('Beef adobo (ala carte)');
        expect(list.textContent).not.toContain('Pinakbet');
        expect(list.textContent).not.toContain('Laing');
    });

    it('will not create anything until every chosen dish has a price, from its category or of its own', async () => {
        open();
        const create = () => screen.getByRole('button', { name: /^Create \d item/ });
        expect(create().disabled).toBe(true);
        expect(screen.getByRole('status').textContent).toContain('Set a price for Beef nilaga, Beef adobo');

        fireEvent.change(screen.getByLabelText('Beef'), { target: { value: '120' } });
        expect(create().disabled).toBe(false);
        fireEvent.change(screen.getByLabelText('Price for Beef adobo'), { target: { value: '130.5' } });
        await screen.findByRole('option', { name: 'Ala carte' });
        fireEvent.change(screen.getByLabelText('Menu group (optional)'), { target: { value: 'g1' } });
        fireEvent.click(create());

        await waitFor(() => expect(calls.some((call) => call.url === '/api/v1/dishes/ala-carte')).toBe(true));
        const post = calls.find((call) => call.url === '/api/v1/dishes/ala-carte');
        expect(post.method).toBe('POST');
        expect(post.body).toEqual({ menu_group_id: 'g1', stock_portion: '2', items: [{ dish_id: 'n', price: '120.00' }, { dish_id: 'a', price: '130.50' }] });
        expect((await screen.findByRole('status')).textContent).toContain('1 ala carte item created');
    });

    it('leaves an unticked dish out', async () => {
        open();
        fireEvent.change(screen.getByLabelText('Beef'), { target: { value: '100' } });
        fireEvent.click(screen.getByRole('checkbox', { name: /Beef adobo/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Create 1 item' }));

        await waitFor(() => expect(calls.some((call) => call.url === '/api/v1/dishes/ala-carte')).toBe(true));
        expect(calls.find((call) => call.url === '/api/v1/dishes/ala-carte').body.items).toEqual([{ dish_id: 'n', price: '100.00' }]);
    });
});
