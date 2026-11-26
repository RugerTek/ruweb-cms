<?php

namespace App\Services\SupportAccess;

/**
 * ES256 (ECDSA P-256 + SHA-256) signatures in the two encodings that meet here: the JWS / Azure Key Vault form, the
 * 64 bytes r||s (RFC 7518 §3.4), which is what travels in a pass, and the ASN.1 DER SEQUENCE { r, s } that OpenSSL
 * signs and verifies (docs/operations/support-access.md#signature).
 */
final class Es256Signature
{
    public const RAW_LENGTH = 64;

    private const HALF = 32;

    /** DER `SEQUENCE { INTEGER r, INTEGER s }` for a raw r||s signature; null when it is not 64 bytes or r/s is zero. */
    public static function rawToDer(string $raw): ?string
    {
        if (strlen($raw) !== self::RAW_LENGTH) {
            return null;
        }
        $integers = '';
        foreach ([substr($raw, 0, self::HALF), substr($raw, self::HALF)] as $part) {
            $part = ltrim($part, "\0");
            if ($part === '') {
                return null;
            }
            if (ord($part[0]) >= 0x80) {
                $part = "\0".$part;
            }
            $integers .= "\x02".chr(strlen($part)).$part;
        }

        return "\x30".chr(strlen($integers)).$integers;
    }

    /** Raw r||s (64 bytes) of a DER ECDSA P-256 signature; null when the DER is not exactly that. */
    public static function derToRaw(string $der): ?string
    {
        $length = strlen($der);
        if ($length < 8 || $der[0] !== "\x30" || ord($der[1]) !== $length - 2) {
            return null;
        }
        $offset = 2;
        $raw = '';
        for ($i = 0; $i < 2; $i++) {
            if ($offset + 2 > $length || $der[$offset] !== "\x02") {
                return null;
            }
            $size = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $size);
            if ($size === 0 || strlen($value) !== $size) {
                return null;
            }
            $value = ltrim($value, "\0");
            if ($value === '' || strlen($value) > self::HALF) {
                return null;
            }
            $raw .= str_pad($value, self::HALF, "\0", STR_PAD_LEFT);
            $offset += 2 + $size;
        }

        return $offset === $length ? $raw : null;
    }
}
