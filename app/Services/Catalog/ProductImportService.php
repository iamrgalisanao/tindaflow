<?php

namespace App\Services\Catalog;

use App\Http\Requests\ProductInputRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * openapi.yaml productImport: bulk create-or-update of products from a CSV in the ProductCsv layout.
 *
 * - Products are matched on SKU (unique per store). A new SKU creates a product; an existing one is updated.
 * - A column the file does not have leaves that field alone; a blank cell clears an optional field (barcode,
 *   description, category, brand, cost) and is an error for a required one. Blank track_inventory,
 *   reorder_level and active cells leave an existing product alone (a new one gets the defaults).
 * - Rows succeed or fail independently, each in its own savepoint. A problem with the file as a whole (no
 *   `sku` column, an unrecognised heading, not UTF-8, too large) is a 422 and nothing is read further.
 * - Categories and brands are matched by name, case-insensitively; they are never created here.
 * - `$dryRun` runs everything and rolls it back, so the result reports exactly what a real import would do.
 *
 * A row's `row` number is its position in the file as a spreadsheet shows it (the heading is row 1).
 */
final class ProductImportService
{
    public const MAX_ROWS = 5000;

    public const MAX_BYTES = 5 * 1024 * 1024;

    private const REQUIRED_FOR_NEW = ['name', 'unit_of_measure', 'selling_price', 'tax_class'];

    /** @var array<string, Product> */
    private array $productsBySku = [];

    /** @var array<string, string> barcode => owning product id (own or alternate) or the sku of an earlier row */
    private array $barcodeOwners = [];

    /** @var array<string, list<string>> lower-cased name => category ids */
    private array $categories = [];

    /** @var array<string, list<string>> lower-cased name => brand ids */
    private array $brands = [];

    /**
     * @return array{created: int, updated: int, unchanged: int, failed: int, errors: list<array{row: int, message: string}>}
     */
    public function import(string $storeId, string $csv, bool $dryRun = false): array
    {
        [$header, $rows] = $this->parse($csv);
        $this->loadLookups($storeId);

        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'errors' => []];
        $seenSkus = [];

        DB::beginTransaction();

