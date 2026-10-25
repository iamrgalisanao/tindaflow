import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SlipPanel from './SlipPanel';

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));

let calls;
beforeEach(() => {
    calls = [];
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options = {}) => {
            calls.push({ url, method: options.method ?? 'GET', headers: options.headers });
            if (options.method === 'POST') {
                return json({ reprint_event_id: 'e1', is_reprint: true, reprinted_at: '2026-10-26T01:00:00Z', slip: { render_html: '<p>REPRINT slip copy</p>' } }, 201);
            }
            return json({ render_html: '<p>original slip</p>' });
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('SlipPanel', () => {
    it('opens on the plain original, read from the slip endpoint', async () => {
        render(<SlipPanel slipId="s1" slipNumber="T-01-000004" canPrint onClose={() => {}} onUnauthorized={() => {}} />);

        await waitFor(() => expect(screen.getByTitle('Slip T-01-000004')).toBeTruthy());
        expect(calls[0]).toMatchObject({ url: '/api/v1/sale-slips/s1', method: 'GET' });
        expect(screen.getByTitle('Slip T-01-000004').getAttribute('srcdoc')).toBe('<p>original slip</p>');
        expect(screen.getByText('#T-01-000004')).toBeTruthy();
    });

    it('records a copy through the reprint endpoint with an idempotency key, then shows the marked copy', async () => {
        render(<SlipPanel slipId="s1" slipNumber="T-01-000004" canPrint onClose={() => {}} onUnauthorized={() => {}} />);
        await waitFor(() => expect(screen.getByTitle('Slip T-01-000004')).toBeTruthy());

        fireEvent.click(screen.getByRole('button', { name: 'Print a copy' }));

        await waitFor(() => expect(screen.getByTitle('Slip T-01-000004').getAttribute('srcdoc')).toBe('<p>REPRINT slip copy</p>'));
        const post = calls.find((call) => call.method === 'POST');
        expect(post.url).toBe('/api/v1/sale-slips/s1/reprints');
        expect(post.headers['Idempotency-Key']).toBeTruthy();
        expect(screen.getByRole('status').textContent).toContain('No new slip number was issued');
    });

    it('cannot record a copy from a browser that is not a terminal, and never calls the document an invoice', async () => {
        const { container } = render(<SlipPanel slipId="s1" slipNumber="T-01-000004" canPrint={false} onClose={() => {}} onUnauthorized={() => {}} />);
        await waitFor(() => expect(screen.getByTitle('Slip T-01-000004')).toBeTruthy());

        expect(screen.getByRole('button', { name: 'Print a copy' }).disabled).toBe(true);
        expect(container.ownerDocument.body.textContent.toLowerCase()).not.toContain('invoice');
    });
});
