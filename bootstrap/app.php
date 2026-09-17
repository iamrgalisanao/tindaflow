<?php

use App\Domain\Exceptions\DomainException;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
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
            ], 429);
        });
    })->create();
