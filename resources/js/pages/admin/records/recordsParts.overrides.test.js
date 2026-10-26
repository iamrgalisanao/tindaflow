import { describe, expect, it } from 'vitest';
import { AUDIT_TYPES, describeAudit } from './recordsParts';

// ADR-016 slice 3.3: the supervisor override's audit events read in plain words on the audit screen and are in its filter.
describe('credit override audit events', () => {
    it('lists every override event type in the filter', () => {
        const ids = AUDIT_TYPES.map((type) => type.id);
        for (const id of ['CREDIT_OVERRIDE_GRANTED', 'CREDIT_OVERRIDE_USED', 'CREDIT_OVERRIDE_REFUSED', 'CREDIT_OVERRIDE_LOCKED', 'OVERRIDE_CODE_SET', 'OVERRIDE_CODE_RESET']) {
            expect(ids).toContain(id);
        }
    });

    it('describes a grant and its use with the amounts', () => {
        expect(describeAudit({ event_type: 'CREDIT_OVERRIDE_GRANTED', after_metadata: { max_amount: '1600.00', employee_code: 'TSC00012', limit: '1500.00', used: '1490.00' } }))
            .toMatch(/approved one credit sale of up to .*1,600\.00.* for TSC00012 \(limit .*1,500\.00.*, used .*1,490\.00.*\)/);
        expect(describeAudit({ event_type: 'CREDIT_OVERRIDE_USED', after_metadata: { amount: '1600.00', employee_code: 'TSC00012', limit: '1500.00', used: '1490.00' } }))
            .toMatch(/let a credit sale of .*1,600\.00.* for TSC00012 go past the limit of .*1,500\.00/);
    });

    it('gives the reason of a refusal and of a lockout, and never needs a code to do it', () => {
        expect(describeAudit({ event_type: 'CREDIT_OVERRIDE_REFUSED', reason: 'WRONG_CODE', after_metadata: { employee_code: 'TSC00012' } })).toBe('A manager approval was refused (wrong code) for TSC00012');
        expect(describeAudit({ event_type: 'CREDIT_OVERRIDE_REFUSED', reason: 'ALREADY_USED', after_metadata: {} })).toBe('A manager approval was refused (approval already used)');
        expect(describeAudit({ event_type: 'CREDIT_OVERRIDE_REFUSED', reason: 'SOMETHING_NEW', after_metadata: {} })).toContain('SOMETHING_NEW');
        expect(describeAudit({ event_type: 'CREDIT_OVERRIDE_LOCKED', after_metadata: { locked_minutes: 15 } })).toBe('A manager was locked out of approving for 15 minutes after too many wrong codes');
    });

    it('describes setting, changing and clearing a code', () => {
        expect(describeAudit({ event_type: 'OVERRIDE_CODE_SET', before_metadata: { has_code: false }, after_metadata: {} })).toBe('Override code set by its owner');
        expect(describeAudit({ event_type: 'OVERRIDE_CODE_SET', before_metadata: { has_code: true }, after_metadata: {} })).toBe('Override code changed by its owner');
        expect(describeAudit({ event_type: 'OVERRIDE_CODE_RESET', before_metadata: { has_code: true, locked: true }, after_metadata: { user_name: 'Mia Manager', role: 'MANAGER' } }))
            .toBe('Override code of Mia Manager (MANAGER) cleared by an administrator (it was locked)');
    });
});

