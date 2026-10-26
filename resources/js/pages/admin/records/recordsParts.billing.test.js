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

// ADR-016 slice 4.2: the cheque audit events.
describe('cheque audit events', () => {
    it('are in the filter and read in plain words', () => {
        const ids = AUDIT_TYPES.map((type) => type.id);
        expect(ids).toContain('BILLING_CHEQUE_RECORDED');
        expect(ids).toContain('BILLING_CHEQUE_RETURNED');
        expect(auditLabel('BILLING_CHEQUE_RECORDED')).toBe('Cheque recorded');
        expect(auditLabel('BILLING_CHEQUE_RETURNED')).toBe('Cheque returned');
        expect(describeAudit({ event_type: 'BILLING_CHEQUE_RECORDED', after_metadata: { cheque_number: '0012', bank: 'BDO', amount: '600.00', received_on: '2026-11-02', allocations: [{}, {}] } }))
            .toMatch(/Cheque 0012 from BDO for .*600\.00.* recorded, received 2026-11-02, paying 2 cut-off periods/);
        expect(describeAudit({ event_type: 'BILLING_CHEQUE_RETURNED', reason: 'Insufficient funds', after_metadata: { cheque_number: '0012', bank: 'BDO', amount: '600.00', returned_on: '2026-11-05' } }))
            .toMatch(/Cheque 0012 from BDO .*marked returned on 2026-11-05 \(Insufficient funds\); the amount is owed again/);
    });

    it('describe the balance and cheque list exports', () => {
        expect(describeAudit({ event_type: 'BILLING_EXPORTED', after_metadata: { report: 'balance', owed_amount: '200.00' } })).toMatch(/balance exported as CSV \(still owed .*200\.00/);
        expect(describeAudit({ event_type: 'BILLING_EXPORTED', after_metadata: { report: 'cheques', cheque_count: 4 } })).toBe('Cheque list exported as CSV (4 cheques)');
    });
});

// ADR-016 slice 4.3: the write-off audit events.
describe('write-off audit events', () => {
    it('are in the filter and read in plain words with the amount, period, reason and cheque', () => {
        const ids = AUDIT_TYPES.map((type) => type.id);
        expect(ids).toContain('BILLING_WRITE_OFF_RECORDED');
        expect(ids).toContain('BILLING_WRITE_OFF_REVERSED');
        expect(auditLabel('BILLING_WRITE_OFF_RECORDED')).toBe('Amount written off');
        expect(auditLabel('BILLING_WRITE_OFF_REVERSED')).toBe('Write-off reversed');
        expect(describeAudit({ event_type: 'BILLING_WRITE_OFF_RECORDED', reason: 'TSC will not pay', after_metadata: { amount: '350.00', period_start: '2026-10-01', period_end: '2026-10-15', cheque_number: '0012', bank: 'BDO', owed_before: '500.00', owed_after: '150.00' } }))
            .toMatch(/.*350\.00.* of the period 2026-10-01 to 2026-10-15 written off \(cheque 0012 from BDO\): TSC will not pay; owed went from .*500\.00.* to .*150\.00/);
        expect(describeAudit({ event_type: 'BILLING_WRITE_OFF_REVERSED', reason: 'Written off by mistake', after_metadata: { amount: '350.00', period_start: '2026-10-01', period_end: '2026-10-15', owed_before: '150.00', owed_after: '500.00' } }))
            .toMatch(/Write-off of .*350\.00.* for the period 2026-10-01 to 2026-10-15 reversed: Written off by mistake; owed went from .*150\.00.* to .*500\.00/);
    });
});
