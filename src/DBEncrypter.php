<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable;

use Erikwang2013\Encryptable\Contracts\DbDriverDetector;
use Erikwang2013\Encryptable\Contracts\EncryptableConfigContract;
use Erikwang2013\Encryptable\Exceptions\EncryptException;
use Erikwang2013\Encryptable\Utils\Serializer;
use LogicException;

/**
 * DB-level encryption uses a dedicated deterministic format, NOT the PHPEncrypter format:
 *
 *     dbCiphertext = base64( hmac32 + openssl_encrypt(plaintext, 'aes-256-ecb'|'aes-128-ecb', key, OPENSSL_RAW_DATA) )
 *     hmac32       = hash_hmac('sha256', plaintext, hash('sha256', key.':hmac', true), true)
 *
 * - The 32-byte HMAC sits at the FRONT so SQL can strip it with `SUBSTRING(..., 33)` /
 *   `substring(... from 33)` and get the raw plaintext. No colon-splitting — values containing
 *   `:` are no longer truncated (the old MySQL SUBSTRING_INDEX / Postgres split_part bug is gone).
 * - ECB is deterministic: the same plaintext always yields the same ciphertext, so equality
 *   comparisons, WHERE clauses and unique indexes on the encrypted column work.
 * - This format is NOT interchangeable with PHPEncrypter payloads (\x01/\x02 prefix + trailing HMAC
 *   + random IV). Never mix both formats in one column.
 * - SQL decrypt fragments use the primary key only; rotating DB-side ciphertext requires
 *   re-encryption (e.g. read with PHP decrypt after migrating keys, or an ALTER pipeline).
 * - serialize=true embeds the {@see Serializer} envelope (same as PHPEncrypter). For columns used
 *   in query comparisons use serialize=false so the stored plaintext is the raw string.
 */
class DBEncrypter extends Encrypter
{
    private const HMAC_ALGO = 'sha256';
    private const HMAC_LENGTH = 32;

    public function __construct(
        EncryptableConfigContract $encryptableConfig,
        protected DbDriverDetector $driverDetector
    ) {
        parent::__construct($encryptableConfig);
    }

    /**
     * Lightweight heuristic: strict base64 + length floor (32-byte HMAC + 1 byte ciphertext) +
     * first byte is not a PHP-format marker (\x01/\x02). Intentionally cheap — no decryption.
     * A false positive on arbitrary base64 strings is possible; a false negative (~2/256, when a
     * DB HMAC happens to start with \x01/\x02) would double-encrypt.
     */
    public function isEncrypted(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false || strlen($decoded) < self::HMAC_LENGTH + 1) {
            return false;
        }

        $first = $decoded[0];

        return $first !== "\x01" && $first !== "\x02";
    }

    public function encrypt(mixed $value, bool $serialize = true): ?string
    {
        if (is_null($value)) {
            return null;
        }

        if ($this->isEncrypted($value)) {
            return $value;
        }

        if ($serialize) {
            $value = Serializer::serialize($value);
        }

        $key = $this->getEncryptionKey();
        $hmac = hash_hmac(self::HMAC_ALGO, $value, self::deriveHmacKey($key), true);

        $ciphertext = openssl_encrypt($value, $this->getEcbCipher(), $key, OPENSSL_RAW_DATA);
        if ($ciphertext === false) {
            throw new EncryptException(
                'OpenSSL encryption failed: ' . (openssl_error_string() ?: 'unknown error')
            );
        }

        return base64_encode($hmac . $ciphertext);
    }

    /**
     * @param string $payload column name (validated identifier — never interpolated raw)
     *
     * @return string|null SQL fragment that decrypts the column, or null for null input
     */
    public function decrypt(?string $payload, bool $unserialize = true): mixed
    {
        if (is_null($payload)) {
            return null;
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $payload) !== 1) {
            throw new LogicException(
                "Invalid DB column reference [{$payload}]. Expected a column name matching ^[A-Za-z_][A-Za-z0-9_.]*$."
            );
        }

        if ($this->driverDetector->isPostgres()) {
            return sprintf(
                $this->getPostgresGrammarDecrypt(),
                $payload,
                $this->escapeSqlString($this->getEncryptionKey())
            );
        }

        return sprintf(
            $this->getMysqlGrammarDecrypt(),
            $payload,
            $this->escapeSqlString($this->getEncryptionKey())
        );
    }

    private function getEcbCipher(): string
    {
        // aes-128-* configs have 16-byte keys; everything else uses 32-byte keys.
        return str_starts_with($this->getEncryptionCipher(), 'aes-128') ? 'aes-128-ecb' : 'aes-256-ecb';
    }

    private static function deriveHmacKey(string $encryptionKey): string
    {
        return hash('sha256', $encryptionKey . ':hmac', true);
    }

    private function escapeSqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    protected function getMysqlGrammarDecrypt(): string
    {
        // SUBSTRING(str, 33) is 1-based: skips the 32-byte HMAC prefix, leaves raw plaintext.
        return "CONVERT( SUBSTRING( AES_DECRYPT( FROM_BASE64(%s), '%s' ), 33 ) USING 'UTF8' )";
    }

    protected function getPostgresGrammarDecrypt(): string
    {
        // 'aes-ecb' explicitly — pgcrypto's default 'aes' is CBC and requires an IV.
        return "substring( convert_from( decrypt( decode(%s, 'base64'), '%s', 'aes-ecb' ), 'UTF8' ) from 33 )";
    }
}
