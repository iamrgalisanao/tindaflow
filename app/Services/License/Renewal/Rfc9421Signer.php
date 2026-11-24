<?php

namespace App\Services\License\Renewal;

use InvalidArgumentException;

/**
 * HTTP message signatures (RFC 9421) with Ed25519 over `@method`, `@target-uri`, `content-digest` (RFC 9530) and `content-type`,
 * as ADR-018 decision 8 requires of every machine request. The signature parameters carry `keyid` (the installation id), `tag`,
 * and the server-issued `nonce` the server measures the 5-minute window from, so a PC with a wrong clock can still sign a request
 * the server accepts. There is no `created` or `expires` here on purpose: they would be the client's own clock.
 *
 * Pure apart from the signature itself; the label is fixed (`sig1`).
 */
final class Rfc9421Signer
{
    public const LABEL = 'sig1';

    public const COMPONENTS = ['@method', '@target-uri', 'content-digest', 'content-type'];

    /** RFC 9530: `sha-256=:base64:` over the exact body bytes. */
    public static function contentDigest(string $body): string
    {
        return 'sha-256=:'.base64_encode(hash('sha256', $body, true)).':';
    }

    /**
     * The headers to send with the request.
     *
     * @return array{Content-Digest: string, Content-Type: string, Signature-Input: string, Signature: string}
     */
    public static function headers(string $method, string $url, string $body, string $contentType, string $keyId, string $tag, string $nonce, string $secretKeyBase64): array
    {
        $secretKey = base64_decode($secretKeyBase64, true);
        if ($secretKey === false || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('The installation secret key is not a 64-byte Ed25519 key.');
        }

        $digest = self::contentDigest($body);
        $params = self::params($keyId, $tag, $nonce);
        $base = self::signatureBase($method, $url, $digest, $contentType, $params);

        return [
            'Content-Digest' => $digest,
            'Content-Type' => $contentType,
            'Signature-Input' => self::LABEL.'='.$params,
            'Signature' => self::LABEL.'=:'.base64_encode(sodium_crypto_sign_detached($base, $secretKey)).':',
        ];
    }

    /** RFC 9421 section 2.5: the exact bytes that are signed. */
    public static function signatureBase(string $method, string $url, string $contentDigest, string $contentType, string $params): string
    {
        return '"@method": '.strtoupper($method)."\n"
            .'"@target-uri": '.$url."\n"
            .'"content-digest": '.$contentDigest."\n"
            .'"content-type": '.$contentType."\n"
            .'"@signature-params": '.$params;
    }

    /** The signature parameters: the covered components, then keyid, tag and nonce, each a quoted string. */
    public static function params(string $keyId, string $tag, string $nonce): string
    {
        foreach ([$keyId, $tag, $nonce] as $value) {
            if (preg_match('/\A[A-Za-z0-9._~:\/+=-]{1,256}\z/', $value) !== 1) {
                throw new InvalidArgumentException('A signature parameter holds a character outside the safe set.');
            }
        }

        return '('.implode(' ', array_map(fn (string $component) => '"'.$component.'"', self::COMPONENTS)).');keyid="'.$keyId.'";tag="'.$tag.'";nonce="'.$nonce.'"';
    }
}
