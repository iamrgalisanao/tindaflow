import { describe, expect, it } from 'vitest';
import { AUDIT_TYPES, auditLabel, describeAudit } from './recordsParts';

// ADR-016 slice 4.1: the billing audit events are in the audit screen's filter and read in plain words.
describe('billing audit events', () => {
    it('are listed in the filter with plain labels', () => {
        const ids = AUDIT_TYPES.map((type) => type.id);
        expect(ids).toContain('BILLING_RUN_CREATED');
        expect(ids).toContain('BILLING_EXPORTED');
        expect(auditLabel('BILLING_RUN_CREATED')).toBe('Billing run saved');
        expect(auditLabel('BILLING_EXPORTED')).toBe('Billing exported');
    });

    it('describe a saved run and an export', () => {
        expect(describeAudit({ event_type: 'BILLING_RUN_CREATED', after_metadata: { run_number: 'BR-000003', from: '2026-10-01', to: '2026-10-15', net_amount: '1200.00', employee_count: 12 } }))
            .toMatch(/Billing run BR-000003 saved for 2026-10-01 to 2026-10-15: total due .*1,200\.00.* for 12 employees/);
        expect(describeAudit({ event_type: 'BILLING_RUN_CREATED', after_metadata: { run_number: 'BR-000004', from: 'a', to: 'b', employee_type: 'SUBCONTRACTOR', net_amount: '0.00', employee_count: 0 } })).toContain('only');
        expect(describeAudit({ event_type: 'BILLING_EXPORTED', after_metadata: { run_number: 'BR-000003', net_amount: '1200.00' } })).toMatch(/Billing run BR-000003 exported as CSV/);
        expect(describeAudit({ event_type: 'BILLING_EXPORTED', after_metadata: { from: '2026-10-01', to: '2026-10-15', net_amount: '5.00' } })).toMatch(/statement for 2026-10-01 to 2026-10-15 exported as CSV/);
    });
});
