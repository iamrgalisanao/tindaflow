import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

// ADR-016 slice 3.2, CREDIT-002: a sale charged to an employee is refunded to credit and nothing else.
const calls = [];
vi.mock('../catalog/catalogApi', () => ({
    request: vi.fn(async (url, options = {}) => {
        calls.push({ url, body: options.body });
        return { ok: true, status: 201, body: { id: 'r1', status: 'COMPLETED' } };
    }),
    fieldErrors: () => ({}),
}));

const { default: RefundPanel } = await import('./RefundPanel');

afterEach(() => {
    cleanup();
    calls.length = 0;
});

const item = { id: 'i1', line_number: 1, product_name_snapshot: 'Plain rice', quantity: '2.000', unit_price_snapshot: '15.00', net_line_amount: '30.00', parent_line_number: null };
const sale = (extra = {}) => ({ id: 's1', items: [item], refunds: [], document_reference: 'T-01-000001', ...extra });
const panel = (saleValue) => render(<RefundPanel sale={saleValue} executesImmediately onDone={() => {}} onClose={() => {}} onUnauthorized={() => {}} />);

async function refundOne() {
    fireEvent.change(await screen.findByLabelText('Quantity returned'), { target: { value: '1' } });
    fireEvent.change(screen.getByLabelText('What happens to it'), { target: { value: 'RETURN_TO_STOCK' } });
    fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Customer returned it' } });
    fireEvent.click(screen.getByRole('button', { name: 'Refund' }));
}

describe('RefundPanel for a credit sale', () => {
    it('offers only credit back: no method to choose, no split, no cash wording, and settles to CREDIT', async () => {
        panel(sale({ employee: { id: 'e1', employee_code: 'TSC00012', name: 'Ana Reyes' } }));

        await waitFor(() => expect(screen.getByText('Credit back to the employee')).toBeTruthy());
        expect(screen.getByText('Ana Reyes')).toBeTruthy();
        expect(screen.queryByLabelText('Method')).toBeNull();
        expect(screen.queryByText(/Split across another method/)).toBeNull();
        expect(screen.queryByText(/Cash comes out of the drawer/)).toBeNull();

        await refundOne();
        await waitFor(() => expect(calls.some((call) => call.url === '/api/v1/sales/s1/refunds')).toBe(true));
        const refund = calls.find((call) => call.url === '/api/v1/sales/s1/refunds');
        expect(refund.body.settlements).toEqual([{ payment_method: 'CREDIT', amount: '15.00' }]);
    });

    it('is unchanged for any other sale: cash first, every method but CREDIT to choose from', async () => {
        panel(sale());

        const method = await screen.findByLabelText('Method');
        expect(method.value).toBe('CASH');
        expect([...method.options].map((option) => option.value)).toEqual(['CASH', 'GCASH', 'MAYA', 'CARD', 'OTHER']);
        expect(screen.getByText(/Cash comes out of the drawer/)).toBeTruthy();
    });
});
