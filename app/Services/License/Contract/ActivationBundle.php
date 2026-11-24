<?php

namespace App\Services\License\Contract;

use DateTimeImmutable;

/**
 * The provider's reply to an activation request (ADR-018 decision 9): who the store is, how it is set up, the first
 * certificate, and where to renew. It is itself vendor-signed ({@see SignedEnvelope}), and bound to ONE installation by that
 * installation's UUID and public key, so a bundle made for one PC cannot activate another. Read from a payload that has already
 * passed the signature and canonical-form checks; validation here is strict (exactly these fields, exact types, no extras).
 * Business details beyond the store name (tax registration and so on) are entered on site and are deliberately not in the bundle.
 * Part of the contract kernel: no framework imports.
 */
final class ActivationBundle
{
    public const VERSION = 1;

    public const TYPE = 'tindaflow.bundle';

    private const FIELDS = [
        'certificate', 'control_plane', 'default_location', 'deployment', 'iat', 'installation', 'installation_key', 'kid',
        'sale_document_mode', 'slip_footer', 'store_id', 'store_name', 'till_code', 'typ', 'v',
    ];

    private const TIME_FORMAT = 'Y-m-d\TH:i:sP';

    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /** @param  list<string>  $controlPlane  https URLs, the primary first */
    private function __construct(
        public readonly string $kid,
        public readonly string $storeId,
        public readonly string $deployment,
        public readonly string $installation,
        public readonly string $installationKey,
        public readonly string $storeName,
        public readonly string $saleDocumentMode,
        public readonly ?string $slipFooter,
        public readonly string $defaultLocation,
        public readonly string $tillCode,
        public readonly string $certificate,
        public readonly array $controlPlane,
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
            throw self::bad('fields are not exactly the v'.self::VERSION.' set');
        }

        self::require($payload['v'] === self::VERSION, 'v');
        self::require($payload['typ'] === self::TYPE, 'typ');
        self::require(self::matches($payload['kid'], '/\A[A-Za-z0-9._-]{1,64}\z/'), 'kid');
        self::require(self::matches($payload['store_id'], self::UUID), 'store_id');
        self::require(self::matches($payload['deployment'], '/\A[0-9A-F]{8}\z/'), 'deployment');
        self::require(self::matches($payload['installation'], self::UUID), 'installation');
        self::require(is_string($payload['installation_key']) && strlen((string) base64_decode($payload['installation_key'], true)) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'installation_key');
        self::require(self::text($payload['store_name'], 1, 150), 'store_name');
        self::require($payload['sale_document_mode'] === 'SLIP' || $payload['sale_document_mode'] === 'INVOICE', 'sale_document_mode');
        self::require($payload['slip_footer'] === null || self::text($payload['slip_footer'], 1, 200), 'slip_footer');
        self::require(self::text($payload['default_location'], 1, 100), 'default_location');
        self::require(self::text($payload['till_code'], 1, 40), 'till_code');
        self::require(is_string($payload['certificate']) && $payload['certificate'] !== '' && strlen($payload['certificate']) <= 4096, 'certificate');

        $urls = $payload['control_plane'];
        self::require(is_array($urls) && array_is_list($urls) && count($urls) >= 1 && count($urls) <= 4, 'control_plane');
        foreach ($urls as $url) {
            self::require(is_string($url) && strlen($url) <= 200 && preg_match('#\Ahttps://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~/-]*)?\z#', $url) === 1, 'control_plane');
        }

        $iat = DateTimeImmutable::createFromFormat(self::TIME_FORMAT, is_string($payload['iat']) ? $payload['iat'] : '');
        self::require($iat !== false && $iat->format(self::TIME_FORMAT) === $payload['iat'], 'iat');

        return new self(
            $payload['kid'], $payload['store_id'], $payload['deployment'], $payload['installation'], $payload['installation_key'],
            $payload['store_name'], $payload['sale_document_mode'], $payload['slip_footer'], $payload['default_location'],
            $payload['till_code'], $payload['certificate'], $urls, $iat,
        );
    }

    private static function text(mixed $value, int $min, int $max): bool
    {
        return is_string($value) && mb_strlen($value) >= $min && mb_strlen($value) <= $max && $value === trim($value) && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
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