        try {
            foreach ($rows as $rowNumber => $cells) {
                [$outcome, $message] = $this->importRow($storeId, $header, $cells, $rowNumber, $seenSkus);

                if ($outcome === 'failed') {
                    $result['failed']++;
                    $result['errors'][] = ['row' => $rowNumber, 'message' => $message];
                } else {
                    $result[$outcome]++;
                }
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $result;
    }

    /**
     * @return array{0: list<string>, 1: array<int, list<string>>} the headings, and the non-blank records keyed by row number
     */
    private function parse(string $csv): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw $this->fileError('The file is larger than '.(self::MAX_BYTES / 1024 / 1024).' MB. Split it into smaller files.');
        }
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }
        if (trim($csv) === '') {
            throw $this->fileError('The file is empty. Export the catalog to get a file with the right columns.');
        }
        if (! mb_check_encoding($csv, 'UTF-8')) {
            throw $this->fileError('The file is not UTF-8 text. In Excel, save it as "CSV UTF-8 (Comma delimited)".');
        }

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $csv);
        rewind($stream);

        $headerCells = fgetcsv($stream, null, ',', '"', '');
        $header = array_map(fn ($cell) => strtolower(trim((string) $cell)), $headerCells ?: []);
        $this->checkHeader($header);

        $rows = [];
        $rowNumber = 1;
        while (($record = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rowNumber++;
            if (trim(implode('', array_map(fn ($cell) => (string) $cell, $record))) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw $this->fileError('The file has more than '.self::MAX_ROWS.' products. Split it into smaller files.');
            }
            $rows[$rowNumber] = array_map(fn ($cell) => (string) $cell, $record);
        }
        fclose($stream);

        return [$header, $rows];
    }

    /** @param  list<string>  $header */
    private function checkHeader(array $header): void
    {
        $named = array_values(array_filter($header, fn ($name) => $name !== ''));

        if (! in_array('sku', $named, true)) {
            throw $this->fileError('The first row must be a heading row that includes a "sku" column. Check that the file is comma-separated.');
        }
        if (count($named) !== count(array_unique($named))) {
            throw $this->fileError('The heading row lists the same column twice: '.implode(', ', array_keys(array_filter(array_count_values($named), fn ($count) => $count > 1))).'.');
        }
        $unknown = array_values(array_diff($named, ProductCsv::COLUMNS));
        if ($unknown !== []) {
            throw $this->fileError('Unrecognised column'.(count($unknown) > 1 ? 's' : '').': '.implode(', ', $unknown).'. Expected: '.implode(', ', ProductCsv::COLUMNS).'.');
        }
    }

    private function loadLookups(string $storeId): void
    {
        $this->productsBySku = Product::where('store_id', $storeId)->get()->keyBy('sku')->all();

        $this->barcodeOwners = [];
        foreach ($this->productsBySku as $product) {
            if ($product->barcode !== null) {
                $this->barcodeOwners[$product->barcode] = $product->id;
            }
        }
        foreach (ProductBarcode::where('store_id', $storeId)->get(['product_id', 'barcode']) as $alternate) {
            $this->barcodeOwners[$alternate->barcode] = $alternate->product_id;
        }

        $this->categories = $this->namesToIds(Category::where('store_id', $storeId)->get(['id', 'name']));
        $this->brands = $this->namesToIds(Brand::where('store_id', $storeId)->get(['id', 'name']));
    }

    /** @return array<string, list<string>> */
    private function namesToIds(iterable $named): array
    {
        $map = [];
        foreach ($named as $row) {
            $map[mb_strtolower(trim($row->name))][] = $row->id;
        }

        return $map;
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $record
     * @param  array<string, int>  $seenSkus
     * @return array{0: 'created'|'updated'|'unchanged'|'failed', 1: string|null} the outcome, and why when it failed
     */
    private function importRow(string $storeId, array $header, array $record, int $rowNumber, array &$seenSkus): array
    {
        if (count($record) !== count($header)) {
            return ['failed', 'Expected '.count($header).' values but found '.count($record).'. Check for a stray comma or an unclosed quote.'];
        }

        $cells = [];
        foreach ($header as $index => $name) {
            if ($name !== '') {
                $cells[$name] = trim(in_array($name, ProductCsv::TEXT_COLUMNS, true) ? ProductCsv::unguard(trim($record[$index])) : $record[$index]);
            }
        }

        $sku = $cells['sku'];
        if ($sku === '' || mb_strlen($sku) > 255) {
            return ['failed', $sku === '' ? 'The SKU is blank.' : 'The SKU is longer than 255 characters.'];
        }
        if (isset($seenSkus[$sku])) {
            return ['failed', "The SKU {$sku} already appears on row {$seenSkus[$sku]}."];
        }
        $seenSkus[$sku] = $rowNumber;

        $existing = $this->productsBySku[$sku] ?? null;
        $problems = [];
        $attributes = $this->attributes($cells, $existing, $problems);

        if ($existing === null) {
            $missing = array_diff(self::REQUIRED_FOR_NEW, array_keys($cells));
            if ($missing !== []) {
                $problems[] = 'This SKU is new, so the file needs '.implode(', ', array_map(fn ($name) => "a \"{$name}\" column", $missing)).' to create it.';
            }
        }
        if ($problems !== []) {
            return ['failed', implode(' ', $problems)];
        }

        try {
            $outcome = DB::transaction(fn () => $existing === null
                ? $this->create($storeId, $sku, $attributes)
                : $this->update($storeId, $existing, $attributes));

            return [$outcome, null];
        } catch (UniqueConstraintViolationException) {
            return ['failed', 'Another change took this SKU or barcode while the import was running. Import the file again.'];
        }
    }

    /**
     * @param  array<string, string>  $cells
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function attributes(array $cells, ?Product $existing, array &$problems): array
    {
        $attributes = [];

        foreach (['name' => 'The name', 'unit_of_measure' => 'The unit of measure'] as $column => $label) {
            if (array_key_exists($column, $cells)) {
                if ($cells[$column] === '') {
                    $problems[] = "{$label} is blank.";
                } elseif (mb_strlen($cells[$column]) > 255) {
                    $problems[] = "{$label} is longer than 255 characters.";
                } else {
                    $attributes[$column] = $cells[$column];
                }
            }
        }

        if (array_key_exists('description', $cells)) {
            $attributes['description'] = $cells['description'] === '' ? null : $cells['description'];
        }

        if (array_key_exists('barcode', $cells)) {
            $barcode = $cells['barcode'];
            if ($barcode === '') {
                $attributes['barcode'] = null;
            } elseif (mb_strlen($barcode) > 255) {
                $problems[] = 'The barcode is longer than 255 characters.';
            } elseif ($this->looksLikeScientificNotation($barcode)) {
                $problems[] = "The barcode {$barcode} looks like Excel turned it into scientific notation, which loses digits. Format the barcode column as Text and re-enter it.";
            } elseif (isset($this->barcodeOwners[$barcode]) && $this->barcodeOwners[$barcode] !== $existing?->id) {
                $problems[] = "The barcode {$barcode} is already assigned to another product.";
            } else {
                $attributes['barcode'] = $barcode;
            }
        }

        foreach (['category' => ['category_id', $this->categories], 'brand' => ['brand_id', $this->brands]] as $column => [$field, $names]) {
            if (array_key_exists($column, $cells)) {
                $name = $cells[$column];
                $ids = $name === '' ? [] : ($names[mb_strtolower($name)] ?? []);
                if ($name === '') {
                    $attributes[$field] = null;
                } elseif ($ids === []) {
                    $problems[] = "There is no {$column} named \"{$name}\". Create it first (Catalog > Categories and brands).";
                } elseif (count($ids) > 1) {
                    $problems[] = "There is more than one {$column} named \"{$name}\", so the import cannot tell which you mean.";
                } else {
                    $attributes[$field] = $ids[0];
                }
            }
        }

        if (array_key_exists('cost', $cells)) {
            if ($cells['cost'] === '') {
                $attributes['cost'] = null;
            } elseif (($money = $this->money($cells['cost'])) === null) {
                $problems[] = "The cost \"{$cells['cost']}\" is not a valid amount. Use digits with up to two decimal places, such as 40.00.";
            } else {
                $attributes['cost'] = $money;
            }
        }

        if (array_key_exists('selling_price', $cells)) {
            if (($money = $this->money($cells['selling_price'])) === null) {
                $problems[] = $cells['selling_price'] === ''
                    ? 'The selling price is blank.'
                    : "The selling price \"{$cells['selling_price']}\" is not a valid amount. Use digits with up to two decimal places, such as 55.00.";
            } else {
                $attributes['selling_price'] = $money;
            }
        }

        if (array_key_exists('tax_class', $cells)) {
            $taxClass = strtoupper(str_replace([' ', '-'], '_', $cells['tax_class']));
            if (! in_array($taxClass, ProductInputRequest::TAX_CLASSES, true)) {
                $problems[] = 'The tax class "'.$cells['tax_class'].'" is not one of '.implode(', ', ProductInputRequest::TAX_CLASSES).'.';
            } else {
                $attributes['tax_class'] = $taxClass;
            }
        }

        foreach (['track_inventory' => 'track_inventory', 'active' => 'active'] as $column => $field) {
            if (array_key_exists($column, $cells) && $cells[$column] !== '') {
                $flag = $this->flag($cells[$column]);
                if ($flag === null) {
                    $problems[] = "The {$column} value \"{$cells[$column]}\" must be true or false.";
                } else {
                    $attributes[$field] = $flag;
                }
            }
        }

        if (array_key_exists('reorder_level', $cells) && $cells['reorder_level'] !== '') {
            if (ctype_digit($cells['reorder_level']) && (int) $cells['reorder_level'] <= 2147483647) {
                $attributes['reorder_level'] = (int) $cells['reorder_level'];
            } else {
                $problems[] = "The reorder level \"{$cells['reorder_level']}\" must be a whole number, zero or more.";
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return 'created'
     */
    private function create(string $storeId, string $sku, array $attributes): string
    {
        $product = Product::create(array_merge(
            ['track_inventory' => true, 'reorder_level' => 0, 'active' => true],
            $attributes,
            ['store_id' => $storeId, 'sku' => $sku],
        ));

        $this->productsBySku[$sku] = $product;
        if ($product->barcode !== null) {
            $this->barcodeOwners[$product->barcode] = $product->id;
        }

        return 'created';
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return 'updated'|'unchanged'
     */
    private function update(string $storeId, Product $snapshot, array $attributes): string
    {
        $product = Product::where('store_id', $storeId)->lockForUpdate()->findOrFail($snapshot->id);
        $product->fill($attributes);
        $changed = $product->isDirty();

        if ($changed) {
            $previousBarcode = $snapshot->barcode;
            $product->save();
            if ($previousBarcode !== null && $previousBarcode !== $product->barcode) {
                unset($this->barcodeOwners[$previousBarcode]);
            }
            if ($product->barcode !== null) {
                $this->barcodeOwners[$product->barcode] = $product->id;
            }
            $this->productsBySku[$product->sku] = $product;
        }

        return $changed ? 'updated' : 'unchanged';
    }

    /** Spreadsheets drop trailing zeros ("55.00" is saved as "55"), so up to two places are accepted and completed. */
    private function money(string $text): ?string
    {
        if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $text) !== 1) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $text), 2, '');

        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    private function flag(string $text): ?bool
    {
        return match (strtolower($text)) {
            'true', '1', 'yes', 'y' => true,
            'false', '0', 'no', 'n' => false,
            default => null,
        };
    }

    private function looksLikeScientificNotation(string $text): bool
    {
        return preg_match('/^\d(\.\d+)?e\+\d+$/i', $text) === 1;
    }

    private function fileError(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }
}
