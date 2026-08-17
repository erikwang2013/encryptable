<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\DBEncrypter;
use Erikwang2013\Encryptable\Exceptions\DecryptException;
use Erikwang2013\Encryptable\Exceptions\MissingEncryptionCipherException;
use Erikwang2013\Encryptable\Exceptions\MissingEncryptionKeyException;
use Erikwang2013\Encryptable\PHPEncrypter;
use Erikwang2013\Encryptable\Rules\ExistsEncrypted;
use Erikwang2013\Encryptable\Rules\UniqueEncrypted;
use LogicException;
use PHPUnit\Framework\TestCase;

final class EncrypterBehaviorTest extends TestCase
{
    private ?string $savedCipherEnv = null;

    protected function tearDown(): void
    {
        if ($this->savedCipherEnv === null) {
            unset($_ENV['ENCRYPTION_CIPHER']);
        } else {
            $_ENV['ENCRYPTION_CIPHER'] = $this->savedCipherEnv;
        }
        putenv('ENCRYPTION_CIPHER');
        parent::tearDown();
    }

    // ── Key validation ──

    public function test_16_byte_key_with_256_cipher_throws(): void
    {
        $config = new KeyRingTestConfig(str_repeat('k', 16), [], 'aes-256-ecb');

        $this->expectException(MissingEncryptionKeyException::class);
        (new PHPEncrypter($config))->encrypt('test');
    }

    public function test_base64_prefixed_key_is_decoded_and_usable(): void
    {
        $key = 'base64:' . base64_encode(random_bytes(32));
        $encrypter = new PHPEncrypter(new KeyRingTestConfig($key, [], 'aes-256-gcm'));

        $encrypted = $encrypter->encrypt('secret');

        self::assertNotSame('secret', $encrypted);
        self::assertSame('secret', $encrypter->decrypt($encrypted));
    }

    // ── Cipher whitelist ──

    public function test_unsupported_cipher_des_ecb_throws(): void
    {
        $config = new KeyRingTestConfig(str_repeat('k', 16), [], 'des-ecb');

        $this->expectException(MissingEncryptionCipherException::class);
        (new PHPEncrypter($config))->encrypt('test');
    }

    public function test_unsupported_cipher_rc4_throws(): void
    {
        $config = new KeyRingTestConfig(str_repeat('k', 16), [], 'rc4');

        $this->expectException(MissingEncryptionCipherException::class);
        (new PHPEncrypter($config))->encrypt('test');
    }

    public function test_uppercase_aes_256_gcm_cipher_works(): void
    {
        $encrypter = new PHPEncrypter(new KeyRingTestConfig(str_repeat('k', 32), [], 'AES-256-GCM'));

        self::assertSame('roundtrip', $encrypter->decrypt($encrypter->encrypt('roundtrip')));
    }

    // ── Dirty-bit collision ──

    public function test_plaintext_starting_with_dirty_bit_roundtrips(): void
    {
        $encrypter = new PHPEncrypter(new KeyRingTestConfig(str_repeat('k', 16), [], 'aes-128-ecb'));

        $encrypted = $encrypter->encrypt('crypt:secret', false);

        self::assertSame('crypt:secret', $encrypter->decrypt($encrypted, false));
    }

    // ── Strict mode ──

    public function test_tampered_payload_passes_through_in_non_strict_mode(): void
    {
        $encrypter = new PHPEncrypter(new KeyRingTestConfig(str_repeat('k', 16), [], 'aes-128-ecb'));
        $decoded = base64_decode($encrypter->encrypt('strict-value'), true);
        $decoded[strlen($decoded) - 1] = $decoded[strlen($decoded) - 1] === "\x00" ? "\x01" : "\x00";
        $tampered = base64_encode($decoded);

        self::assertSame($tampered, $encrypter->decrypt($tampered, true, false));
    }

