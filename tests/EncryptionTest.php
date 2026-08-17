<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Contracts\EncryptableConfigContract;
use Erikwang2013\Encryptable\Encryption;
use Erikwang2013\Encryptable\Exceptions\SerializationException;
use Erikwang2013\Encryptable\PHPEncrypter;
use Erikwang2013\Encryptable\Support\PreviousKeysParser;
use Erikwang2013\Encryptable\Utils\Serializer;
use PHPUnit\Framework\TestCase;

final class EncryptionTest extends TestCase
{
    private string $key;

    private EncryptableConfigContract $config;

    protected function setUp(): void
    {
        $this->key = str_repeat('k', 16);
        $this->config = new KeyRingTestConfig($this->key, []);
        Encryption::setFallbackConfig($this->config);
    }

    protected function tearDown(): void
    {
        Encryption::setFallbackConfig(null);
        Encryption::setContainer(null);
        parent::tearDown();
    }

    // ── Basic encrypt/decrypt ──

    public function test_encrypt_and_decrypt_string(): void
    {
        $plain = 'hello world';
        $encrypted = Encryption::php()->encrypt($plain);

        self::assertIsString($encrypted);
        self::assertNotSame($plain, $encrypted);
        self::assertSame($plain, Encryption::php()->decrypt($encrypted));
    }

    public function test_encrypt_and_decrypt_int(): void
    {
        $plain = 42;
        $encrypted = Encryption::php()->encrypt($plain);

        self::assertIsString($encrypted);
        self::assertSame(42, Encryption::php()->decrypt($encrypted));
    }

    public function test_encrypt_and_decrypt_float(): void
    {
        $plain = 3.14;
        $encrypted = Encryption::php()->encrypt($plain);

        self::assertIsString($encrypted);
        self::assertSame(3.14, Encryption::php()->decrypt($encrypted));
    }

    public function test_encrypt_and_decrypt_bool(): void
    {
        $encrypted = Encryption::php()->encrypt(true);

        self::assertIsString($encrypted);
        self::assertTrue(Encryption::php()->decrypt($encrypted));
    }

    public function test_encrypt_and_decrypt_null(): void
    {
        self::assertNull(Encryption::php()->encrypt(null));
        self::assertNull(Encryption::php()->decrypt(null));
    }

    public function test_encrypt_null_value_directly(): void
    {
        $encrypter = new PHPEncrypter($this->config);
        self::assertNull($encrypter->encrypt(null));
    }

    // ── isEncrypted ──

    public function test_is_encrypted_detects_ciphertext(): void
    {
        $encrypted = Encryption::php()->encrypt('test');

        self::assertTrue(Encryption::isEncrypted($encrypted));
    }

    public function test_is_encrypted_returns_false_for_plaintext(): void
    {
        self::assertFalse(Encryption::isEncrypted('plain text'));
    }

    public function test_is_encrypted_returns_false_for_non_string(): void
    {
        self::assertFalse(Encryption::isEncrypted(123));
        self::assertFalse(Encryption::isEncrypted(null));
        self::assertFalse(Encryption::isEncrypted(['arr']));
    }

    // ── Double-encryption prevention ──

    public function test_double_encrypt_does_not_double_wrap(): void
    {
        $once = Encryption::php()->encrypt('double');
        $twice = Encryption::php()->encrypt($once);

        self::assertSame($once, $twice);
        self::assertSame('double', Encryption::php()->decrypt($twice));
    }

    // ── Rotation ──

    public function test_rotate_to_current_key_is_noop_for_plaintext(): void
    {
        $result = Encryption::php()->rotateToCurrentKey('plain text');
        self::assertSame('plain text', $result);
    }

    public function test_rotate_to_current_key_returns_null_for_null(): void
    {
        self::assertNull(Encryption::php()->rotateToCurrentKey(null));
    }

    // ── Serializer ──

    public function test_serializer_throws_on_unsupported_type(): void
    {
        $this->expectException(SerializationException::class);
        Serializer::serialize(['array']);
    }

    public function test_serializer_throws_on_malformed_payload(): void
    {
        $this->expectException(\Erikwang2013\Encryptable\Exceptions\UnserializationException::class);
        Serializer::unserialize('no-colon');
    }

    public function test_serializer_roundtrip_types(): void
    {
        self::assertSame('hello', Serializer::unserialize(Serializer::serialize('hello')));
        self::assertSame(42, Serializer::unserialize(Serializer::serialize(42)));
        self::assertSame(3.14, Serializer::unserialize(Serializer::serialize(3.14)));
        self::assertTrue(Serializer::unserialize(Serializer::serialize(true)));
        self::assertNull(Serializer::unserialize(Serializer::serialize(null)));
    }

    // ── Decrypt pass-through for plaintext ──

    public function test_decrypt_passes_through_unencrypted_plaintext(): void
    {
        self::assertSame('plain text', Encryption::php()->decrypt('plain text'));
        self::assertSame('123', Encryption::php()->decrypt('123'));
    }

    // ── Missing key ──

    public function test_encrypt_throws_with_empty_key(): void
    {
        $badConfig = new KeyRingTestConfig('', []);
        $encrypter = new PHPEncrypter($badConfig);

        $this->expectException(\Erikwang2013\Encryptable\Exceptions\MissingEncryptionKeyException::class);
        $encrypter->encrypt('test');
    }

    // ── PreviousKeysParser ──

    public function test_previous_keys_parser_empty_inputs(): void
    {
        self::assertSame([], PreviousKeysParser::parse(null));
        self::assertSame([], PreviousKeysParser::parse(''));
        self::assertSame([], PreviousKeysParser::parse([]));
    }

