<?php

namespace App\Services\License\Renewal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The renewal request over HTTPS. For each URL, primary first: ask for a one-time nonce (`GET /v1/nonce`), then send
 * `POST /v1/renewals` signed per RFC 9421 over that nonce. TLS certificates are always validated, redirects are never followed,
 * and the body is sent exactly as it was hashed. A transport failure, a 429 or a 5xx moves on to the next URL; any other answer
 * (including a 409) is final, because it is the provider's real decision and a standby would only repeat or contradict it.
 */
final class HttpControlPlane implements ControlPlane
{
    private const TAG = 'tindaflow-renewal';

    private const CONTENT_TYPE = 'application/json';

    public function renew(array $urls, array $body, string $keyId, string $secretKey): ControlPlaneReply
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $errors = [];

        foreach ($urls as $base) {
            $base = rtrim($base, '/');

            try {
                $nonce = $this->nonce($base);
                $target = $base.'/v1/renewals';
                $response = $this->client()
                    ->withHeaders(Rfc9421Signer::headers('POST', $target, $json, self::CONTENT_TYPE, $keyId, self::TAG, $nonce, $secretKey))
                    ->withBody($json, self::CONTENT_TYPE)
                    ->post($target);
            } catch (ConnectionException $e) {
                $errors[] = $base.': unreachable';

                continue;
            } catch (Throwable $e) {
                $errors[] = $base.': '.$e->getMessage();

                continue;
            }

            if ($response->status() === 429 || $response->serverError()) {
                $errors[] = $base.': HTTP '.$response->status();

                continue;
            }

            $decoded = $response->json();

            return new ControlPlaneReply($response->status(), is_array($decoded) ? $decoded : null, $base);
        }

        return ControlPlaneReply::unreachable($errors === [] ? 'no control plane URL' : implode('; ', $errors));
    }

    private function nonce(string $base): string
    {
        $response = $this->client()->get($base.'/v1/nonce');
        $nonce = $response->successful() ? ($response->json('nonce') ?? null) : null;

        if (! is_string($nonce) || preg_match('/\A[A-Za-z0-9._~:\/+=-]{16,256}\z/', $nonce) !== 1) {
            throw new \RuntimeException('HTTP '.$response->status().' with no usable nonce');
        }

        return $nonce;
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->timeout(20)->connectTimeout(10)->withoutRedirecting()->withOptions(['verify' => true]);
    }
}