    public function test_tampered_payload_throws_in_strict_mode(): void
    {
        $encrypter = new PHPEncrypter(new KeyRingTestConfig(str_repeat('k', 16), [], 'aes-128-ecb'));
        $decoded = base64_decode($encrypter->encrypt('strict-value'), true);
        $decoded[strlen($decoded) - 1] = $decoded[strlen($decoded) - 1] === "\x00" ? "\x01" : "\x00";
        $tampered = base64_encode($decoded);

        $this->expectException(DecryptException::class);
        $encrypter->decrypt($tampered, true, true);
    }

    // ── DB deterministic format ──

    public function test_db_encrypt_format_128(): void
    {
        $this->assertDbFormat(str_repeat('k', 16), 'aes-128-ecb', 'aes-128-ecb', 'hello');
    }

    public function test_db_encrypt_format_256(): void
    {
        $this->assertDbFormat(str_repeat('k', 32), 'aes-256-gcm', 'aes-256-ecb', 'hello');
    }

    public function test_db_encrypt_is_deterministic(): void
    {
        $db = $this->makeDbEncrypter(str_repeat('k', 16), 'aes-128-ecb');

        self::assertSame($db->encrypt('hello', false), $db->encrypt('hello', false));
    }

    public function test_db_decrypt_rejects_invalid_column_reference(): void
    {
        $db = $this->makeDbEncrypter(str_repeat('k', 16), 'aes-128-ecb');

        $this->expectException(LogicException::class);
        $db->decrypt('foo" bar');
    }

    // ── Rules deterministic-cipher guard ──

    public function test_unique_encrypted_throws_with_gcm_cipher(): void
    {
        $this->setCipherEnv('aes-256-gcm');

        $this->expectException(LogicException::class);
        new UniqueEncrypted('users', 'email');
    }

    public function test_exists_encrypted_throws_with_gcm_cipher(): void
    {
        $this->setCipherEnv('aes-256-gcm');

        $this->expectException(LogicException::class);
        new ExistsEncrypted('users', 'email');
    }

    public function test_rules_construct_with_ecb_cipher(): void
    {
        if (! class_exists(\Illuminate\Validation\Rules\Unique::class)) {
            self::markTestSkipped('Illuminate validation is not installed in this dev environment.');
        }

        $this->setCipherEnv('aes-256-ecb');

        self::assertInstanceOf(UniqueEncrypted::class, new UniqueEncrypted('users', 'email'));
        self::assertInstanceOf(ExistsEncrypted::class, new ExistsEncrypted('users', 'email'));
    }

    // ── Helpers ──

    private function makeDbEncrypter(string $key, string $cipher): DBEncrypter
    {
        $detector = new class implements \Erikwang2013\Encryptable\Contracts\DbDriverDetector {
            public function isPostgres(): bool
            {
                return false;
            }
        };

        return new DBEncrypter(new KeyRingTestConfig($key, [], $cipher), $detector);
    }

    private function assertDbFormat(string $key, string $cipher, string $ecbCipher, string $plain): void
    {
        $db = $this->makeDbEncrypter($key, $cipher);
        $payload = $db->encrypt($plain, false);

        self::assertIsString($payload);
        $decoded = base64_decode($payload, true);
        self::assertNotFalse($decoded);
        self::assertGreaterThanOrEqual(33, strlen($decoded));

        $hmac = substr($decoded, 0, 32);
        $ciphertext = substr($decoded, 32);

        self::assertSame(hash_hmac('sha256', $plain, hash('sha256', $key . ':hmac', true), true), $hmac);
        self::assertSame($plain, openssl_decrypt($ciphertext, $ecbCipher, $key, OPENSSL_RAW_DATA));
    }

    private function setCipherEnv(string $cipher): void
    {
        $this->savedCipherEnv = $_ENV['ENCRYPTION_CIPHER'] ?? null;
        $_ENV['ENCRYPTION_CIPHER'] = $cipher;
        putenv("ENCRYPTION_CIPHER={$cipher}");
    }
}
