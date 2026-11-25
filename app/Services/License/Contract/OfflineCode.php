<?php

namespace App\Services\License\Contract;

use DateTimeImmutable;

/**
 * The payload of an offline code (ADR-018 decisions 3 and 6): 23 bytes, short enough to send in a message. Two types share the
 * layout, and the type is inside what is signed: an EXTENSION keeps an installation trading when it cannot renew, and a TIME_RESET
 * pulls the trusted-time mark back after a clock was set into the future. Big-endian, in this order:
 *
 *   type 1 | key id 1 | deployment 4 | installation digest 6 | base seq 4 | days 1 | ordinal 2 | iat (unix seconds) 4
 *
 * The installation digest is the first 6 bytes of SHA-256 of the installation UUID; `base seq` ties the code to one certificate,
 * so a renewal discards it; the ordinal lets a client use each code once. Part of the contract kernel: no framework imports.
 */
final class OfflineCode
{
    public const EXTENSION = 1;

    public const TIME_RESET = 2;

    public const PAYLOAD_BYTES = 23;

    public const MAX_DAYS = 30;

    private function __construct(
        public readonly int $type,
        public readonly int $keyId,
        public readonly string $deployment,
        public readonly string $installationDigest,
        public readonly int $baseSeq,
        public readonly int $days,
        public readonly int $ordinal,
        public readonly DateTimeImmutable $iat,
    ) {}

    /** 12 lower-case hex digits. */
    public static function digestOf(string $installationId): string
    {
        return substr(hash('sha256', $installationId), 0, 12);
    }

    /** @throws CertificateRejected BAD_FIELD */
    public static function make(int $type, int $keyId, string $deployment, string $installationDigest, int $baseSeq, int $days, int $ordinal, DateTimeImmutable $iat): self
    {
        foreach ([
            'type' => $type === self::EXTENSION || $type === self::TIME_RESET,
            'key id' => $keyId >= 1 && $keyId <= 255,
            'deployment' => preg_match('/\A[0-9A-F]{8}\z/', $deployment) === 1,
            'installation digest' => preg_match('/\A[0-9a-f]{12}\z/', $installationDigest) === 1,
            'base seq' => $baseSeq >= 1 && $baseSeq <= 0xFFFFFFFF,
            // An extension carries 1 to 30 days; a time reset carries none.
            'days' => $type === self::TIME_RESET ? $days === 0 : $days >= 1 && $days <= self::MAX_DAYS,
            'ordinal' => $ordinal >= 1 && $ordinal <= 0xFFFF,
            'iat' => $iat->getTimestamp() > 0 && $iat->getTimestamp() <= 0xFFFFFFFF,
        ] as $field => $valid) {
            if (! $valid) {
                throw new CertificateRejected(CertificateRejected::BAD_FIELD, "field {$field}");
            }
        }

        return new self($type, $keyId, $deployment, $installationDigest, $baseSeq, $days, $ordinal, $iat);
    }

    public function toBytes(): string
    {
        return pack('CC', $this->type, $this->keyId)
            .hex2bin(strtolower($this->deployment))
            .hex2bin($this->installationDigest)
            .pack('NCnN', $this->baseSeq, $this->days, $this->ordinal, $this->iat->getTimestamp());
    }

    /** Reads bytes whose signature has ALREADY been checked. @throws CertificateRejected */
    public static function fromBytes(string $bytes): self
    {
        if (strlen($bytes) !== self::PAYLOAD_BYTES) {
            throw new CertificateRejected(CertificateRejected::MALFORMED, 'payload is not 23 bytes');
        }

        $head = unpack('Ctype/Ckid', $bytes);
        $tail = unpack('Nseq/Cdays/nordinal/Niat', substr($bytes, 12));

        return self::make(
            $head['type'], $head['kid'], strtoupper(bin2hex(substr($bytes, 2, 4))), bin2hex(substr($bytes, 6, 6)),
            $tail['seq'], $tail['days'], $tail['ordinal'], new DateTimeImmutable('@'.$tail['iat']),
        );
    }
}
