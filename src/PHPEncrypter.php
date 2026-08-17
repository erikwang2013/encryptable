<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable;

use Erikwang2013\Encryptable\Exceptions\DecryptException;
use Erikwang2013\Encryptable\Exceptions\EncryptException;
use Erikwang2013\Encryptable\Utils\Serializer;

class PHPEncrypter extends Encrypter
{
    private const FORMAT_V1 = "\x01";
    private const FORMAT_V2 = "\x02";
    private const HMAC_ALGO = 'sha256';
    private const HMAC_LENGTH = 32;
    private const TAG_LENGTH = 16;

    /** @var array<string, string> */
    private static array $hmacKeyCache = [];

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

        $value = $this->addDirtyBit($value);

        $cipher = $this->getEncryptionCipher();
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = $ivLength > 0 ? random_bytes($ivLength) : '';

        if ($this->isAeadCipher($cipher)) {
            $tag = '';
            $ciphertext = openssl_encrypt(
                $value,
                $cipher,
                $this->getEncryptionKey(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($ciphertext === false) {
                throw new EncryptException(
                    'OpenSSL encryption failed: ' . (openssl_error_string() ?: 'unknown error')
                );
            }

            $hmac = hash_hmac(self::HMAC_ALGO, $iv . $tag . $ciphertext, $this->getHmacKey(), true);

            return $this->base64Encode(self::FORMAT_V2 . $iv . $tag . $ciphertext . $hmac);
        }

        $ciphertext = openssl_encrypt(
            $value,
            $cipher,
            $this->getEncryptionKey(),
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($ciphertext === false) {
            throw new EncryptException(
                'OpenSSL encryption failed: ' . (openssl_error_string() ?: 'unknown error')
            );
        }

        $hmac = hash_hmac(self::HMAC_ALGO, $iv . $ciphertext, $this->getHmacKey(), true);

        return $this->base64Encode(self::FORMAT_V1 . $iv . $ciphertext . $hmac);
    }

    public function decrypt(?string $payload, bool $unserialize = true, bool $strict = false): mixed
    {
        if (is_null($payload)) {
            return null;
        }

        try {
            $decoded = $this->base64Decode($payload);
        } catch (DecryptException $e) {
            if ($strict) {
                throw $e;
            }

            return $payload;
        }

        $plain = $this->tryOpenSSLDecrypt($decoded);

        if ($plain === null) {
            if ($strict) {
                throw new DecryptException('Decryption failed: invalid ciphertext or HMAC.');
            }

            return $payload;
        }

        $plain = $this->removeDirtyBit($plain);

        if ($unserialize) {
            $plain = Serializer::unserialize($plain);
        }

        return $plain;
    }

    /**
     * Decrypt with any key in the ring, then encrypt with the current primary key (for gradual re-encryption).
     */
    public function rotateToCurrentKey(?string $payload, bool $serialize = true): ?string
    {
        if ($payload === null) {
            return null;
        }

        if (! $this->isEncrypted($payload)) {
            return $payload;
        }

        $plain = $this->decrypt($payload, $serialize, true);

        return $this->encrypt($plain, $serialize);
    }

    public function isEncrypted(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $decoded = base64_decode($value, true);

        // ponytail: format-only check (no HMAC/openssl work); a plaintext that
        // happens to look like ciphertext (base64 + \x01/\x02 prefix) is treated
        // as already encrypted — astronomically unlikely, acceptable. V0 (prefixless)
        // legacy blobs are no longer flagged; rotateToCurrentKey skips them as-is.
        if ($decoded === false || $decoded === '') {
            return false;
        }

        $version = $decoded[0];

        if ($version !== self::FORMAT_V1 && $version !== self::FORMAT_V2) {
            return false;
        }

        return strlen($decoded) >= 1 + self::HMAC_LENGTH + 1;
    }

    /**
     * Attempt decryption with all keys in the ring. Returns plaintext on success, null on failure.
     */
    private function tryOpenSSLDecrypt(string $decoded): ?string
    {
        $cipher = $this->getEncryptionCipher();

        if (str_starts_with($decoded, self::FORMAT_V2)) {
            return $this->tryDecryptV2($decoded, $cipher);
        }

        if (str_starts_with($decoded, self::FORMAT_V1) && ! $this->isAeadCipher($cipher)) {
            return $this->tryDecryptV1($decoded, $cipher);
        }

        if (! $this->isAeadCipher($cipher)) {
            return $this->tryDecryptV0($decoded, $cipher);
        }

        return null;
    }

    private function tryDecryptV1(string $data, string $cipher): ?string
    {
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = $ivLength > 0 ? substr($data, 1, $ivLength) : '';
        $hmac = substr($data, -self::HMAC_LENGTH);
        $ciphertext = substr($data, 1 + $ivLength, -self::HMAC_LENGTH);

        foreach ($this->getDecryptionKeyRing() as $key) {
            $expectedHmac = hash_hmac(self::HMAC_ALGO, $iv . $ciphertext, self::deriveHmacKey($key), true);
            if (! hash_equals($expectedHmac, $hmac)) {
                continue;
            }

            $plain = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv);
            if ($plain !== false && str_starts_with($plain, self::DIRTY_BIT_KEY)) {
                return $plain;
            }
        }

        return null;
    }

    private function tryDecryptV2(string $data, string $cipher): ?string
    {
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = $ivLength > 0 ? substr($data, 1, $ivLength) : '';
        $tag = substr($data, 1 + $ivLength, self::TAG_LENGTH);
        $hmac = substr($data, -self::HMAC_LENGTH);
        $ciphertext = substr($data, 1 + $ivLength + self::TAG_LENGTH, -self::HMAC_LENGTH);

        foreach ($this->getDecryptionKeyRing() as $key) {
            $expectedHmac = hash_hmac(self::HMAC_ALGO, $iv . $tag . $ciphertext, self::deriveHmacKey($key), true);
            if (! hash_equals($expectedHmac, $hmac)) {
                continue;
            }

            $plain = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain !== false && str_starts_with($plain, self::DIRTY_BIT_KEY)) {
                return $plain;
            }
        }

        return null;
    }

