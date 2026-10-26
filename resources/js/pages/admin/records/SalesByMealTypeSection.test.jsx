import { cleanup, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ZReadingPanel } from './FiscalDayDetailPage';
import SalesByMealTypeSection from './SalesByMealTypeSection';

vi.mock('../AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../../../context/AuthContext', () => ({ useAuth: () => ({ setUser: () => {}, user: { capabilities: ['REPORT_VIEW'] } }) }));
afterEach(cleanup);

const item = (name, kind, net, qty, returned = '0.00') => ({ item: name, kind, quantity_net: qty, net_amount: net, returned_amount: returned });

const section = {
    sold_amount: '386.00',
    returned_amount: '65.00',
    net_amount: '321.00',
    voided_amount: '68.00',
    meal_types: [
        { meal_type: 'Combo Meal', net_amount: '85.00', returned_amount: '0.00', items: [item('Beef nilaga + Pinakbet', 'DISH', '85.00', '1.000')] },
        { meal_type: 'Standard Meal', net_amount: '136.00', returned_amount: '15.00', items: [item('Beef nilaga', 'DISH', '136.00', '2.000'), item('Whole rice', 'ADDON', '0.00', '0.000', '15.00')] },
    ],
};

describe('SalesByMealTypeSection', () => {
    it('lists each meal type with its dishes and bridges to the net sales', () => {
        render(<SalesByMealTypeSection section={section} />);
        const root = screen.getByTestId('sales-by-meal-type');

        expect(within(root).getByText('Combo Meal')).toBeTruthy();
        expect(within(root).getByText('Beef nilaga + Pinakbet')).toBeTruthy();
        expect(within(root).getByText('Beef nilaga')).toBeTruthy();
        expect(within(root).getByText('+ Whole rice')).toBeTruthy();
        expect(root.textContent).toContain('₱136.00');
        // Sales - refunds = net, and the voids that were never counted.
        expect(root.textContent).toContain('Sales');
        expect(root.textContent).toContain('₱386.00');
        expect(root.textContent).toContain('-₱65.00');
        expect(within(root).getByText('Net sales').nextSibling.textContent).toBe('₱321.00');
        expect(within(root).getByText('Voids (not counted)').nextSibling.textContent).toBe('₱68.00');
    });

    it('omits the refund and void lines when there were none', () => {
        render(<SalesByMealTypeSection section={{ ...section, returned_amount: '0.00', voided_amount: '0.00' }} />);
        const root = screen.getByTestId('sales-by-meal-type');

        expect(within(root).queryByText('Refunds')).toBeNull();
        expect(within(root).queryByText('Voids (not counted)')).toBeNull();
        expect(within(root).queryByText('Refunded')).toBeNull();
    });

    it('renders nothing for a day without the section (invoice mode, an old day, or no sales)', () => {
        for (const value of [undefined, null, { ...section, meal_types: [] }]) {
            const { container, unmount } = render(<SalesByMealTypeSection section={value} />);
            expect(container.textContent).toBe('');
            unmount();
        }
    });
});

describe('ZReadingPanel', () => {
    const reading = (extra = {}, snapshot = {}) => ({
        fiscal_day_id: 'day-1',
        business_date: '2026-10-26',
        generated_at: '2026-10-26T12:00:00Z',
        from_at: '2026-10-26T01:00:00Z',
        to_at: '2026-10-26T12:00:00Z',
        generated_by: 'user-1',
        original_printed_at: null,
        document_mode: 'SLIP',
        totals_snapshot: { z_counter: 4, reset_counter: 0, gross_sales: '386.00', taxable_sales: '0.00', vat_exempt_sales: '0.00', zero_rated_sales: '0.00', non_vat_sales: '386.00', vat_amount: '0.00', void_total: '68.00', refund_total: '65.00', accumulated_grand_total_sales_before: '0.00', accumulated_grand_total_sales_after: '386.00', payment_breakdown: { CASH: '301.00', CREDIT: '85.00' }, ...snapshot },
        ...extra,
    });

    it('shows the section and calls the reading the end of day report on a slip day', () => {
        render(<MemoryRouter><ZReadingPanel reading={reading({}, { sales_by_meal_type: section })} /></MemoryRouter>);

        expect(screen.getByText('End of day report #4')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Print end of day report' })).toBeTruthy();
        expect(screen.getByTestId('sales-by-meal-type')).toBeTruthy();
        expect(screen.getByText('CREDIT')).toBeTruthy();
    });

    it('leaves an invoice-mode Z-reading exactly as it was', () => {
        render(<MemoryRouter><ZReadingPanel reading={reading({ document_mode: 'INVOICE' })} /></MemoryRouter>);

        expect(screen.getByText('Z-reading #4')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Print Z-reading' })).toBeTruthy();
        expect(screen.queryByTestId('sales-by-meal-type')).toBeNull();
    });
});
