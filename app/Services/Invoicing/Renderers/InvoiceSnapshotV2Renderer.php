<?php

namespace App\Services\Invoicing\Renderers;

/**
 * Renders `invoice_snapshot_json` schema_version 2: everything version 1 held plus the machine registration data
 * (MIN, serial number, PTU and accreditation), the payments taken, and an optional discount beneficiary.
 *
 * It prints only what the snapshot recorded and never fills a gap: a store whose terminal has no PTU on file gets no
 * PTU line, not a placeholder. Which of these lines the law requires on which document, and how the accreditation
 * footer should read, is for the business or legal owner (BIR-006, docs/06-backend/stage-24-owner-decisions.md);
 * this renderer only shows the data the store has registered.
 */
final class InvoiceSnapshotV2Renderer extends InvoiceSnapshotRenderer
{
    protected function machineBlock(array $snapshot): array
    {
        $registration = $snapshot['registration'] ?? null;
        if (! is_array($registration)) {
            return [];
        }

        $parts = array_filter([
            $this->has($registration, 'min') ? 'MIN: '.$this->text($registration['min']) : null,
            $this->has($registration, 'machine_serial_number') ? 'S/N: '.$this->text($registration['machine_serial_number']) : null,
        ]);

        return $parts === [] ? [] : ['<div class="c wrap">'.implode(' &middot; ', $parts).'</div>'];
    }

    protected function beneficiaryBlock(array $snapshot): array
    {
        $beneficiary = $snapshot['discount_beneficiary'] ?? null;
        if (! is_array($beneficiary)) {
            return [];
        }

        $lines = ['<div class="sep"></div>'];
        foreach (['type' => 'Discount', 'name' => 'Name', 'id_number' => 'ID No.', 'tin' => 'TIN'] as $key => $label) {
            if ($this->has($beneficiary, $key)) {
                $lines[] = '<div class="wrap">'.$this->text($label).': '.$this->text($beneficiary[$key]).'</div>';
            }
        }
        $lines[] = '<div class="wrap">Signature: ____________________</div>';

        return $lines;
    }

    protected function paymentBlock(array $snapshot): array
    {
        $payments = $snapshot['payments'] ?? [];
        if (! is_array($payments) || $payments === []) {
            return [];
        }

        $lines = [];
        foreach ($payments as $payment) {
            $lines[] = $this->row(strtoupper((string) ($payment['method'] ?? '')), $this->money((string) ($payment['amount'] ?? '0.00')));
        }
        if ($this->positive((string) ($snapshot['change'] ?? '0.00'))) {
            $lines[] = $this->row('Change', $this->money((string) $snapshot['change']));
        }
        $lines[] = '<div class="sep"></div>';

        return $lines;
    }

    protected function authorityBlock(array $snapshot): array
    {
        $registration = $snapshot['registration'] ?? null;
        if (! is_array($registration)) {
            return [];
        }

        $lines = [];
        if ($this->has($registration, 'ptu_number')) {
            $lines[] = '<div class="wrap">PTU No.: '.$this->text($registration['ptu_number'])
                .($this->has($registration, 'ptu_date') ? ' &middot; '.$this->text($registration['ptu_date']) : '').'</div>';
        }
        if ($this->has($registration, 'accreditation_number')) {
            $accreditation = 'Accreditation No.: '.$this->text($registration['accreditation_number']);
            if ($this->has($registration, 'accreditation_date')) {
                $accreditation .= ' &middot; '.$this->text($registration['accreditation_date']);
            }
            if ($this->has($registration, 'accreditation_valid_to')) {
                $accreditation .= ' &middot; valid until '.$this->text($registration['accreditation_valid_to']);
            }
            $lines[] = '<div class="wrap">'.$accreditation.'</div>';
        }

        return $lines === [] ? [] : array_merge(['<div class="sep"></div>'], $lines);
    }
}
