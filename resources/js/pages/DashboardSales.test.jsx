import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DashboardSales from './DashboardSales';

let asked;
let answers;

vi.mock('../api', () => ({
    apiFetch: vi.fn(async (url) => {
        asked.push(url);
        const query = url.split('?')[1];
        return { ok: true, status: 200, body: answers[query] ?? { rows: [], summary: { gross_sales: '0.00', discount_total: '0.00', grand_total: '0.00' } } };
    }),
}));

vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { capabilities: ['REPORT_VIEW'] }, setUser: vi.fn() }) }));

const report = (counts, total) => ({
    rows: counts.map((transaction_count, day) => ({ business_date: `2026-11-0${day + 1}`, transaction_count })),
    summary: { gross_sales: total, discount_total: '0.00', grand_total: total },
});

const show = () => render(<MemoryRouter><DashboardSales /></MemoryRouter>);
const press = (name) => fireEvent.click(screen.getByRole('button', { name }));
const type = (label, value) => fireEvent.change(screen.getByLabelText(label), { target: { value } });

describe('DashboardSales', () => {
    beforeEach(() => {
        // A Friday: the week began on Monday the 23rd. Only the date is faked, so waiting for the screen still works.
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date(2026, 10, 27, 12, 0));
        asked = [];
        answers = {};
    });

    afterEach(() => {
        cleanup();
        vi.useRealTimers();
    });

    it('starts on today', async () => {
        show();

        expect(await screen.findByText(/No sales have been finalised today yet/)).toBeTruthy();
        expect(asked).toEqual(['/api/v1/reports/daily-sales-summary?from=2026-11-27&to=2026-11-27']);
        expect(screen.getByRole('heading', { name: 'Today so far' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Today' }).getAttribute('aria-pressed')).toBe('true');
    });

    it('follows a preset, adding up the transactions of every day', async () => {
        answers['from=2026-11-23&to=2026-11-27'] = report([4, 7, 2], '1500.50');
        show();

        press('This week');

        expect(await screen.findByText('13')).toBeTruthy();
        expect(screen.getAllByText('₱1,500.50').length).toBe(2);
        expect(screen.getByRole('heading', { name: 'This week so far' })).toBeTruthy();
        expect(screen.getByRole('link', { name: /Daily sales summary/ }).getAttribute('href')).toBe('/admin/reports/daily-sales-summary?from=2026-11-23&to=2026-11-27');
    });

    it('shows chosen dates only when asked to', async () => {
        answers['from=2026-11-01&to=2026-11-15'] = report([9], '900.00');
        show();
        await screen.findByText(/No sales have been finalised today yet/);

        type('From', '2026-11-01');
        type('To', '2026-11-15');
        expect(asked.length).toBe(1);

        press('Show these dates');

        expect(await screen.findByText('9')).toBeTruthy();
        expect(screen.getByRole('heading', { name: '2026-11-01 to 2026-11-15' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Today' }).getAttribute('aria-pressed')).toBe('false');
    });

    it('says there are no sales for other dates without calling them today', async () => {
        show();

        press('Yesterday');

        expect(await screen.findByText(/There are no sales for these dates/)).toBeTruthy();
        expect(asked.at(-1)).toBe('/api/v1/reports/daily-sales-summary?from=2026-11-26&to=2026-11-26');
    });

    it('does not ask for dates that run backwards or are missing', async () => {
        show();
        await screen.findByText(/No sales have been finalised today yet/);

        type('From', '2026-11-28');
        expect(screen.getByRole('alert').textContent).toContain('after');
        expect(screen.getByRole('button', { name: 'Show these dates' }).disabled).toBe(true);

        type('From', '');
        expect(screen.getByRole('button', { name: 'Show these dates' }).disabled).toBe(true);
        await waitFor(() => expect(asked.length).toBe(1));
    });

    it('never shows one period\'s figures under another\'s heading', async () => {
        answers['from=2026-11-27&to=2026-11-27'] = report([5], '500.00');
        show();
        await screen.findByText('5');

        press('This month');

        expect(screen.queryByText('5')).toBeNull();
        expect(await screen.findByText(/There are no sales for these dates/)).toBeTruthy();
    });
});