    private function isAeadCipher(string $cipher): bool
    {
        $upper = strtoupper($cipher);

        return str_contains($upper, 'GCM') || str_contains($upper, 'CCM');
    }

    private function tryDecryptV0(string $data, string $cipher): ?string
    {
        foreach ($this->getDecryptionKeyRing() as $key) {
            $plain = openssl_decrypt($data, $cipher, $key, OPENSSL_RAW_DATA);
            if ($plain !== false && str_starts_with($plain, self::DIRTY_BIT_KEY)) {
                return $plain;
            }
        }

        return null;
    }

    protected function addDirtyBit(string $value): string
    {
        // Always prefix — trusting an existing prefix strips it on decrypt,
        // corrupting round-trips of plaintext that itself starts with "crypt:".
        return self::DIRTY_BIT_KEY . $value;
    }

    protected function base64Encode(string $value): string
    {
        return base64_encode($value);
    }

    protected function base64Decode(string $payload): string
    {
        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            throw new DecryptException('Base64 decoding failed.');
        }

        return $decoded;
    }

    protected function removeDirtyBit(string $payload): string
    {
        if (! str_starts_with($payload, self::DIRTY_BIT_KEY)) {
            throw new DecryptException('Decryption failed: missing integrity marker.');
        }

        return substr($payload, strlen(self::DIRTY_BIT_KEY));
    }

    private function getHmacKey(): string
    {
        return self::deriveHmacKey($this->getEncryptionKey());
    }

    private static function deriveHmacKey(string $encryptionKey): string
    {
        // Key is fixed for the process lifetime, so the hash is safe to memoize.
        return self::$hmacKeyCache[$encryptionKey] ??= hash('sha256', $encryptionKey . ':hmac', true);
    }
}
