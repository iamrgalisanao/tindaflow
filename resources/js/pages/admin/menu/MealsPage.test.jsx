import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DishesPage from './DishesPage';
import MealsPage from './MealsPage';

vi.mock('../AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../../../context/AuthContext', () => ({ useAuth: () => ({ setUser: () => {} }) }));

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
const page = (data) => ({ data, meta: { page: 1, per_page: 100, total: data.length, last_page: 1 } });

const categories = [
    { id: 'beef', code: 'BEEF', name: 'Beef', sort_order: 1, active: true },
    { id: 'veg', code: 'VEGETABLE', name: 'Vegetable', sort_order: 2, active: true },
];
const dishes = [
    { id: 'd1', name: 'Beef nilaga', dish_category_id: 'beef', sort_order: 1, active: true },
    { id: 'd2', name: 'Pinakbet', dish_category_id: 'veg', sort_order: 1, active: true },
];
const option = (id, group, dish, extra = {}) => ({ id, meal_option_group_id: group, dish_id: dish.id, dish_name: dish.name, dish_category_id: dish.dish_category_id, label: null, sort_order: 1, active: true, ...extra });
const meals = [
    {
        id: 'm1',
        name: 'Combo Meal',
        menu_group_id: null,
        backing_product_id: 'bp',
        sort_order: 1,
        active: true,
        option_groups: [
            { id: 'g1', meal_id: 'm1', name: 'Main', kind: 'CHOICE', min_select: 1, max_select: 1, prices_the_meal: true, popup_after_choice: false, step_order: 1, active: true, options: [option('o1', 'g1', dishes[0])] },
            { id: 'g2', meal_id: 'm1', name: 'Side', kind: 'CHOICE', min_select: 1, max_select: 1, prices_the_meal: false, popup_after_choice: false, step_order: 2, active: true, options: [option('o2', 'g2', dishes[1])] },
            { id: 'g3', meal_id: 'm1', name: 'Extra rice', kind: 'ADDON', min_select: 0, max_select: 1, prices_the_meal: false, popup_after_choice: true, step_order: 3, active: true, options: [{ id: 'o3', meal_option_group_id: 'g3', dish_id: null, dish_name: null, dish_category_id: null, label: 'Whole rice', sort_order: 1, active: true }] },
        ],
        pricing: { unpriced_dish_category_ids: ['beef'], unpriced_option_ids: [] },
    },
];

let calls;
beforeEach(() => {
    calls = [];
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options = {}) => {
            calls.push({ url, method: options.method ?? 'GET', body: options.body ? JSON.parse(options.body) : undefined });
            if (options.method === 'POST' || options.method === 'PATCH') {
                return json({ id: 'x' });
            }
            if (url.startsWith('/api/v1/meals?')) return json(page(meals));
            if (url.startsWith('/api/v1/dishes?')) return json(page(dishes));
            if (url.startsWith('/api/v1/dish-categories?')) return json(page(categories));
            return json(page([]));
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const renderInRouter = (element) => render(<MemoryRouter>{element}</MemoryRouter>);

describe('MealsPage', () => {
    it('lists the steps with what each asks and which one prices the meal, and warns about the missing price', async () => {
        renderInRouter(<MealsPage />);
        expect(await screen.findByText('Main')).toBeTruthy();
        expect(screen.getByText('PRICES THE MEAL')).toBeTruthy();
        expect(screen.getAllByText(/Pick exactly 1/).length).toBe(2);
        expect(screen.getByText(/Add-ons · Optional · opens automatically/)).toBeTruthy();
        expect(screen.getByText(/No price yet for: Beef/)).toBeTruthy();
        expect(screen.getByText('Whole rice')).toBeTruthy();
    });

    it('offers to move the pricing flag only to a dish step that is not already the pricing step', async () => {
        renderInRouter(<MealsPage />);
        await screen.findByText('Main');
        const buttons = screen.getAllByRole('button', { name: 'Make this price the meal' });
        expect(buttons.length).toBe(1);

        fireEvent.click(buttons[0]);
        await waitFor(() => expect(calls.some((call) => call.method === 'PATCH')).toBe(true));
        expect(calls.find((call) => call.method === 'PATCH')).toMatchObject({ url: '/api/v1/meal-option-groups/g2', body: { prices_the_meal: true } });
    });

    it('never offers to hide the step that prices the meal', async () => {
        renderInRouter(<MealsPage />);
        await screen.findByText('Main');
        const mainRow = screen.getByText('Main').closest('li');
        expect(mainRow.textContent).not.toMatch(/\bHide\b/);
    });
});

describe('DishesPage', () => {
    it('groups dishes under their category and renames through the API', async () => {
        renderInRouter(<DishesPage />);
        expect(await screen.findByText('Beef nilaga')).toBeTruthy();
        expect(screen.getByRole('region', { name: 'Beef' })).toBeTruthy();

        fireEvent.click(screen.getAllByRole('button', { name: 'Rename' })[1]);
        fireEvent.change(screen.getByPlaceholderText('Dish name'), { target: { value: 'Beef nilaga special' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(calls.some((call) => call.method === 'PATCH')).toBe(true));
        expect(calls.find((call) => call.method === 'PATCH')).toMatchObject({ url: '/api/v1/dishes/d1', body: { name: 'Beef nilaga special' } });
    });

    it('moves a dish to another category from its select', async () => {
        renderInRouter(<DishesPage />);
        await screen.findByText('Beef nilaga');
        fireEvent.change(screen.getByLabelText('Category of Beef nilaga'), { target: { value: 'veg' } });
        await waitFor(() => expect(calls.some((call) => call.method === 'PATCH')).toBe(true));
        expect(calls.find((call) => call.method === 'PATCH')).toMatchObject({ url: '/api/v1/dishes/d1', body: { dish_category_id: 'veg' } });
    });
});
