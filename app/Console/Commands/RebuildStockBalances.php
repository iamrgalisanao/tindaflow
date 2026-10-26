<?php

namespace App\Console\Commands;

use App\Services\Inventory\StockLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes `stock_balances` and `daily_stock_balances` from the ledger (invariant #44: on-hand must equal the signed sum of
 * the movements). Needed once for databases written before checkout updated balances, and as the repair
 * path if a balance ever drifts. The ledger is authoritative; this never edits a movement.
 */
class RebuildStockBalances extends Command
{
    protected $signature = 'inventory:rebuild-balances {--dry-run : Report the differences without changing anything}';

    protected $description = 'Recompute stock_balances and daily_stock_balances from the stock_movements ledger';

    public function handle(): int
    {
        $outflow = "'".implode("','", StockLedger::OUTFLOW)."'";

        $expected = DB::table('stock_movements')
            ->whereNull('stock_date') // dated (daily) movements belong to daily_stock_balances, rebuilt below
            ->selectRaw("product_id, location_id, SUM(CASE WHEN movement_type IN ({$outflow}) THEN -quantity ELSE quantity END) AS expected")
            ->groupBy('product_id', 'location_id')
            ->get()
            ->keyBy(fn ($row) => $row->product_id.'|'.$row->location_id);

        $actual = DB::table('stock_balances')->get()->keyBy(fn ($row) => $row->product_id.'|'.$row->location_id);

        $changes = [];
        foreach ($expected->keys()->merge($actual->keys())->unique() as $key) {
            $want = $expected->has($key) ? bcadd((string) $expected[$key]->expected, '0', 3) : '0.000';
            $have = $actual->has($key) ? bcadd((string) $actual[$key]->quantity_on_hand, '0', 3) : null;

            if ($have !== $want) {
                [$productId, $locationId] = explode('|', $key);
                $changes[] = ['product_id' => $productId, 'location_id' => $locationId, 'from' => $have ?? '(none)', 'to' => $want];
            }
        }

        // ADR-017 slice B: the same for the date-keyed cache of DAILY stock (product, location, stock date).
        $dailyExpected = DB::table('stock_movements')
            ->whereNotNull('stock_date')
            ->selectRaw("product_id, location_id, stock_date, SUM(CASE WHEN movement_type IN ({$outflow}) THEN -quantity ELSE quantity END) AS expected")
            ->groupBy('product_id', 'location_id', 'stock_date')
            ->get()
            ->keyBy(fn ($row) => $row->product_id.'|'.$row->location_id.'|'.$row->stock_date);
        $dailyActual = DB::table('daily_stock_balances')->get()->keyBy(fn ($row) => $row->product_id.'|'.$row->location_id.'|'.$row->stock_date);

        $dailyChanges = [];
        foreach ($dailyExpected->keys()->merge($dailyActual->keys())->unique() as $key) {
            $want = $dailyExpected->has($key) ? bcadd((string) $dailyExpected[$key]->expected, '0', 3) : '0.000';
            $have = $dailyActual->has($key) ? bcadd((string) $dailyActual[$key]->quantity_on_hand, '0', 3) : null;

            if ($have !== $want) {
                [$productId, $locationId, $stockDate] = explode('|', $key);
                $dailyChanges[] = ['product_id' => $productId, 'location_id' => $locationId, 'stock_date' => $stockDate, 'from' => $have ?? '(none)', 'to' => $want];
            }
        }

        if ($changes === [] && $dailyChanges === []) {
            $this->info('Every stock balance already equals its ledger sum.');

            return self::SUCCESS;
        }

        if ($changes !== []) {
            $this->table(['product_id', 'location_id', 'from', 'to'], $changes);
        }
        if ($dailyChanges !== []) {
            $this->table(['product_id', 'location_id', 'stock_date', 'from', 'to'], $dailyChanges);
        }
        $changeCount = count($changes) + count($dailyChanges);

        if ($this->option('dry-run')) {
            $this->warn($changeCount.' balance(s) differ. Dry run: nothing changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($changes, $dailyChanges) {
            foreach ($changes as $change) {
                DB::statement(
                    'INSERT INTO stock_balances (product_id, location_id, quantity_on_hand, updated_at) VALUES (?, ?, ?, now())
                     ON CONFLICT (product_id, location_id) DO UPDATE SET quantity_on_hand = EXCLUDED.quantity_on_hand, updated_at = now()',
                    [$change['product_id'], $change['location_id'], $change['to']],
                );
            }
            foreach ($dailyChanges as $change) {
                DB::statement(
                    'INSERT INTO daily_stock_balances (product_id, location_id, stock_date, quantity_on_hand, updated_at) VALUES (?, ?, ?, ?, now())
                     ON CONFLICT (product_id, location_id, stock_date) DO UPDATE SET quantity_on_hand = EXCLUDED.quantity_on_hand, updated_at = now()',
                    [$change['product_id'], $change['location_id'], $change['stock_date'], $change['to']],
                );
            }
        });

        $this->info($changeCount.' balance(s) rebuilt from the ledger.');

        return self::SUCCESS;
    }
}
