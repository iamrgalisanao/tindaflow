<?php

namespace App\Services\Employees;

use App\Enums\EmployeeType;
use App\Models\Employee;
use App\Models\User;
use App\Services\Catalog\ProductCsv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * openapi.yaml employeeImport: bulk creation of employees from a CSV (columns first_name, middle_name, last_name, employee_type;
 * `middle_name` is optional, and `employee_type` takes DIRECT, SUBCONTRACTOR, TSC or SUB in any case).
 *
 * - Every created row gets a generated code from the same locked counters as the form (the file never supplies one).
 * - Re-importing creates NEW employees, except that an exact duplicate -- same type and the same first, middle and last name,
 *   ignoring case and extra spaces -- of an employee already in the store (active or not) or of an earlier row of the same file is
 *   SKIPPED and reported, so uploading the same file twice does not enrol everyone twice. Two different people who really share a
 *   name are added one at a time on the form.
 * - Rows succeed or fail independently, each in its own savepoint, so a failed row does not burn a code. A problem with the file as
 *   a whole (no heading, an unknown column, not UTF-8, too large) is a 422 and nothing is read further.
 * - `$dryRun` runs everything and rolls it back; the result lists the codes a real import would issue if nothing else is created
 *   in between.
 *
 * A row's `row` number is its position in the file as a spreadsheet shows it (the heading is row 1).
 */
final class EmployeeImportService
{
    public const MAX_ROWS = 2000;

    public const MAX_BYTES = 1024 * 1024;

    public const COLUMNS = ['first_name', 'middle_name', 'last_name', 'employee_type'];

    private const REQUIRED_COLUMNS = ['first_name', 'last_name', 'employee_type'];

    public function __construct(private EmployeeService $employees, private EmployeeCodeAllocator $codes, private EmployeeAuditor $auditor) {}

    /**
     * @return array{created: int, skipped: int, failed: int, errors: list<array{row: int, message: string}>, skipped_rows: list<array{row: int, message: string}>, employees: list<array{row: int, id: string, employee_code: string, name: string, employee_type: string}>}
     */
    public function import(User $actor, string $csv, bool $dryRun = false): array
    {
        [$header, $rows] = $this->parse($csv);
        $result = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'skipped_rows' => [], 'employees' => []];
        $batchId = (string) Str::uuid();

        DB::beginTransaction();

