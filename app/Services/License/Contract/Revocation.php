<?php

namespace App\Services\License\Contract;

use DateTimeImmutable;

/**
 * A vendor-signed revocation (ADR-018 decision 7): the provider's answer to a renewal when an installation's license is withdrawn
 * for breach. Only a signed revocation ever changes client state; an unsigned "revoked" answer is just a message. Read from a payload
 * that has already passed {@see SignedEnvelope}; exactly these fields, no extras. Part of the contract kernel: no framework imports.
 */
final class Revocation
{
    public const VERSION = 1;

    public const TYPE = 'tindaflow.revocation';

    private const FIELDS = ['iat', 'installation', 'kid', 'store', 'typ', 'v'];

    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    private function __construct(
        public readonly string $kid,
        public readonly string $store,
        public readonly string $installation,
        public readonly DateTimeImmutable $iat,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws CertificateRejected BAD_FIELD
     */
    public static function fromPayload(array $payload): self
    {
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== self::FIELDS) {
            throw new CertificateRejected(CertificateRejected::BAD_FIELD, 'fields are not exactly the v'.self::VERSION.' set');
        }

        foreach ([
            'v' => $payload['v'] === self::VERSION,
            'typ' => $payload['typ'] === self::TYPE,
            'kid' => is_string($payload['kid']) && preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $payload['kid']) === 1,
            'store' => is_string($payload['store']) && preg_match(self::UUID, $payload['store']) === 1,
            'installation' => is_string($payload['installation']) && preg_match(self::UUID, $payload['installation']) === 1,
        ] as $field => $ok) {
            if (! $ok) {
                throw new CertificateRejected(CertificateRejected::BAD_FIELD, "field {$field}");
            }
        }

        $iat = is_string($payload['iat']) ? DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $payload['iat']) : false;
        if ($iat === false || $iat->format('Y-m-d\TH:i:sP') !== $payload['iat']) {
            throw new CertificateRejected(CertificateRejected::BAD_FIELD, 'field iat');
        }

        return new self($payload['kid'], $payload['store'], $payload['installation'], $iat);
    }
}
