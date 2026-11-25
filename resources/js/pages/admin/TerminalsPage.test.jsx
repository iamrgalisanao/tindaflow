import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TerminalsPage from './TerminalsPage';

vi.mock('../../context/AuthContext', () => ({
    useAuth: () => ({ logout: () => {}, setUser: () => {}, user: { name: 'Ana Cruz', role: 'ADMIN', capabilities: ['TERMINAL_MANAGE'] } }),
}));

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));

let limit;
beforeEach(() => {
    limit = null;
    vi.stubGlobal(
        'fetch',
        vi.fn((url) => {
            if (url.startsWith('/api/v1/terminals')) {
                return json({ data: [], meta: { page: 1, per_page: 100, total: 0, last_page: 1, license: limit } });
            }
            if (url === '/api/v1/terminal/current') {
                return json({ error: { code: 'TERMINAL_NOT_ENROLLED' } }, 403);
            }
            return json({ data: [] });
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const show = () => render(<MemoryRouter initialEntries={['/admin/terminals']}><TerminalsPage /></MemoryRouter>);

describe('TerminalsPage till limit', () => {
    it('shows how many licensed tills are in use', async () => {
        limit = { max_terminals: 2, in_use: 2 };
        show();

        expect(await screen.findByText(/2 of 2 licensed tills in use\./)).toBeTruthy();
        expect(screen.getByText(/ask your TindaFlow provider to raise the limit/)).toBeTruthy();
    });

    it('says the license allows no new till when the limit is zero, and not "2 of 0"', async () => {
        limit = { max_terminals: 0, in_use: 2 };
        show();

        expect(await screen.findByText(/The license allows no new till right now\. Tills already set up keep working\./)).toBeTruthy();
        expect(screen.queryByText(/licensed till/)).toBeNull();
    });

    it('says nothing about a limit when none applies', async () => {
        show();

        expect(await screen.findByRole('heading', { name: 'Add terminal' })).toBeTruthy();
        expect(screen.queryByText(/licensed till|allows no new till/)).toBeNull();
    });
});