        try {
            // Both counters first, in a fixed order, so two imports running together cannot deadlock on them.
            $this->codes->lockAll($actor->store_id);
            $known = Employee::where('store_id', $actor->store_id)->get(['employee_type', 'first_name', 'middle_name', 'last_name'])
                ->mapWithKeys(fn (Employee $e) => [EmployeeService::nameKey($e->employee_type, $e->first_name, $e->middle_name, $e->last_name) => true])
                ->all();

            foreach ($rows as $rowNumber => $record) {
                $outcome = $this->importRow($actor, $batchId, $header, $record, $known);

                if ($outcome['status'] === 'created') {
                    $result['created']++;
                    $result['employees'][] = ['row' => $rowNumber] + $outcome['employee'];
                } elseif ($outcome['status'] === 'skipped') {
                    $result['skipped']++;
                    $result['skipped_rows'][] = ['row' => $rowNumber, 'message' => $outcome['message']];
                } else {
                    $result['failed']++;
                    $result['errors'][] = ['row' => $rowNumber, 'message' => $outcome['message']];
                }
            }

            $this->auditor->imported($actor, $batchId, [
                'rows' => count($rows),
                'created' => $result['created'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                'codes' => array_column($result['employees'], 'employee_code'),
                'sha256' => hash('sha256', $csv),
            ]);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $result;
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $record
     * @param  array<string, true>  $known  name keys already in the store or earlier in the file; updated here
     * @return array{status: 'created'|'skipped'|'failed', message?: string, employee?: array<string, string>}
     */
    private function importRow(User $actor, string $batchId, array $header, array $record, array &$known): array
    {
        if (count($record) !== count($header)) {
            return ['status' => 'failed', 'message' => 'Expected '.count($header).' values but found '.count($record).'. Check for a stray comma or an unclosed quote.'];
        }

        $cells = [];
        foreach ($header as $index => $name) {
            if ($name !== '') {
                $cells[$name] = EmployeeService::tidy(ProductCsv::unguard(trim($record[$index])));
            }
        }

        $problems = [];
        foreach (['first_name' => 'first name', 'last_name' => 'last name'] as $field => $label) {
            if (($cells[$field] ?? '') === '') {
                $problems[] = "The {$label} is blank.";
            }
        }
        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            if (mb_strlen($cells[$field] ?? '') > 255) {
                $problems[] = 'The '.str_replace('_', ' ', $field).' is longer than 255 characters.';
            }
        }
        $type = EmployeeType::fromLabel($cells['employee_type'] ?? '');
        if ($type === null) {
            $problems[] = 'The employee type "'.($cells['employee_type'] ?? '').'" is not DIRECT, SUBCONTRACTOR, TSC or SUB.';
        }
        if ($problems !== []) {
            return ['status' => 'failed', 'message' => implode(' ', $problems)];
        }

        $middle = ($cells['middle_name'] ?? '') === '' ? null : $cells['middle_name'];
        $key = EmployeeService::nameKey($type, $cells['first_name'], $middle, $cells['last_name']);
        if (isset($known[$key])) {
            return ['status' => 'skipped', 'message' => 'Skipped: '.implode(' ', array_filter([$cells['first_name'], $middle, $cells['last_name']])).' is already an employee of this type (or appears earlier in the file).'];
        }

        // create() runs in its own savepoint inside the import's transaction, so a row that fails leaves no code behind.
        $employee = $this->employees->create($actor, [
            'employee_type' => $type->value, 'first_name' => $cells['first_name'], 'middle_name' => $middle, 'last_name' => $cells['last_name'],
        ], $batchId);

        $known[$key] = true;

        return ['status' => 'created', 'employee' => [
            'id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'name' => $employee->fullName(),
            'employee_type' => $employee->employee_type->value,
        ]];
    }

    /**
     * @return array{0: list<string>, 1: array<int, list<string>>} the headings, and the non-blank records keyed by row number
     */
    private function parse(string $csv): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw $this->fileError('The file is larger than 1 MB. Split it into smaller files.');
        }
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }
        if (trim($csv) === '') {
            throw $this->fileError('The file is empty. It needs a heading row: '.implode(', ', self::COLUMNS).'.');
        }
        if (! mb_check_encoding($csv, 'UTF-8')) {
            throw $this->fileError('The file is not UTF-8 text. In Excel, save it as "CSV UTF-8 (Comma delimited)".');
        }

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $csv);
        rewind($stream);

        $header = array_map(fn ($cell) => strtolower(trim((string) $cell)), fgetcsv($stream, null, ',', '"', '') ?: []);
        $named = array_values(array_filter($header, fn ($name) => $name !== ''));
        $missing = array_diff(self::REQUIRED_COLUMNS, $named);
        if ($missing !== []) {
            throw $this->fileError('The first row must be a heading row with the column'.(count($missing) > 1 ? 's' : '').' '.implode(', ', $missing).'. Check that the file is comma-separated.');
        }
        if (count($named) !== count(array_unique($named))) {
            throw $this->fileError('The heading row lists the same column twice.');
        }
        $unknown = array_values(array_diff($named, self::COLUMNS));
        if ($unknown !== []) {
            throw $this->fileError('Unrecognised column'.(count($unknown) > 1 ? 's' : '').': '.implode(', ', $unknown).'. Codes are generated; the columns are '.implode(', ', self::COLUMNS).'.');
        }

        $rows = [];
        $rowNumber = 1;
        while (($record = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rowNumber++;
            if (trim(implode('', array_map(fn ($cell) => (string) $cell, $record))) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw $this->fileError('The file has more than '.self::MAX_ROWS.' employees. Split it into smaller files.');
            }
            $rows[$rowNumber] = array_map(fn ($cell) => (string) $cell, $record);
        }
        fclose($stream);

        return [$header, $rows];
    }

    private function fileError(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }
}
