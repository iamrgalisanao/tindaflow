<?php

namespace Database\Factories;

use App\Models\FiscalInstallation;
use App\Models\Terminal;
use App\Models\TerminalFiscalInstallation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TerminalFiscalInstallation>
 */
class TerminalFiscalInstallationFactory extends Factory
{
    protected $model = TerminalFiscalInstallation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // terminal_id and fiscal_installation_id must both resolve to the
        // SAME store as store_id (composite FKs), so both are created
        // eagerly against one shared store rather than via two
        // independent Model::factory() refs.
        $fiscalInstallation = FiscalInstallation::factory()->create();
        $terminal = Terminal::factory()->create(['store_id' => $fiscalInstallation->store_id]);

        return [
            'store_id' => $fiscalInstallation->store_id,
            'terminal_id' => $terminal->id,
            'fiscal_installation_id' => $fiscalInstallation->id,
            'effective_from' => now(),
            'effective_to' => null,
        ];
    }
}
