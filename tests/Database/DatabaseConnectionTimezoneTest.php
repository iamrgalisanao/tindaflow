<?php

namespace Tests\Database;

use App\Models\Store;
use App\Models\Terminal;
use App\Models\TerminalEnrollmentToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A3 discovery: config/database.php's pgsql connection never pinned a
 * session timezone, so PostgreSQL defaulted to the server's own local
 * zone (reproduced directly as Asia/Kuala_Lumpur, not UTC) -- silently
 * shifting every timestamptz round-trip through PDO by that offset. A
 * genuinely-future terminal_enrollment_tokens.expires_at was reading
 * back as already-past. Fixed by pinning the session to UTC
 * (config/database.php's `timezone` key, applied via
 * PostgresConnector::configureTimezone()'s `SET TIME ZONE`). This test
 * pins the invariant so it cannot silently regress.
 */
class DatabaseConnectionTimezoneTest extends PostgresSchemaTestCase
{
    public function test_the_connection_session_timezone_is_utc(): void
    {
        $timezone = DB::selectOne('show timezone')->TimeZone;

        $this->assertSame('UTC', $timezone);
    }

    public function test_a_future_expiry_timestamp_does_not_shift_relative_to_application_now(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $terminal = Terminal::factory()->unenrolled()->create(['store_id' => $store->id]);

        $writtenAt = now();
        $token = TerminalEnrollmentToken::create([
            'store_id' => $store->id,
            'terminal_id' => $terminal->id,
            'token_hash' => hash('sha256', 'timezone-round-trip-check'),
            'created_by' => $admin->id,
            'expires_at' => $writtenAt->clone()->addMinutes(15),
        ]);

        $readBack = $token->refresh()->expires_at;

        // A timestamp written as "15 minutes from now" must still read
        // back as being in the future, and specifically ~15 minutes
        // ahead -- not 8 hours in the past, which is exactly what the
        // Asia/Kuala_Lumpur session-timezone bug produced.
        $this->assertTrue($readBack->isFuture(), 'a freshly-written future expiry must not read back as already past');
        $this->assertEqualsWithDelta(
            $writtenAt->clone()->addMinutes(15)->timestamp,
            $readBack->timestamp,
            2, // seconds of tolerance for test execution time
            'the round-tripped timestamp must represent the same absolute instant, not one shifted by the server\'s local UTC offset'
        );
    }
}
