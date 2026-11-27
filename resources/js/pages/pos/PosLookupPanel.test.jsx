import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PosLookupPanel from './PosLookupPanel';

let asked;
let answer;

vi.mock('../../api', () => ({
    apiFetch: vi.fn(async (url) => {
        asked.push(url);
        return { ok: true, status: 200, body: answer };
    }),
}));

const sale = (extra = {}) => ({
    id: 's1',
    transaction_number: 'T-01HXAAAAAAAAAAAAAAAAAAAAAA',
    document_mode: 'INVOICE',
    document_reference: '000123',
    invoice_number: '000123',
    sold_at: new Date().toISOString(),
    grand_total: '150.00',
    status: 'COMPLETED',
    item_count: 3,
    payment_methods: ['CASH', 'BANK_TRANSFER'],
    ...extra,
});

const show = () => render(<PosLookupPanel canSeeAllSales capabilities={[]} />);
const lastQuery = () => new URLSearchParams(asked.at(-1).split('?')[1]);

describe('PosLookupPanel', () => {
    beforeEach(() => {
        asked = [];
        answer = { data: [sale()], meta: { total: 1 } };
    });

    afterEach(cleanup);

    it('shows how many lines each sale has and how it was paid', async () => {
        show();

        expect(await screen.findByText('3 lines · CASH + BANK TRANSFER')).toBeTruthy();
        expect(screen.queryByText(/Showing the newest/)).toBeNull();
    });

    it('says "1 line" for a single line', async () => {
        answer = { data: [sale({ item_count: 1, payment_methods: ['GCASH'] })], meta: { total: 1 } };
        show();

        expect(await screen.findByText('1 line · GCASH')).toBeTruthy();
    });

    it('searches by any part of a number, whatever it looks like', async () => {
        show();
        await screen.findByText(/3 lines/);

        for (const text of ['0123', 'T-01HXAAAAAAAAAAAAAAAAAAAAAA']) {
            fireEvent.change(screen.getByLabelText('Search by transaction, invoice or slip number'), { target: { value: `  ${text} ` } });
            await waitFor(() => expect(lastQuery().get('number_contains')).toBe(text));
            expect(lastQuery().get('transaction_number')).toBeNull();
            expect(lastQuery().get('document_number')).toBeNull();
        }
    });

    it('says to turn off Today when nothing of today matches', async () => {
        answer = { data: [], meta: { total: 0 } };
        show();
        fireEvent.change(screen.getByLabelText('Search by transaction, invoice or slip number'), { target: { value: '999' } });

        expect(await screen.findByText('No transaction matches that number. Turn off Today to search earlier days.')).toBeTruthy();
    });

    it('says when more sales match than are shown', async () => {
        answer = { data: [sale()], meta: { total: 40 } };
        show();

        expect(await screen.findByText(/Showing the newest 1 of 40/)).toBeTruthy();
    });

    it('gives the date of a sale from an earlier day', async () => {
        answer = { data: [sale({ sold_at: '2020-03-05T04:00:00.000Z' })], meta: { total: 1 } };
        show();

        expect(await screen.findByText(/000123 · .*2020/)).toBeTruthy();
    });
});
