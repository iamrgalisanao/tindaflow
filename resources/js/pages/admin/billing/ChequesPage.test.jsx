import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ChequesPage from './ChequesPage';

vi.mock('../AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../../../context/AuthContext', () => ({ useAuth: () => ({ setUser: () => {}, user: { capabilities: ['BILLING_MANAGE'] } }) }));

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));

const period = (start, end, label, billed, received, status, cheques = [], writtenOff = '0.00') => {
    const owed = (Number(billed) - Number(received) - Number(writtenOff)).toFixed(2);
    return {
        period_start: start, period_end: end, period_label: label, charged_amount: billed, reversed_amount: '0.00', billed_amount: billed, received_amount: received,
        returned_cheques_amount: '0.00', written_off_amount: writtenOff, owed_amount: owed, credit_amount: Number(owed) < 0 ? (-Number(owed)).toFixed(2) : '0.00',
        write_off_excess_amount: '0.00', status, cheques,
    };
};

const writeOffRow = (overrides = {}) => ({
    id: 'wo-1', kind: 'WRITE_OFF', period_start: '2026-10-16', period_end: '2026-10-31', period_label: 'Oct 16-31, 2026', amount: '120.00', reason: 'TSC will not pay', cheque: null,
    reverses_write_off_id: null, status: 'ACTIVE', written_by: { id: 'u', name: 'Mia Manager' }, written_at: '2026-11-03T10:00:00+08:00', reversal: null, ...overrides,
});

let balance;
let cheques;
let writeOffs;
let calls;

function respondWith(handler) {
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options = {}) => {
            calls.push({ url, method: options.method ?? 'GET', body: options.body ? JSON.parse(options.body) : null, accept: options.headers?.Accept });
            if (options.headers?.Accept === 'text/csv') {
                return Promise.resolve(new Response('a,b\n', { status: 200 }));
            }
            return handler(url, options) ?? json({ data: balance });
        }),
    );
}

function baseHandler(url, options = {}) {
    if (url.startsWith('/api/v1/billing/balance')) {
        return json({ data: balance });
    }
    if (url.startsWith('/api/v1/billing-write-offs') && (options.method ?? 'GET') === 'GET') {
        return json({ data: writeOffs, meta: { page: 1, per_page: 25, total: writeOffs.length, last_page: 1 } });
    }
    if (url.startsWith('/api/v1/billing-cheques') && (options.method ?? 'GET') === 'GET') {
        return json({ data: cheques, meta: { page: 1, per_page: 25, total: cheques.length, last_page: 1 } });
    }
    return null;
}

const mine = {
    id: 'ch-1', cheque_number: '0012345', bank: 'BDO', cheque_date: '2026-11-02', amount: '400.00', received_on: '2026-11-02', received_by: { id: 'u', name: 'Mia Manager' },
    status: 'RECEIVED', returned_on: null, return_reason: null, notes: null, allocations: [{ period_start: '2026-10-01', period_end: '2026-10-15', amount: '400.00' }],
};

function renderPage() {
    return render(
        <MemoryRouter>
            <ChequesPage />
        </MemoryRouter>,
    );
}

