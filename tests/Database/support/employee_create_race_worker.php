<?php

/**
 * Standalone worker process for EmployeeCodeConcurrencyTest: a real OS process with its own PostgreSQL connection, released at a
 * file barrier together with its competitors, that creates employees through the real service.
 *
 * argv: [1]=jobFile (JSON) [2]=readyFile [3]=goFile [4]=outputFile [5]=databaseName
 *
 * job: {"user_id", "employee_type", "count", "prefix"}
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Models\User;
use App\Services\Employees\EmployeeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Database\PostgresTestConnection;

[, $jobFile, $readyFile, $goFile, $outputFile, $databaseName] = $argv;

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(PostgresTestConnection::settings($databaseName));
DB::purge('pgsql');
DB::setDefaultConnection('pgsql');

$job = json_decode(file_get_contents($jobFile), true);

file_put_contents($readyFile, '1');

$deadline = microtime(true) + 15;
while (! file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        file_put_contents($outputFile, json_encode(['outcome' => 'exception', 'class' => 'BarrierTimeout', 'message' => 'timed out waiting for barrier']));
        exit(1);
    }
    usleep(500);
}

try {
    $user = User::findOrFail($job['user_id']);
    $service = $app->make(EmployeeService::class);
    $codes = [];
    for ($i = 1; $i <= $job['count']; $i++) {
        $codes[] = $service->create($user, ['employee_type' => $job['employee_type'], 'first_name' => $job['prefix'].$i, 'last_name' => 'Worker'])->employee_code;
    }

    file_put_contents($outputFile, json_encode(['outcome' => 'success', 'codes' => $codes]));
} catch (Throwable $e) {
    file_put_contents($outputFile, json_encode([
        'outcome' => 'exception',
        'class' => (new ReflectionClass($e))->getShortName(),
        'message' => $e->getMessage(),
    ]));
}
