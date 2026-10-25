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
