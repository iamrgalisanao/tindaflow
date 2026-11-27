<?php

namespace Tests\Database\Concerns;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-only middleware: the request is handled as the restricted database role the deployed application connects as
 * (database/scripts/harden_append_only_privileges.sql), and the test then goes back to the role it was in. A refusal for lack
 * of privilege is remembered even when the request merely answered 500, so that a test which expects a failure cannot hide it.
 */
final class RunsRequestsAsApplicationRole
{
    /** PostgreSQL's code for "insufficient privilege". */
    private const REFUSED = '42501';

    private const TRANSACTION_ABORTED = '25P02';

    /** @var list<string> */
    public static array $refusals = [];

    public function handle(Request $request, Closure $next): Response
    {
        // Not RESET ROLE: a test may already have put itself in the restricted role, and must stay there.
        $before = DB::selectOne('select current_user as name')->name;
        DB::unprepared('SET ROLE tindaflow_app');
        try {
            $response = $next($request);
            $failure = $response->exception ?? null;
            if ($failure instanceof QueryException && (string) $failure->getCode() === self::REFUSED) {
                self::$refusals[] = $request->method().' '.$request->path().': '.$failure->getPrevious()?->getMessage();
            }

            return $response;
        } finally {
            $this->restore($before);
        }
    }

    /**
     * A statement that failed outside any savepoint leaves the test's transaction refusing everything, this included. Rolling that
     * transaction back, as the test does when it ends, undoes the SET ROLE too, so there is nothing left to restore.
     */
    private function restore(string $role): void
    {
        try {
            DB::unprepared('SET ROLE "'.str_replace('"', '""', $role).'"');
        } catch (QueryException $failure) {
            if ((string) $failure->getCode() !== self::TRANSACTION_ABORTED) {
                throw $failure;
            }
        }
    }
}