    public function test_previous_keys_parser_comma_list(): void
    {
        self::assertSame(['a', 'b', 'c'], PreviousKeysParser::parse('a, b, c'));
    }

    public function test_previous_keys_parser_json_array(): void
    {
        self::assertSame(['x', 'y'], PreviousKeysParser::parse('["x","y"]'));
    }

    public function test_previous_keys_parser_skips_empty_segments(): void
    {
        self::assertSame(['a', 'b'], PreviousKeysParser::parse('a,,b'));
    }

    public function test_previous_keys_parser_accepts_array_directly(): void
    {
        self::assertSame(['k1', 'k2'], PreviousKeysParser::parse(['k1', 'k2']));
    }

    // ── Deserialization without serialization ──

    public function test_decrypt_raw_without_unserialize(): void
    {
        $encrypter = new PHPEncrypter($this->config);
        $encrypted = $encrypter->encrypt('raw', true);
        $raw = $encrypter->decrypt($encrypted, false);

        self::assertStringStartsWith('string:', $raw);
    }

    // ── Boolean false roundtrip ──

    public function test_serializer_boolean_false_roundtrip(): void
    {
        $serialized = Serializer::serialize(false);
        self::assertSame(false, Serializer::unserialize($serialized));
        self::assertStringStartsWith('boolean:', $serialized);
        self::assertSame('boolean:0', $serialized);
    }

    // ── AEAD (GCM) encryption ──

    public function test_encrypt_and_decrypt_with_gcm_cipher(): void
    {
        $gcmKey = str_repeat('k', 32);
        $gcmConfig = new KeyRingTestConfig($gcmKey, [], 'aes-256-gcm');
        $encrypter = new PHPEncrypter($gcmConfig);

        $plain = 'gcm-encrypted-value';
        $encrypted = $encrypter->encrypt($plain);
        self::assertIsString($encrypted);
        self::assertNotSame($plain, $encrypted);
        self::assertSame($plain, $encrypter->decrypt($encrypted));
    }

    public function test_gcm_encrypted_value_is_detected(): void
    {
        $gcmKey = str_repeat('k', 32);
        $gcmConfig = new KeyRingTestConfig($gcmKey, [], 'aes-256-gcm');
        $encrypter = new PHPEncrypter($gcmConfig);

        $encrypted = $encrypter->encrypt('test');
        self::assertTrue($encrypter->isEncrypted($encrypted));
    }

    // ── Unsupported cipher (whitelist) ──

    public function test_encrypt_throws_with_invalid_cipher(): void
    {
        $badConfig = new KeyRingTestConfig($this->key, [], 'invalid-cipher-algo');
        $encrypter = new PHPEncrypter($badConfig);

        $this->expectException(\Erikwang2013\Encryptable\Exceptions\MissingEncryptionCipherException::class);
        $encrypter->encrypt('test');
    }

    // ── MissingEncryptionCipherException ──

    public function test_encrypt_throws_with_null_cipher(): void
    {
        $nullCipherConfig = new KeyRingTestConfig($this->key, [], null);
        $encrypter = new PHPEncrypter($nullCipherConfig);

        $this->expectException(\Erikwang2013\Encryptable\Exceptions\MissingEncryptionCipherException::class);
        $encrypter->encrypt('test');
    }

    // ── DBEncrypter SQL output ──

    public function test_db_decrypt_returns_mysql_fragment(): void
    {
        $mysqlDetector = new class implements \Erikwang2013\Encryptable\Contracts\DbDriverDetector {
            public function isPostgres(): bool
            {
                return false;
            }
        };

        $db = new \Erikwang2013\Encryptable\DBEncrypter($this->config, $mysqlDetector);
        $sql = $db->decrypt('base64payload');

        self::assertIsString($sql);
        self::assertStringContainsString('AES_DECRYPT', $sql);
        self::assertStringContainsString('FROM_BASE64', $sql);
    }

    public function test_db_decrypt_returns_postgres_fragment(): void
    {
        $pgDetector = new class implements \Erikwang2013\Encryptable\Contracts\DbDriverDetector {
            public function isPostgres(): bool
            {
                return true;
            }
        };

        $db = new \Erikwang2013\Encryptable\DBEncrypter($this->config, $pgDetector);
        $sql = $db->decrypt('base64payload');

        self::assertIsString($sql);
        self::assertStringContainsString('decrypt', $sql);
        self::assertStringContainsString('decode(', $sql);
        self::assertStringContainsString("'base64'", $sql);
    }

    public function test_db_encrypt_returns_deterministic_ciphertext(): void
    {
        $detector = new class implements \Erikwang2013\Encryptable\Contracts\DbDriverDetector {
            public function isPostgres(): bool
            {
                return false;
            }
        };

        $db = new \Erikwang2013\Encryptable\DBEncrypter($this->config, $detector);

        $first = $db->encrypt('test', false);
        $second = $db->encrypt('test', false);

        self::assertIsString($first);
        self::assertTrue($db->isEncrypted($first));
        self::assertSame($first, $second); // ECB is deterministic
        self::assertGreaterThanOrEqual(33, strlen((string) base64_decode($first, true)));
    }

    public function test_db_decrypt_null_returns_null(): void
    {
        $detector = new class implements \Erikwang2013\Encryptable\Contracts\DbDriverDetector {
            public function isPostgres(): bool
            {
                return false;
            }
        };

        $db = new \Erikwang2013\Encryptable\DBEncrypter($this->config, $detector);
        self::assertNull($db->decrypt(null));
    }
}
