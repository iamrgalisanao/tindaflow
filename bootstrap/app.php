<?php

use App\Domain\Exceptions\DomainException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Database\ConcurrencyFailure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        // This is a JSON API: a guest is never redirected to a login page (there is no `login` route). Without this,
        // an unauthenticated request that does not send `Accept: application/json` -- a CSV export fetched with
        // `Accept: text/csv`, a link opened in a browser -- died with a 500 (RouteNotFoundException) instead of
        // the AUTHENTICATION_REQUIRED 401 rendered below, so an expired session looked like a server fault.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // error-catalog.md's standard envelope, wired to HTTP rendering
        // for the first time here -- DomainException::toErrorEnvelope()
        // already existed (built for Stage 6C) but nothing rendered it
        // until this pass, since no controller/route existed yet.
        $exceptions->render(function (DomainException $e, Request $request) {
            $envelope = $e->toErrorEnvelope();
            $envelope['error']['request_id'] = $request->attributes->get('request_id');

            return response()->json($envelope, $e->httpStatus());
        });

        // A deadlock or serialization failure means PostgreSQL rolled the transaction back because it lost a race with
        // another request, not because this request was wrong. Left alone it is a 500; it is the existing retryable
        // CONCURRENCY_CONFLICT instead, and every write that matters carries an Idempotency-Key, so the client can
        // simply send it again. Nothing from the driver (SQL, bindings, the message) reaches the response; the
        // log keeps it. Any other database error still falls through to the framework's 500.
        // docs/06-backend/stage-27-deadlock-handling.md.
        $exceptions->render(function (QueryException|DeadlockException $e, Request $request) {
            if (! ConcurrencyFailure::caused($e)) {
                return null;
            }

            Log::warning('A database operation lost a race (deadlock or serialization failure) and was returned as a retryable 409', [
                'sql_state' => ConcurrencyFailure::sqlState($e),
                'request_id' => $request->attributes->get('request_id'),
                'path' => $request->path(),
            ]);

            return response()->json([
                'error' => [
                    'code' => 'CONCURRENCY_CONFLICT',
                    'message' => 'Another request was updating the same records at the same moment. Nothing was saved, so it is safe to try again.',
                    'details' => ['retryable' => true],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 409, ['Retry-After' => '1']);
        });

        // No/invalid session, bad login credentials, inactive-at-login,
        // and inactive-session-on-next-request all converge on this same
        // AuthenticationException -> AUTHENTICATION_REQUIRED mapping
        // (Module A Decision Register SS13) -- no INVALID_CREDENTIALS/
        // USER_INACTIVE/SESSION_EXPIRED code is invented.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'error' => [
                    'code' => 'AUTHENTICATION_REQUIRED',
                    'message' => $e->getMessage(),
                    'details' => [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 401);
        });

        // A2: authenticated but lacking the required capability.
        // AuthenticationException (401 AUTHENTICATION_REQUIRED, above)
        // and this (403 AUTHORIZATION_DENIED) are deliberately separate
        // exception types/renderers -- EnsureUserIsActive always runs
        // ahead of any `can:` middleware, so an inactive user's stale
        // session never reaches this one. No missing-capability name or
        // policy class is leaked; the frozen contract names neither.
        //
        // Registered against AccessDeniedHttpException, not
        // Illuminate\Auth\Access\AuthorizationException directly: Laravel's
        // own Handler::prepareException() unconditionally rewraps a
        // status-less AuthorizationException into this Symfony exception
        // before any custom render() callback runs (confirmed by reading
        // the framework source after a callback registered for
        // AuthorizationException itself never fired) -- Gate::authorize()
        // and the `can:` middleware both throw the Illuminate exception,
        // so by the time anything downstream can render it, it is always
        // this one.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            return response()->json([
                'error' => [
                    'code' => 'AUTHORIZATION_DENIED',
                    'message' => $e->getMessage(),
                    'details' => [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 403);
        });

        // Login throttling (SS14 Ruling 6): RATE_LIMITED is emitted
        // directly here, with no domain exception class in the business
        // layer, exactly as the Decision Register specifies.
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            return response()->json([
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => 'Too many attempts. Please try again later.',
                    'details' => [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 429, $e->getHeaders());
        });

        // error-catalog.md VALIDATION_FAILED (A1 correction): every
        // operation's 422 UnprocessableEntity response already referenced
        // this same envelope with no code registered for a FormRequest's
        // own structural/shape validation failure (as opposed to a
        // domain-specific 422, which is a DomainException handled above).
        // Centralized here rather than per-FormRequest so no future
        // FormRequest needs its own failedValidation() override.
        $exceptions->render(function (ValidationException $e, Request $request) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                    'details' => $e->errors(),
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], $e->status);
        });
    })->create();