// ADR-017 slice A: the stock rules and the manager approval of a blocked sale read in plain words on the audit screen and are in its filter.
describe('stock audit events', () => {
    it('lists every stock rule and override event type in the filter', () => {
        const ids = AUDIT_TYPES.map((type) => type.id);
        for (const id of ['STOCK_RULES_CHANGED', 'STOCK_OVERRIDE_GRANTED', 'STOCK_OVERRIDE_USED', 'STOCK_OVERRIDE_REFUSED', 'STOCK_OVERRIDE_LOCKED']) {
            expect(ids).toContain(id);
        }
    });

    it('describes a rule change field by field with the old and the new value', () => {
        const text = describeAudit({
            event_type: 'STOCK_RULES_CHANGED',
            before_metadata: { block_sale_without_stock: false, stock_override_enabled: true, low_stock_default_threshold: 3 },
            after_metadata: { block_sale_without_stock: true, stock_override_enabled: true, low_stock_default_threshold: 5, unsellable_count_when_enabled: 4 },
        });

        expect(text).toBe('Stock rules changed: block a sale without stock off → on, low-stock warning 3 left → 5 left');
    });

    it('names the items, what was needed and what was there, for an approval and for the sale that used it', () => {
        const after = { shortfalls: [{ name: 'Coke', required: '3.000', available: '1.000' }] };

        expect(describeAudit({ event_type: 'STOCK_OVERRIDE_GRANTED', after_metadata: after })).toBe('A manager approved one sale that is short of stock: Coke (needed 3, had 1)');
        expect(describeAudit({ event_type: 'STOCK_OVERRIDE_USED', after_metadata: after })).toContain('A sale went through without enough stock on a manager\'s approval: Coke (needed 3, had 1)');
    });

    it('gives the reason of a refusal, and the lockout, without needing a code', () => {
        expect(describeAudit({ event_type: 'STOCK_OVERRIDE_REFUSED', reason: 'DEMAND_CHANGED', after_metadata: {} })).toBe('A stock approval was refused (the cart changed after it was approved)');
        expect(describeAudit({ event_type: 'STOCK_OVERRIDE_REFUSED', reason: 'WRONG_CODE', after_metadata: {} })).toBe('A stock approval was refused (wrong code)');
        expect(describeAudit({ event_type: 'STOCK_OVERRIDE_LOCKED', after_metadata: { locked_minutes: 15 } })).toBe('A manager was locked out of approving for 15 minutes after too many wrong codes');
    });

    it('shows a product whose own reorder level was cleared as "store default"', () => {
        expect(describeAudit({ event_type: 'PRODUCT_UPDATED', before_metadata: { reorder_level: 8 }, after_metadata: { sku: 'COK-1', reorder_level: null } })).toContain('reorder level 8 → store default');
    });
});

// ADR-017 slice B: the daily stock entry (with who entered and who approved), the approval trail and the ala carte action read in plain words.
describe('daily stock audit events', () => {
    it('lists the daily stock event types in the filter', () => {
        const ids = AUDIT_TYPES.map((type) => type.id);
        for (const id of ['DAILY_STOCK_ENTERED', 'STOCK_ENTRY_APPROVED', 'STOCK_ENTRY_APPROVAL_REFUSED', 'STOCK_ENTRY_APPROVAL_LOCKED', 'ALA_CARTE_ITEMS_CREATED']) {
            expect(ids).toContain(id);
        }
    });

    it('says what was entered, and when a manager approved a cashier\'s entry', () => {
        const lines = [{ name: 'Beef nilaga', delta: '20.000', cooked_after: '20.000' }, { name: 'Pinakbet', delta: '0.000', cooked_after: '5.000' }, { name: 'Siopao', delta: '-3.000', cooked_after: '9.000' }];

        expect(describeAudit({ event_type: 'DAILY_STOCK_ENTERED', after_metadata: { stock_date: '2026-10-27', approved_by: null, lines } })).toBe('Daily stock for 2026-10-27 entered: Beef nilaga +20 (now 20 cooked), Siopao -3 (now 9 cooked)');
        expect(describeAudit({ event_type: 'DAILY_STOCK_ENTERED', after_metadata: { stock_date: '2026-10-27', approved_by: 'mgr', lines: lines.slice(0, 1) } })).toContain('by a cashier with a manager\'s approval');
        expect(describeAudit({ event_type: 'STOCK_ENTRY_APPROVAL_REFUSED', reason: 'WRONG_STOCK_DATE', after_metadata: {} })).toBe('A daily stock approval was refused (approval was for another day)');
    });

    it('shows the stock day hour in a rule change', () => {
        expect(describeAudit({ event_type: 'STOCK_RULES_CHANGED', before_metadata: { stock_day_cutover_hour: 0 }, after_metadata: { stock_day_cutover_hour: 5 } })).toBe('Stock rules changed: stock day starts at 00:00 → 05:00');
    });

    it('names the ala carte items created and how many were skipped', () => {
        expect(describeAudit({ event_type: 'ALA_CARTE_ITEMS_CREATED', after_metadata: { created: [{ name: 'Beef nilaga (ala carte)' }], skipped: [{ reason: 'ALREADY_EXISTS' }] } })).toBe('Ala carte items created for dishes: Beef nilaga (ala carte) (1 skipped)');
    });
});
