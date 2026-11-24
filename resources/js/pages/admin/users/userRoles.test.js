import { describe, expect, it } from 'vitest';
import { assignableRoles, mayManageUser } from './UserFormPanel';

describe('what the Users screen offers a manager (ADR-018; the server enforces the same rule)', () => {
    it('offers an administrator all three roles', () => {
        expect(assignableRoles('ADMIN').map((role) => role.id)).toEqual(['ADMIN', 'MANAGER', 'CASHIER']);
    });

    it('does not offer a manager the ADMIN role', () => {
        expect(assignableRoles('MANAGER').map((role) => role.id)).toEqual(['MANAGER', 'CASHIER']);
    });

    it('lets an administrator manage every account', () => {
        expect(['ADMIN', 'MANAGER', 'CASHIER'].map((role) => mayManageUser('ADMIN', role))).toEqual([true, true, true]);
    });

    it('lets a manager manage cashiers and managers but not an administrator', () => {
        expect(mayManageUser('MANAGER', 'CASHIER')).toBe(true);
        expect(mayManageUser('MANAGER', 'MANAGER')).toBe(true);
        expect(mayManageUser('MANAGER', 'ADMIN')).toBe(false);
    });
});
