<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserSummaryResource;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml Auth tag -- authLogin/authLogout/authMe. Human session
 * authentication only (Module A Decision Register SS5): standard Laravel
 * database-backed session auth, no bearer tokens/JWT/Sanctum/Passport.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (! Auth::guard('web')->attempt($credentials)) {
            // Unknown email and wrong password both land here, identically --
            // Auth::attempt() never distinguishes them externally.
            throw new AuthenticationException('These credentials do not match our records.');
        }

        if (! Auth::guard('web')->user()->active) {
            // Module A Decision Register Ruling 2: an inactive user cannot
            // establish a session. Tear down the session attempt() just
            // created and return the SAME generic failure as a wrong
            // password -- never reveal that the credentials were otherwise
            // correct.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException('These credentials do not match our records.');
        }

        // Session fixation: always issue a fresh session ID after a
        // successful authentication, never reuse the pre-login ID.
        $request->session()->regenerate();

        return (new UserSummaryResource(Auth::guard('web')->user()))->response();
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        return (new UserSummaryResource(Auth::guard('web')->user()))->response();
    }
}
