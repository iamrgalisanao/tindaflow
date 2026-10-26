<?php

namespace App\Services\Billing;

use App\Domain\CutOff;
use Carbon\CarbonImmutable;

/**
 * What a billing statement is asked for (ADR-016 section 8): one billing party, a range of Manila calendar dates (inclusive, either a
 * named cut-off or any from/to), optionally one employee type or one employee, and an "as of" instant. Entries recorded after the
 * as-of instant do not exist yet for this statement, so a statement can be reproduced as it stood on any past date.
 *
 * Range rule: a charge belongs to the range by the LOCAL (Asia/Manila) calendar date of its sale; a reversal belongs to the range of the
 * charge it reverses, whenever it was recorded (so a refund in the next cut-off reduces the original period; CREDIT-004).
 */
final class BillingCriteria
{
    /** Longest range a statement may cover, in days (inclusive of both ends): a year plus a leap day. */
    public const MAX_DAYS = 366;

    public function __construct(
        public readonly string $partyId,
        public readonly string $from,
        public readonly string $to,
        public readonly string $periodLabel,
        public readonly ?string $cutoffMonth,
        public readonly ?int $cutoffHalf,
        public readonly ?string $employeeType,
        public readonly ?string $employeeId,
        public readonly ?string $employeeCode,
        public readonly CarbonImmutable $asOf,
    ) {}

    /**
     * @param  array<string, mixed>  $input  already validated by BillingRequest
     */
    public static function fromValidated(string $partyId, array $input, CarbonImmutable $now, ?string $employeeCode = null): self
    {
        $timezone = (string) config('app.timezone');
        $cutoffMonth = $input['cutoff_month'] ?? null;
        $half = isset($input['cutoff_half']) ? (int) $input['cutoff_half'] : null;

        if ($cutoffMonth !== null) {
            $day = $half === 2 ? 16 : 1;
            $period = CutOff::containing(CarbonImmutable::createFromFormat('Y-m-d', "{$cutoffMonth}-".sprintf('%02d', $day), $timezone), $timezone);
            $from = $period->startDate();
            $to = $period->endDate();
            $label = $period->start->format('M').' '.$period->start->day.'-'.$period->end->day.', '.$period->start->year;
        } else {
            $from = (string) $input['from'];
            $to = (string) $input['to'];
            $label = "{$from} to {$to}";
        }

        $asOf = isset($input['as_of']) && $input['as_of'] !== null && $input['as_of'] !== ''
            ? CarbonImmutable::parse((string) $input['as_of'])
            : $now;

        return new self(
            $partyId, $from, $to, $label, $cutoffMonth, $cutoffMonth === null ? null : ($half === 2 ? 2 : 1),
            $input['employee_type'] ?? null, $input['employee_id'] ?? null, $employeeCode, $asOf,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'period_label' => $this->periodLabel,
            'cutoff_month' => $this->cutoffMonth,
            'cutoff_half' => $this->cutoffHalf,
            'employee_type' => $this->employeeType,
            'employee_id' => $this->employeeId,
            'employee_code' => $this->employeeCode,
            'as_of' => $this->asOf->toIso8601String(),
        ];
    }
}
