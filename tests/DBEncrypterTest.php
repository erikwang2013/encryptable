<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Contracts\DbDriverDetector;
use Erikwang2013\Encryptable\DBEncrypter;
use Erikwang2013\Encryptable\Exceptions\SerializationException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DBEncrypterTest extends TestCase
{
    private function makeDb(string $key, string $cipher, bool $postgres = false): DBEncrypter
    {
        $detector = new class ($postgres) implements DbDriverDetector {
            public function __construct(private bool $postgres)
            {
            }

            public function isPostgres(): bool
            {
                return $this->postgres;
            }
        };

        return new DBEncrypter(new KeyRingTestConfig($key, [], $cipher), $detector);
    }

    // ── Deterministic format (HMAC-prefix + ECB) ──

    public function test_aes_128_ecb_format_hmac_front_and_manual_decrypt(): void
    {
        $key = str_repeat('k', 16);
        $db = $this->makeDb($key, 'aes-128-ecb');

        $payload = $db->encrypt('hello', false);
        $decoded = base64_decode($payload, true);

        self::assertNotFalse($decoded);
        self::assertSame(hash_hmac('sha256', 'hello', hash('sha256', $key . ':hmac', true), true), substr($decoded, 0, 32));
        self::assertSame('hello', openssl_decrypt(substr($decoded, 32), 'aes-128-ecb', $key, OPENSSL_RAW_DATA));
    }

    public function test_aes_256_config_uses_aes_256_ecb(): void
    {
        $key = str_repeat('k', 32);
        $db = $this->makeDb($key, 'aes-256-gcm');

        $decoded = base64_decode($db->encrypt('hello', false), true);
        self::assertNotFalse($decoded);
        self::assertSame('hello', openssl_decrypt(substr($decoded, 32), 'aes-256-ecb', $key, OPENSSL_RAW_DATA));
    }

    public function test_encrypt_is_deterministic(): void
    {
        $db = $this->makeDb(str_repeat('k', 16), 'aes-128-ecb');

        self::assertSame($db->encrypt('same', false), $db->encrypt('same', false));
    }

    public function test_serialized_envelope_is_embedded(): void
    {
        $key = str_repeat('k', 16);
        $db = $this->makeDb($key, 'aes-128-ecb');

        $decoded = base64_decode($db->encrypt(42, true), true);
        self::assertNotFalse($decoded);
        self::assertSame('integer:42', openssl_decrypt(substr($decoded, 32), 'aes-128-ecb', $key, OPENSSL_RAW_DATA));
    }

    public function test_encrypt_unsupported_type_throws(): void
    {
        $this->expectException(SerializationException::class);
        $this->makeDb(str_repeat('k', 16), 'aes-128-ecb')->encrypt(['array']);
    }

    public function test_encrypt_null_returns_null(): void
    {
        self::assertNull($this->makeDb(str_repeat('k', 16), 'aes-128-ecb')->encrypt(null));
    }

    // ── isEncrypted ──

    public function test_is_encrypted_false_for_non_strings(): void
    {
        $db = $this->makeDb(str_repeat('k', 16), 'aes-128-ecb');

        self::assertFalse($db->isEncrypted(123));
        self::assertFalse($db->isEncrypted(null));
        self::assertFalse($db->isEncrypted(['x']));
    }

    public function test_is_encrypted_false_below_length_floor(): void
    {
        $db = $this->makeDb(str_repeat('k', 16), 'aes-128-ecb');

        // 32-byte HMAC only, no ciphertext byte
        self::assertFalse($db->isEncrypted(base64_encode(str_repeat("\x00", 32))));
        self::assertFalse($db->isEncrypted('not base64 at all'));
    }

    public function test_is_encrypted_false_for_php_format_markers(): void
    {
        $db = $this->makeDb(str_repeat('k', 16), 'aes-128-ecb');

        self::assertFalse($db->isEncrypted(base64_encode("\x01" . str_repeat("\x00", 40))));
        self::assertFalse($db->isEncrypted(base64_encode("\x02" . str_repeat("\x00", 40))));
    }

    public function test_encrypt_prevents_double_encryption(): void
    {
        $db = $this->makeDb(str_repeat('k', 16), 'aes-128-ecb');
        $payload = $db->encrypt('once', false);

        self::assertTrue($db->isEncrypted($payload));
        self::assertSame($payload, $db->encrypt($payload, false));
    }

    // ── SQL decrypt fragments ──

    public function test_mysql_fragment_structure(): void
    {
        $db = $this->makeDb(str_repeat('k', 16), 'aes-128-ecb');
        $sql = $db->decrypt('email');

        self::assertStringContainsString('CONVERT(', $sql);
        self::assertStringContainsString('SUBSTRING(', $sql);
        self::assertStringContainsString('AES_DECRYPT(', $sql);
        self::assertStringContainsString('FROM_BASE64(', $sql);
        self::assertStringContainsString('email', $sql);
        self::assertStringContainsString(str_repeat('k', 16), $sql);
    }

    public function test_postgres_fragment_structure(): void
    {
        $db = $this->makeDb(str_repeat('k', 16), 'aes-128-ecb', true);
        $sql = $db->decrypt('email');

        self::assertStringContainsString('substring(', $sql);
        self::assertStringContainsString('convert_from(', $sql);
        self::assertStringContainsString('decrypt(', $sql);
        self::assertStringContainsString("decode(", $sql);
        self::assertStringContainsString("'base64'", $sql);
        self::assertStringContainsString("'aes-ecb'", $sql);
        self::assertStringContainsString(str_repeat('k', 16), $sql);
    }

    public function test_sql_key_single_quotes_are_escaped(): void
    {
        // 16 bytes containing single quotes
        $key = "k'k'k'k'k'k'k'k'";
        $db = $this->makeDb($key, 'aes-128-ecb');

        $sql = $db->decrypt('email');

        self::assertStringContainsString(str_replace("'", "''", $key), $sql);
        self::assertStringNotContainsString("'" . $key, $sql);
    }

    public function test_qualified_column_name_is_accepted(): void
    {
        $db = $this->makeDb(str_repeat('k', 16), 'aes-128-ecb');

        self::assertIsString($db->decrypt('users.email'));
        self::assertIsString($db->decrypt('_private_col'));
    }

    public function test_invalid_column_references_throw(): void
    {
        $db = $this->makeDb(str_repeat('k', 16), 'aes-128-ecb');

        foreach (['', 'foo" bar', 'foo;DROP TABLE users', 'foo-bar', '1abc', 'a b', "o'ne"] as $bad) {
            try {
                $db->decrypt($bad);
                self::fail("Expected LogicException for column reference [{$bad}].");
            } catch (LogicException $e) {
                self::assertStringContainsString('Invalid DB column reference', $e->getMessage());
            }
        }
    }

    public function test_decrypt_null_returns_null(): void
    {
        self::assertNull($this->makeDb(str_repeat('k', 16), 'aes-128-ecb')->decrypt(null));
    }

    // ── Cipher mapping ──

    public function test_aes_128_variant_ciphers_use_aes_128_ecb(): void
    {
        foreach (['aes-128-ecb', 'aes-128-cbc', 'aes-128-gcm'] as $cipher) {
            $key = str_repeat('k', 16);
            $decoded = base64_decode($this->makeDb($key, $cipher)->encrypt('x', false), true);
            self::assertNotFalse($decoded, "cipher {$cipher}");
            self::assertSame('x', openssl_decrypt(substr($decoded, 32), 'aes-128-ecb', $key, OPENSSL_RAW_DATA), "cipher {$cipher}");
        }
    }

    public function test_aes_256_variant_ciphers_use_aes_256_ecb(): void
    {
        foreach (['aes-256-ecb', 'aes-256-cbc', 'aes-256-gcm'] as $cipher) {
            $key = str_repeat('k', 32);
            $decoded = base64_decode($this->makeDb($key, $cipher)->encrypt('x', false), true);
            self::assertNotFalse($decoded, "cipher {$cipher}");
            self::assertSame('x', openssl_decrypt(substr($decoded, 32), 'aes-256-ecb', $key, OPENSSL_RAW_DATA), "cipher {$cipher}");
        }
    }
}
