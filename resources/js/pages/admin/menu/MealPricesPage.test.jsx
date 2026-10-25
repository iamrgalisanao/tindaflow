import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import MealPricesPage from './MealPricesPage';

vi.mock('../AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../../../context/AuthContext', () => ({ useAuth: () => ({ setUser: () => {} }) }));

const MEAL = 'm1';
const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
const grid = {
    meal_id: MEAL,
    rows: [
        { kind: 'DISH_CATEGORY', dish_category_id: 'beef', meal_option_id: null, label: 'Beef', active: true, dishes: ['Beef nilaga'], current: { amount: '68.00', effective_from: '2026-10-01T00:00:00.000000Z' }, upcoming: { amount: '72.00', effective_from: '2026-12-01T00:00:00.000000Z' } },
        { kind: 'ADDON', dish_category_id: null, meal_option_id: 'rice', label: 'Whole rice', active: true, dishes: [], current: null, upcoming: null },
    ],
};
const history = { data: [{ id: 'p1', amount: '66.00', effective_from: '2026-10-01T00:00:00.000000Z', dish_category_name: 'Beef', meal_option_label: null, changed_by_name: 'Admin', reason: 'Opening' }], meta: { page: 1, per_page: 10, total: 1, last_page: 1 } };

let calls;
beforeEach(() => {
    calls = [];
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options = {}) => {
            calls.push({ url, method: options.method ?? 'GET', body: options.body ? JSON.parse(options.body) : undefined });
            if (url.startsWith('/api/v1/meals?')) {
                return json({ data: [{ id: MEAL, name: 'Standard Meal', active: true }], meta: { page: 1, per_page: 100, total: 1, last_page: 1 } });
            }
            if (url.includes('/prices/history')) {
                return json(history);
            }
            if (options.method === 'POST') {
                return json({ id: 'p2', amount: '70.00' }, 201);
            }
            return json(grid);
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

function renderPage() {
    return render(
        <MemoryRouter initialEntries={[`/admin/menu/prices/${MEAL}`]}>
            <Routes>
                <Route path="/admin/menu/prices/:mealId" element={<MealPricesPage />} />
            </Routes>
        </MemoryRouter>,
    );
}

describe('MealPricesPage', () => {
    it('shows the price in force, the scheduled next price, and the history', async () => {
        renderPage();
        expect(await screen.findByText('₱68.00')).toBeTruthy();
        expect(screen.getByText(/Next: ₱72.00/)).toBeTruthy();
        expect(screen.getByText('No price')).toBeTruthy();
        expect(await screen.findByText('Opening')).toBeTruthy();
    });

    it('appends a new price with the typed amount and never offers to save an unchanged one', async () => {
        renderPage();
        await screen.findByText('₱68.00');
        fireEvent.click(screen.getByRole('button', { name: 'Change price' }));

        const save = screen.getByRole('button', { name: 'Save price' });
        expect(save.disabled).toBe(true);

        fireEvent.change(screen.getByLabelText('New price (₱)'), { target: { value: '70' } });
        expect(save.disabled).toBe(false);
        fireEvent.click(save);

        await waitFor(() => expect(calls.some((call) => call.method === 'POST')).toBe(true));
        const post = calls.find((call) => call.method === 'POST');
        expect(post.url).toBe(`/api/v1/meals/${MEAL}/prices`);
        expect(post.body).toEqual({ dish_category_id: 'beef', amount: '70.00' });
    });

    it('rejects an amount that is not money without calling the server', async () => {
        renderPage();
        await screen.findByText('₱68.00');
        fireEvent.click(screen.getByRole('button', { name: 'Set price' }));
        fireEvent.change(screen.getByLabelText('New price (₱)'), { target: { value: 'abc' } });
        // the button stays disabled for an invalid amount, so the form cannot be submitted
        expect(screen.getByRole('button', { name: 'Save price' }).disabled).toBe(true);
        expect(calls.some((call) => call.method === 'POST')).toBe(false);
    });
});
