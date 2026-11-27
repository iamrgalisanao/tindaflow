import { cleanup, render, screen, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ShiftDetailPage from './ShiftDetailPage';

// The shift screen lists every X-reading the shift has taken, each with its own figures.

let asked;
let readings;

vi.mock('../../../api', () => ({
    apiFetch: vi.fn(async (url) => {
        asked.push(url);
        const body = url.endsWith('/x-readings')
            ? readings
            : { id: 's1', status: 'CLOSED', opened_at: '2026-11-27T00:00:00Z', closed_at: '2026-11-27T09:00:00Z', cashier_id: 'u1', terminal_id: 't1', fiscal_day_id: 'd1', opening_cash: '500.00' };
        return { ok: true, status: 200, body };
    }),
}));

vi.mock('../AdminLayout', () => ({ default: ({ children }) => <main>{children}</main> }));
vi.mock('../../../context/AuthContext', () => ({ useAuth: () => ({ user: { capabilities: ['REPORT_VIEW'] }, setUser: vi.fn() }) }));

const reading = (id, sales, count, closing = false) => ({
    id,
    generated_at: '2026-11-27T03:00:00Z',
    from_at: '2026-11-27T00:00:00Z',
    to_at: '2026-11-27T03:00:00Z',
    is_closing_reading: closing,
    totals_snapshot: {
        opening_cash: '500.00', cash_sales: sales, non_cash_sales: '0.00', refunds_total: '0.00', cash_in_total: '0.00',
        cash_out_total: '0.00', expected_cash: '900.00', variance: null, transaction_count: count, payment_breakdown: { CASH: sales },
    },
});

const show = () => render(
    <MemoryRouter initialEntries={['/admin/shifts/s1']}>
        <Routes>
            <Route path="/admin/shifts/:shiftId" element={<ShiftDetailPage />} />
        </Routes>
    </MemoryRouter>,
);

describe('ShiftDetailPage X-readings', () => {
    afterEach(cleanup);

    it('lists every reading in order, each with its own figures', async () => {
        asked = [];
        readings = [reading('a', '111.00', 1), reading('b', '222.00', 2), reading('c', '333.00', 3, true)];
        show();

        expect(await screen.findByText('CLOSING READING')).toBeTruthy();
        expect(screen.getAllByText('INTERIM READING').length).toBe(2);
        const items = screen.getAllByRole('listitem');
        expect(items.length).toBe(3);
        ['111.00', '222.00', '333.00'].forEach((sales, index) => {
            // Once as the cash sales figure and once in the payments by method.
            expect(within(items[index]).getAllByText(`₱${sales}`).length).toBe(2);
        });
        expect(asked).toContain('/api/v1/shifts/s1/x-readings');
    });

    it('says when none was taken', async () => {
        asked = [];
        readings = [];
        show();

        expect(await screen.findByText('No X-reading was taken during this shift.')).toBeTruthy();
    });
});
