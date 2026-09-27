import { describe, expect, it } from 'vitest';
import { formatBusinessDate, isStaleBusinessDay, localDateString } from './businessDay';

describe('localDateString', () => {
    it("is the till's own calendar date, zero-padded", () => {
        expect(localDateString(new Date(2026, 0, 5, 23, 59))).toBe('2026-01-05');
        expect(localDateString(new Date(2026, 11, 31, 0, 0))).toBe('2026-12-31');
    });
});

describe('isStaleBusinessDay', () => {
    const now = new Date(2026, 8, 27, 10, 0);

    it('is stale only when the open day is dated before today', () => {
        expect(isStaleBusinessDay('2026-09-26', now)).toBe(true);
        expect(isStaleBusinessDay('2026-09-27', now)).toBe(false);
        expect(isStaleBusinessDay('2026-09-28', now)).toBe(false);
    });

    it('is never stale when the date is unknown', () => {
        expect(isStaleBusinessDay(null, now)).toBe(false);
        expect(isStaleBusinessDay(undefined, now)).toBe(false);
    });

    it('compares across month and year boundaries', () => {
        expect(isStaleBusinessDay('2025-12-31', new Date(2026, 0, 1))).toBe(true);
        expect(isStaleBusinessDay('2026-08-31', new Date(2026, 8, 1))).toBe(true);
    });
});

describe('formatBusinessDate', () => {
    it('reads the date as a calendar date, with no timezone shift', () => {
        expect(formatBusinessDate('2026-09-26')).toMatch(/26/);
        expect(formatBusinessDate('2026-09-26')).toMatch(/2026/);
        expect(formatBusinessDate('2026-01-01')).toMatch(/2026/);
    });
});
