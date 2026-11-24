<?php

namespace App\Services\License\Contract;

use DateTimeImmutable;

/**
 * The activation certificate (ADR-018 decision 1), read from a payload that has ALREADY passed signature and canonical-form
 * checks. Validation here is strict: exactly these fields, exact types, no extras. A change to the signed payload is a new
 * `v` and an ADR amendment, never an extra field the verifier quietly ignores. Part of the contract kernel: no framework imports.
 */
final class Certificate
{
    public const VERSION = 2;

    public const TYPE = 'tindaflow.activation';

    public const MODE_ENFORCE = 'enforce';

    public const MODE_REPORT = 'report';

    public const FINGERPRINT_COMPONENTS = ['smbios_uuid', 'board_serial', 'bios_serial'];

    private const FIELDS = ['deployment', 'exp', 'fp', 'iat', 'installation', 'jti', 'kid', 'mode', 'prev', 'seats', 'seq', 'store', 'typ', 'v'];

    private const TIME_FORMAT = 'Y-m-d\TH:i:sP';

    /** @param  array<string, string>  $fingerprint  component => lower-case SHA-256 hex */
    private function __construct(
        public readonly string $kid,
        public readonly string $deployment,
        public readonly string $store,
        public readonly string $installation,
        public readonly array $fingerprint,
        public readonly int $seats,
        public readonly string $mode,
        public readonly int $seq,
        public readonly ?string $prev,
        public readonly DateTimeImmutable $iat,
        public readonly DateTimeImmutable $exp,
        public readonly string $jti,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws CertificateRejected BAD_FIELD
     */
    public static function fromPayload(array $payload): self
    {
        if (! self::sameKeys(array_keys($payload), self::FIELDS)) {
            throw self::bad('fields are not exactly the v'.self::VERSION.' set');
        }

        self::require($payload['v'] === self::VERSION, 'v');
        self::require($payload['typ'] === self::TYPE, 'typ');
        self::require(self::matches($payload['kid'], '/\A[A-Za-z0-9._-]{1,64}\z/'), 'kid');
        self::require(self::matches($payload['deployment'], '/\A[0-9A-F]{8}\z/'), 'deployment');
        self::require(self::matches($payload['store'], '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/'), 'store');
        self::require(self::matches($payload['installation'], '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/'), 'installation');
        self::require(is_int($payload['seats']) && $payload['seats'] >= 0 && $payload['seats'] <= 10000, 'seats');
        self::require(in_array($payload['mode'], [self::MODE_ENFORCE, self::MODE_REPORT], true), 'mode');
        self::require(is_int($payload['seq']) && $payload['seq'] >= 1, 'seq');
        self::require($payload['prev'] === null || self::matches($payload['prev'], '/\A[0-9a-f]{64}\z/'), 'prev');
        self::require(self::matches($payload['jti'], '/\A[0-9a-f]{32}\z/'), 'jti');

        $fingerprint = $payload['fp'];
        self::require(is_array($fingerprint) && array_keys($fingerprint) !== [] && self::sameKeys(array_keys($fingerprint), self::FINGERPRINT_COMPONENTS), 'fp');
        foreach ($fingerprint as $hash) {
            self::require(self::matches($hash, '/\A[0-9a-f]{64}\z/'), 'fp');
        }

        $iat = self::time($payload['iat'], 'iat');
        $exp = self::time($payload['exp'], 'exp');
        self::require($exp > $iat, 'exp');

        return new self(
            $payload['kid'], $payload['deployment'], $payload['store'], $payload['installation'], $fingerprint,
            $payload['seats'], $payload['mode'], $payload['seq'], $payload['prev'], $iat, $exp, $payload['jti'],
        );
    }

    private static function time(mixed $value, string $field): DateTimeImmutable
    {
        $time = is_string($value) ? DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $value) : false;

        self::require($time !== false && $time->format(self::TIME_FORMAT) === $value, $field);

        return $time;
    }

    /** @param  list<int|string>  $keys */
    private static function sameKeys(array $keys, array $wanted): bool
    {
        sort($keys);
        sort($wanted);

        return $keys === $wanted;
    }

    private static function matches(mixed $value, string $pattern): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }

    private static function require(bool $condition, string $field): void
    {
        if (! $condition) {
            throw self::bad("field {$field}");
        }
    }

    private static function bad(string $detail): CertificateRejected
    {
        return new CertificateRejected(CertificateRejected::BAD_FIELD, $detail);
    }
}
