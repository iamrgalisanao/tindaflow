import { describe, expect, it } from 'vitest';
import { AUDIT_TYPES, describeAudit } from './recordsParts';

describe('employee audit events', () => {
    it('lists every employee and credit limit event type in the filter', () => {
        const ids = AUDIT_TYPES.map((type) => type.id);
        for (const id of ['EMPLOYEE_CREATED', 'EMPLOYEE_UPDATED', 'EMPLOYEE_DEACTIVATED', 'EMPLOYEE_REACTIVATED', 'EMPLOYEES_IMPORTED', 'CREDIT_LIMIT_CHANGED']) {
            expect(ids).toContain(id);
        }
    });

    it('describes a created employee with the generated code', () => {
        const text = describeAudit({ event_type: 'EMPLOYEE_CREATED', after_metadata: { employee_code: 'TSC00001', employee_type: 'DIRECT', first_name: 'Ana', middle_name: null, last_name: 'Reyes' } });
        expect(text).toBe('Employee TSC00001 created: Ana Reyes (TSC)');
    });

    it('describes a name change, a deactivation and an import', () => {
        expect(describeAudit({ event_type: 'EMPLOYEE_UPDATED', before_metadata: { first_name: 'Maria' }, after_metadata: { first_name: 'Marie', employee_code: 'TSC00002' } })).toBe('Employee TSC00002 changed: first name Maria → Marie');
        expect(describeAudit({ event_type: 'EMPLOYEE_DEACTIVATED', after_metadata: { employee_code: 'SUB00003', name: 'Ramon Garcia' } })).toContain('SUB00003 (Ramon Garcia) deactivated');
        expect(describeAudit({ event_type: 'EMPLOYEES_IMPORTED', after_metadata: { created: 4, skipped: 2, failed: 1 } })).toBe('Employee CSV import: 4 created, 2 skipped as already enrolled, 1 with problems');
    });

    it('describes a credit limit change per type', () => {
        const text = describeAudit({ event_type: 'CREDIT_LIMIT_CHANGED', before_metadata: { limit_per_cutoff: '1000.00' }, after_metadata: { limit_per_cutoff: '1250.50', employee_type: 'SUBCONTRACTOR' } });
        expect(text).toMatch(/^SUB credit limit .*1,000\.00.* → .*1,250\.50.* per cut-off$/);
    });
});
