import { act, cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import LicenseBanner, { LICENSE_NOTICE_REFRESH_MS, tillNoticeText } from './LicenseBanner';

const expiring = { kind: 'EXPIRING', valid_until: '2026-12-25T05:00:00+08:00', reason: null, sales_stop_at: null, sales_stopped: false };
const refused = { kind: 'NEW_SHIFT_REFUSED', valid_until: null, reason: 'EXPIRED', sales_stop_at: null, sales_stopped: false };

let answer;
let calls;
beforeEach(() => {
    calls = 0;
    answer = () => new Response(JSON.stringify({ notice: null }), { status: 200 });
    vi.stubGlobal(
        'fetch',
        vi.fn((url) => {
            expect(url).toBe('/api/v1/license/till-notice');
            calls += 1;
            return Promise.resolve(answer());
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

const answering = (notice, status = 200) => {
    answer = () => new Response(JSON.stringify({ notice }), { status });
};

describe('tillNoticeText', () => {
    it('warns, without alarm, that the license runs out and what stops then', () => {
        const shown = tillNoticeText(expiring);

        expect(shown.urgent).toBe(false);
        expect(shown.text).toContain('The license runs out on ');
        expect(shown.text).toContain('2026');
        expect(shown.text).toContain('a new shift cannot be opened');
    });

    it('tells a cashier with an open shift until when it can sell', () => {
        const shown = tillNoticeText({ ...refused, sales_stop_at: '2026-12-26T02:00:00+08:00' });

        expect(shown.urgent).toBe(true);
        expect(shown.text).toContain('The license has run out. This shift can sell until ');
        expect(shown.text).toContain('After you close it, you cannot open another.');
    });

    it('says when the open shift can no longer sell but can still be closed', () => {
        expect(tillNoticeText({ ...refused, sales_stop_at: '2026-12-26T02:00:00+08:00', sales_stopped: true }).text).toBe(
            'The license has run out. This shift can no longer sell. You can still close it. Tell your manager.',
        );
    });

    it('names each reason in plain words, and has words for one it does not know', () => {
        for (const [reason, words] of [
            ['NOT_ACTIVATED', 'not activated'],
            ['CLOCK_ROLLBACK', 'date or time looks wrong'],
            ['FINGERPRINT_MISMATCH', 'does not match this computer'],
            ['REVOKED', 'withdrawn'],
            ['CHAIN_INVALID', 'a problem with the license'],
        ]) {
            expect(tillNoticeText({ ...refused, reason }).text).toContain(words);
        }
    });

    it('says nothing when there is nothing to say', () => {
        expect(tillNoticeText(null)).toBeNull();
        expect(tillNoticeText({ kind: 'SOMETHING_NEW' })).toBeNull();
    });
});

describe('LicenseBanner', () => {
    it('shows the warning as a status and a refusal as an alert', async () => {
        answering(expiring);
        const { rerender } = render(<LicenseBanner step="open-shift" />);
        expect((await screen.findByRole('status')).textContent).toContain('The license runs out on');

        answering(refused);
        rerender(<LicenseBanner step="cart" />);

        expect((await screen.findByRole('alert')).textContent).toContain('A new shift cannot be opened.');
    });

    it('shows nothing when there is no notice or the request is refused or fails', async () => {
        for (const setUp of [() => answering(null), () => answering(expiring, 403), () => (answer = () => new Response('not json', { status: 500 }))]) {
            setUp();
            const { container, unmount } = render(<LicenseBanner step="cart" />);
            await act(() => Promise.resolve());
            expect(container.textContent).toBe('');
            unmount();
        }
    });

    it('asks again while the till sits open, and clears the warning once the license is renewed', async () => {
        vi.useFakeTimers();
        answering(expiring);
        const { container, unmount } = render(<LicenseBanner step="cart" />);
        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(container.textContent).toContain('The license runs out on');

        answering(null);
        await act(() => vi.advanceTimersByTimeAsync(LICENSE_NOTICE_REFRESH_MS));

        expect(calls).toBe(2);
        expect(container.textContent).toBe('');
        unmount();
        await vi.advanceTimersByTimeAsync(LICENSE_NOTICE_REFRESH_MS);
        expect(calls).toBe(2);
    });
});