beforeEach(() => {
    calls = [];
    URL.createObjectURL = vi.fn(() => 'blob:x');
    URL.revokeObjectURL = vi.fn();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    balance = {
        party: { id: 'p', name: 'TSC' },
        as_of: '2026-11-02T09:00:00+08:00',
        periods: [
            period('2026-10-16', '2026-10-31', 'Oct 16-31, 2026', '300.00', '0.00', 'OPEN'),
            period('2026-10-01', '2026-10-15', 'Oct 1-15, 2026', '500.00', '400.00', 'PARTLY_PAID', [{ cheque_id: 'ch-1', cheque_number: '0012345', bank: 'BDO', amount: '400.00', status: 'RECEIVED' }]),
        ],
        totals: { billed_amount: '800.00', received_amount: '400.00', written_off_amount: '0.00', owed_amount: '400.00', credit_amount: '0.00', write_off_excess_amount: '0.00', returned_cheques_amount: '0.00', period_count: 2, open_period_count: 2 },
    };
    cheques = [mine];
    writeOffs = [];
    respondWith(baseHandler);
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('ChequesPage', () => {
    it('shows what is owed per period with its status, the totals and the cheque list', async () => {
        renderPage();
        expect(await screen.findByText('Oct 16-31, 2026')).toBeTruthy();
        expect(screen.getByText('Open')).toBeTruthy();
        expect(screen.getByText('Partly paid')).toBeTruthy();
        expect(screen.getByText('Still owed').nextSibling.textContent).toBe('₱400.00');
        expect(screen.getAllByText(/BDO 0012345: ₱400\.00/).length).toBe(1);
        expect(screen.getByText('Mia Manager')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Statement' }).getAttribute('href')).toBe('/admin/billing');
        expect(screen.getByRole('link', { name: 'Billing runs' }).getAttribute('href')).toBe('/admin/billing/runs');
    });

    it('shows an overpaid period clearly as a credit and does not hide it', async () => {
        balance.periods[1] = period('2026-10-01', '2026-10-15', 'Oct 1-15, 2026', '400.00', '500.00', 'OVERPAID');
        balance.totals = { ...balance.totals, owed_amount: '300.00', credit_amount: '100.00' };
        renderPage();
        expect(await screen.findByText('Overpaid (credit to TSC)')).toBeTruthy();
        expect(screen.getByText('₱100.00 credit')).toBeTruthy();
    });

    it('proposes the allocation oldest first and records the cheque with the periods the user confirms', async () => {
        respondWith((url, options = {}) => {
            if (url === '/api/v1/billing-cheques' && options.method === 'POST') {
                return json({ data: { ...mine, id: 'ch-2', cheque_number: '77' } }, 201);
            }
            return baseHandler(url, options);
        });
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: 'Record cheque' }));
        const form = screen.getByRole('form', { name: 'Record a cheque' });

        fireEvent.change(within(form).getByLabelText('Cheque number'), { target: { value: '77' } });
        fireEvent.change(within(form).getByLabelText('Bank'), { target: { value: 'BPI' } });
        fireEvent.change(within(form).getByLabelText('Amount (₱)'), { target: { value: '250' } });

        // Oldest first: the 1st-15th owes 100.00, so the rest spills over to the 16th-end.
        expect(within(form).getByLabelText('Amount for Oct 1-15, 2026').value).toBe('100.00');
        expect(within(form).getByLabelText('Amount for Oct 16-31, 2026').value).toBe('150.00');
        expect(within(form).getByRole('status').textContent).toMatch(/All .*250\.00 is placed/);

        fireEvent.click(within(form).getByRole('button', { name: 'Record cheque' }));
        await waitFor(() => expect(calls.some((call) => call.method === 'POST')).toBe(true));
        const post = calls.find((call) => call.method === 'POST');
        expect(post.url).toBe('/api/v1/billing-cheques');
        expect(post.body).toMatchObject({
            cheque_number: '77', bank: 'BPI', amount: '250.00', notes: null,
            allocations: [{ period_start: '2026-10-01', amount: '100.00' }, { period_start: '2026-10-16', amount: '150.00' }],
        });
        expect(await screen.findByText('Cheque 77 recorded')).toBeTruthy();
    });

    it('will not record a cheque whose allocations do not equal its amount or exceed what a period owes', async () => {
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: 'Record cheque' }));
        const form = screen.getByRole('form', { name: 'Record a cheque' });
        fireEvent.change(within(form).getByLabelText('Cheque number'), { target: { value: '78' } });
        fireEvent.change(within(form).getByLabelText('Bank'), { target: { value: 'BPI' } });
        fireEvent.change(within(form).getByLabelText('Amount (₱)'), { target: { value: '250' } });

        fireEvent.change(within(form).getByLabelText('Amount for Oct 16-31, 2026'), { target: { value: '100.00' } });
        expect(within(form).getByRole('status').textContent).toMatch(/50\.00 of the cheque is not placed yet/);
        expect(within(form).getByRole('button', { name: 'Record cheque' }).disabled).toBe(true);

        fireEvent.change(within(form).getByLabelText('Amount for Oct 1-15, 2026'), { target: { value: '150.00' } });
        expect(within(form).getByText(/Only .*100\.00 is owed for this period/)).toBeTruthy();
        expect(within(form).getByRole('button', { name: 'Record cheque' }).disabled).toBe(true);
        expect(calls.some((call) => call.method === 'POST')).toBe(false);
    });

    it('says when the cheque is more than all the periods owe', async () => {
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: 'Record cheque' }));
        const form = screen.getByRole('form', { name: 'Record a cheque' });
        fireEvent.change(within(form).getByLabelText('Amount (₱)'), { target: { value: '1000' } });
        expect(within(form).getByText(/₱600\.00 more than all the periods owe/)).toBeTruthy();
        expect(within(form).getByRole('button', { name: 'Record cheque' }).disabled).toBe(true);
    });

    it('shows the server refusal and reloads the figures when what is owed changed underneath', async () => {
        respondWith((url, options = {}) => {
            if (url === '/api/v1/billing-cheques' && options.method === 'POST') {
                return json({ error: { code: 'CHEQUE_ALLOCATION_EXCEEDS_OWED', message: 'A cheque cannot pay more than is owed. For the period 2026-10-01 to 2026-10-15, only P50.00 is still owed.', details: {} } }, 409);
            }
            return baseHandler(url, options);
        });
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: 'Record cheque' }));
        const form = screen.getByRole('form', { name: 'Record a cheque' });
        fireEvent.change(within(form).getByLabelText('Cheque number'), { target: { value: '79' } });
        fireEvent.change(within(form).getByLabelText('Bank'), { target: { value: 'BPI' } });
        fireEvent.change(within(form).getByLabelText('Amount (₱)'), { target: { value: '100' } });
        const before = calls.filter((call) => call.url.startsWith('/api/v1/billing/balance')).length;
        fireEvent.click(within(form).getByRole('button', { name: 'Record cheque' }));

        expect(await screen.findByText(/only P50\.00 is still owed/)).toBeTruthy();
        await waitFor(() => expect(calls.filter((call) => call.url.startsWith('/api/v1/billing/balance')).length).toBeGreaterThan(before));
    });

    it('marks a cheque returned only after a reason and a confirmation, then reloads', async () => {
        respondWith((url, options = {}) => {
            if (url === '/api/v1/billing-cheques/ch-1/return' && options.method === 'POST') {
                return json({ data: { ...mine, status: 'RETURNED' } });
            }
            return baseHandler(url, options);
        });
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: 'Mark cheque 0012345 returned' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Mark cheque returned' });
        const confirm = within(dialog).getByRole('button', { name: 'Mark returned' });
        expect(confirm.disabled).toBe(true);

        fireEvent.change(within(dialog).getByLabelText('Why was it returned?'), { target: { value: 'Insufficient funds' } });
        expect(confirm.disabled).toBe(false);
        fireEvent.click(confirm);

        await waitFor(() => expect(calls.some((call) => call.url === '/api/v1/billing-cheques/ch-1/return')).toBe(true));
        const post = calls.find((call) => call.url.endsWith('/return'));
        expect(post.body).toMatchObject({ reason: 'Insufficient funds' });
        expect(post.body.returned_on).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        expect(await screen.findByText('Cheque 0012345 marked returned')).toBeTruthy();
    });

    it('offers no return action for a cheque already returned and shows why', async () => {
        cheques = [{ ...mine, status: 'RETURNED', returned_on: '2026-11-05', return_reason: 'Account closed' }];
        renderPage();
        expect(await screen.findByText(/Account closed/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Mark cheque .* returned/ })).toBeNull();
    });

    it('exports the balance and the cheque list as CSV files', async () => {
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: 'Export balance CSV' }));
        await waitFor(() => expect(calls.some((call) => call.accept === 'text/csv' && call.url === '/api/v1/billing/balance')).toBe(true));
        fireEvent.click(screen.getByRole('button', { name: 'Export cheques CSV' }));
        await waitFor(() => expect(calls.some((call) => call.accept === 'text/csv' && call.url === '/api/v1/billing-cheques')).toBe(true));
    });

    it('says so when there is nothing yet', async () => {
        balance = { ...balance, periods: [], totals: { ...balance.totals, billed_amount: '0.00', received_amount: '0.00', owed_amount: '0.00', open_period_count: 0, period_count: 0 } };
        cheques = [];
        renderPage();
        expect(await screen.findByText(/Nothing has been charged to employee credit yet/)).toBeTruthy();
        expect(screen.getByText('No cheques have been recorded yet.')).toBeTruthy();
    });

    // ---------------------------------------------------------------------------------------- slice 4.3: write-offs

    it('shows a Written off column and total, a note on a partly written-off period, and a Write off action only where something is owed', async () => {
        balance.periods = [
            period('2026-10-16', '2026-10-31', 'Oct 16-31, 2026', '300.00', '0.00', 'OPEN', [], '120.00'),
            period('2026-10-01', '2026-10-15', 'Oct 1-15, 2026', '500.00', '400.00', 'WRITTEN_OFF', [], '100.00'),
        ];
        balance.totals = { ...balance.totals, written_off_amount: '220.00', owed_amount: '180.00' };
        renderPage();
        expect(await screen.findByText('Oct 16-31, 2026')).toBeTruthy();

        expect(screen.getByRole('columnheader', { name: 'Written off' })).toBeTruthy();
        expect(screen.getAllByText('Written off').some((element) => element.nextSibling?.textContent === '₱220.00')).toBe(true); // the tile
        expect(screen.getByText('₱120.00 written off')).toBeTruthy(); // the partly written-off period says so
        expect(screen.getAllByText('Written off').length).toBeGreaterThan(1); // tile, column header and the WRITTEN_OFF status badge
        expect(screen.getByRole('button', { name: 'Write off an amount of Oct 16-31, 2026' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Write off an amount of Oct 1-15, 2026' })).toBeNull();
    });

    it('writes off in two steps: the form, then a confirmation that repeats the amount and the period', async () => {
        respondWith((url, options = {}) => {
            if (url === '/api/v1/billing-write-offs' && options.method === 'POST') {
                return json({ data: writeOffRow({ amount: '300.00' }) }, 201);
            }
            return baseHandler(url, options);
        });
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: 'Write off an amount of Oct 16-31, 2026' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Write off an unpaid amount' });

        expect(within(dialog).getByLabelText('Amount to write off (₱)').value).toBe('300.00'); // defaults to what is owed
        fireEvent.click(within(dialog).getByRole('button', { name: 'Review write-off' }));
        expect(within(dialog).getByText('Say why this amount is being written off.')).toBeTruthy(); // reason is required
        expect(calls.some((call) => call.method === 'POST')).toBe(false);

        fireEvent.change(within(dialog).getByLabelText('Why is it being written off?'), { target: { value: '  TSC will not pay  ' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Review write-off' }));
        const confirm = screen.getByRole('alertdialog', { name: 'Write off an unpaid amount' });
        expect(confirm.textContent).toMatch(/You are writing off ₱300\.00 of Oct 16-31, 2026 \(2026-10-16 to 2026-10-31\)/);
        expect(confirm.textContent).toMatch(/from ₱300\.00 to ₱0\.00/);
        expect(confirm.textContent).toMatch(/No money is received/);

        fireEvent.click(within(confirm).getByRole('button', { name: 'Write off ₱300.00' }));
        await waitFor(() => expect(calls.some((call) => call.method === 'POST')).toBe(true));
        const post = calls.find((call) => call.method === 'POST');
        expect(post.url).toBe('/api/v1/billing-write-offs');
        expect(post.body).toMatchObject({ period_start: '2026-10-16', amount: '300.00', reason: 'TSC will not pay', cheque_id: null });
        expect(post.body.request_id).toMatch(/^[0-9a-f-]{36}$/);
        expect(await screen.findByText('₱300.00 written off for Oct 16-31, 2026')).toBeTruthy();
    });

    it('will not review a write-off of more than the period owes or of nothing', async () => {
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: 'Write off an amount of Oct 16-31, 2026' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Write off an unpaid amount' });
        fireEvent.change(within(dialog).getByLabelText('Why is it being written off?'), { target: { value: 'Will not be paid' } });

        fireEvent.change(within(dialog).getByLabelText('Amount to write off (₱)'), { target: { value: '300.01' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Review write-off' }));
        expect(within(dialog).getByText(/Only .*300\.00 is owed for this period/)).toBeTruthy();

        fireEvent.change(within(dialog).getByLabelText('Amount to write off (₱)'), { target: { value: '0' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Review write-off' }));
        expect(within(dialog).getByText(/more than zero/)).toBeTruthy();
        expect(screen.queryByText('Confirm the write-off')).toBeNull();
        expect(calls.some((call) => call.method === 'POST')).toBe(false);
    });

    it('offers the returned cheques of the period and sends the one chosen; a retry keeps the same request id', async () => {
        balance.periods[1] = period('2026-10-01', '2026-10-15', 'Oct 1-15, 2026', '500.00', '0.00', 'OPEN', [
            { cheque_id: 'ch-9', cheque_number: '0099', bank: 'BPI', amount: '500.00', status: 'RETURNED' },
            { cheque_id: 'ch-1', cheque_number: '0012345', bank: 'BDO', amount: '100.00', status: 'RECEIVED' },
        ]);
        let attempts = 0;
        respondWith((url, options = {}) => {
            if (url === '/api/v1/billing-write-offs' && options.method === 'POST') {
                attempts += 1;
                return attempts === 1 ? json({ error: { code: 'INTERNAL', message: 'Something went wrong.', details: {} } }, 500) : json({ data: writeOffRow({ amount: '500.00', period_start: '2026-10-01', period_label: 'Oct 1-15, 2026' }) }, 201);
            }
            return baseHandler(url, options);
        });
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: 'Write off an amount of Oct 1-15, 2026' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Write off an unpaid amount' });
        const select = within(dialog).getByLabelText('Returned cheque it relates to (optional)');
        expect(within(select).getAllByRole('option').map((option) => option.textContent)).toEqual(['None', expect.stringContaining('BPI 0099')]); // the received cheque is not offered
        fireEvent.change(select, { target: { value: 'ch-9' } });
        fireEvent.change(within(dialog).getByLabelText('Why is it being written off?'), { target: { value: 'Bounced and closed account' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Review write-off' }));
        const confirm = screen.getByRole('alertdialog', { name: 'Write off an unpaid amount' });
        expect(confirm.textContent).toMatch(/Cheque BPI 0099/);

        fireEvent.click(within(confirm).getByRole('button', { name: 'Write off ₱500.00' }));
        expect(await screen.findByRole('alert')).toBeTruthy();
        fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Write off ₱500.00' }));
        await waitFor(() => expect(calls.filter((call) => call.method === 'POST').length).toBe(2));
        const [first, second] = calls.filter((call) => call.method === 'POST');
        expect(first.body.cheque_id).toBe('ch-9');
        expect(second.body.request_id).toBe(first.body.request_id);
    });

    it('shows the server refusal and reloads the figures when the owed amount changed underneath', async () => {
        respondWith((url, options = {}) => {
            if (url === '/api/v1/billing-write-offs' && options.method === 'POST') {
                return json({ error: { code: 'WRITE_OFF_EXCEEDS_OWED', message: 'You cannot write off more than is owed. For the period 2026-10-16 to 2026-10-31, only P80.00 is still owed.', details: {} } }, 409);
            }
            return baseHandler(url, options);
        });
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: 'Write off an amount of Oct 16-31, 2026' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Write off an unpaid amount' });
        fireEvent.change(within(dialog).getByLabelText('Why is it being written off?'), { target: { value: 'Will not be paid' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Review write-off' }));
        const before = calls.filter((call) => call.url.startsWith('/api/v1/billing/balance')).length;
        fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Write off ₱300.00' }));

        expect(await screen.findByText(/only P80\.00 is still owed/)).toBeTruthy();
        await waitFor(() => expect(calls.filter((call) => call.url.startsWith('/api/v1/billing/balance')).length).toBeGreaterThan(before));
    });

    it('lists write-offs with who and why, and reverses an active one only after a reason and a confirmation', async () => {
        writeOffs = [
            writeOffRow(),
            writeOffRow({ id: 'wo-2', amount: '50.00', status: 'REVERSED', reversal: { id: 'rv-1', reason: 'Written off by mistake', reversed_by: { id: 'a', name: 'Ana Admin' }, reversed_at: '2026-11-04T09:00:00+08:00' } }),
            writeOffRow({ id: 'rv-1', kind: 'REVERSAL', amount: '50.00', status: null, reverses_write_off_id: 'wo-2', reason: 'Written off by mistake', written_by: { id: 'a', name: 'Ana Admin' } }),
        ];
        respondWith((url, options = {}) => {
            if (url === '/api/v1/billing-write-offs/wo-1/reverse' && options.method === 'POST') {
                return json({ data: writeOffRow({ id: 'rv-2', kind: 'REVERSAL', status: null }) }, 201);
            }
            return baseHandler(url, options);
        });
        renderPage();
        expect((await screen.findAllByText('TSC will not pay')).length).toBe(2);
        expect(screen.getByText('Reversed')).toBeTruthy();
        expect(screen.getByText('Reversal')).toBeTruthy();
        expect(screen.getByText(/Ana Admin: Written off by mistake/)).toBeTruthy();
        expect(screen.getAllByRole('button', { name: /^Reverse the write-off/ }).length).toBe(1); // only the active write-off

        fireEvent.click(screen.getByRole('button', { name: 'Reverse the write-off of ₱120.00 for Oct 16-31, 2026' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Reverse a write-off' });
        const confirm = within(dialog).getByRole('button', { name: 'Reverse ₱120.00' });
        expect(confirm.disabled).toBe(true);
        fireEvent.change(within(dialog).getByLabelText('Why is it being reversed?'), { target: { value: 'Entered by mistake' } });
        fireEvent.click(confirm);

        await waitFor(() => expect(calls.some((call) => call.url === '/api/v1/billing-write-offs/wo-1/reverse')).toBe(true));
        expect(calls.find((call) => call.url.endsWith('/reverse')).body).toEqual({ reason: 'Entered by mistake' });
        expect(await screen.findByText('Write-off of ₱120.00 reversed')).toBeTruthy();
    });

    it('after a cheque is marked returned, says the owed amount is back and offers to write it off', async () => {
        respondWith((url, options = {}) => {
            if (url === '/api/v1/billing-cheques/ch-1/return' && options.method === 'POST') {
                return json({ data: { ...mine, status: 'RETURNED' } });
            }
            return baseHandler(url, options);
        });
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: 'Mark cheque 0012345 returned' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Mark cheque returned' });
        fireEvent.change(within(dialog).getByLabelText('Why was it returned?'), { target: { value: 'Insufficient funds' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Mark returned' }));

        const hint = (await screen.findByText(/The owed amount is back/)).closest('[role="status"]');
        expect(hint.textContent).toMatch(/Cheque 0012345 is marked returned\. The owed amount is back\. Write it off\?/);
        fireEvent.click(within(hint).getByRole('button', { name: 'Write off part of Oct 1-15, 2026' }));
        expect(screen.getByRole('alertdialog', { name: 'Write off an unpaid amount' })).toBeTruthy();

        fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Cancel' }));
        fireEvent.click(within(hint).getByRole('button', { name: 'Not now' }));
        expect(screen.queryByText(/The owed amount is back/)).toBeNull();
    });

    it('says plainly when more was written off than is now unpaid and does not show it as a credit', async () => {
        balance.periods[1] = { ...period('2026-10-01', '2026-10-15', 'Oct 1-15, 2026', '300.00', '0.00', 'OVER_WRITTEN_OFF', [], '400.00'), write_off_excess_amount: '100.00' };
        balance.totals = { ...balance.totals, write_off_excess_amount: '100.00', written_off_amount: '400.00' };
        renderPage();
        expect(await screen.findByText('Written off too much')).toBeTruthy();
        expect(screen.getByText(/₱100\.00 more was written off than is now unpaid/)).toBeTruthy();
        expect(screen.queryByText(/credit$/)).toBeNull();
    });

    it('does not offer to write off while looking at a past date', async () => {
        renderPage();
        const asOf = await screen.findByLabelText('As of (optional)');
        expect(screen.getAllByRole('button', { name: /^Write off an amount of/ }).length).toBeGreaterThan(0);
        fireEvent.change(asOf, { target: { value: '2026-11-01T09:00' } });
        await waitFor(() => expect(screen.queryAllByRole('button', { name: /^Write off an amount of/ }).length).toBe(0));
    });
});
