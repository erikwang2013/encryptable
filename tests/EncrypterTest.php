<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Exceptions\MissingEncryptionCipherException;
use Erikwang2013\Encryptable\Exceptions\MissingEncryptionKeyException;
use Erikwang2013\Encryptable\PHPEncrypter;
use PHPUnit\Framework\TestCase;

final class EncrypterTest extends TestCase
{
    // ── cipher() ──

    public function test_cipher_returns_normalized_lowercase(): void
    {
        $encrypter = new PHPEncrypter(new KeyRingTestConfig(str_repeat('k', 32), [], 'AES-256-GCM'));

        self::assertSame('aes-256-gcm', $encrypter->cipher());
    }

    // ── Key validation ──

    public function test_key_length_must_match_cipher_256(): void
    {
        $encrypter = new PHPEncrypter(new KeyRingTestConfig(str_repeat('k', 16), [], 'aes-256-gcm'));

        $this->expectException(MissingEncryptionKeyException::class);
        $this->expectExceptionMessage('The encryption key must be 32 bytes for cipher [aes-256-gcm].');
        $encrypter->encrypt('x');
    }

    public function test_key_length_must_match_cipher_128(): void
    {
        $encrypter = new PHPEncrypter(new KeyRingTestConfig(str_repeat('k', 32), [], 'aes-128-gcm'));

        $this->expectException(MissingEncryptionKeyException::class);
        $this->expectExceptionMessage('The encryption key must be 16 bytes for cipher [aes-128-gcm].');
        $encrypter->encrypt('x');
    }

    public function test_empty_key_throws_with_default_message(): void
    {
        $encrypter = new PHPEncrypter(new KeyRingTestConfig('', []));

        $this->expectException(MissingEncryptionKeyException::class);
        $this->expectExceptionMessage('No encryption key has been specified.');
        $encrypter->encrypt('x');
    }

    public function test_invalid_base64_prefixed_key_throws(): void
    {
        $encrypter = new PHPEncrypter(new KeyRingTestConfig('base64:!!!', [], 'aes-256-gcm'));

        $this->expectException(MissingEncryptionKeyException::class);
        $this->expectExceptionMessage('The encryption key is not valid base64.');
        $encrypter->encrypt('x');
    }

    public function test_base64_prefixed_16_byte_key_works_for_aes_128(): void
    {
        $key = 'base64:' . base64_encode(str_repeat('k', 16));
        $encrypter = new PHPEncrypter(new KeyRingTestConfig($key, [], 'aes-128-gcm'));

        self::assertSame('v', $encrypter->decrypt($encrypter->encrypt('v')));
    }

    // ── Cipher validation ──

    public function test_empty_cipher_throws_with_default_message(): void
    {
        $encrypter = new PHPEncrypter(new KeyRingTestConfig(str_repeat('k', 32), [], null));

        $this->expectException(MissingEncryptionCipherException::class);
        $this->expectExceptionMessage('No encryption cipher has been specified.');
        $encrypter->encrypt('x');
    }

    public function test_unsupported_cipher_throws_with_name_in_message(): void
    {
        $encrypter = new PHPEncrypter(new KeyRingTestConfig(str_repeat('k', 32), [], 'aes-256-ctr'));

        $this->expectException(MissingEncryptionCipherException::class);
        $this->expectExceptionMessage('Unsupported encryption cipher [aes-256-ctr].');
        $encrypter->encrypt('x');
    }

    // ── Previous-key ring ──

    public function test_previous_key_ring_decrypts_after_primary_change(): void
    {
        $old = str_repeat('o', 16);
        $new = str_repeat('n', 16);

        $payload = (new PHPEncrypter(new KeyRingTestConfig($old, [])))->encrypt('ring', true);

        $encrypter = new PHPEncrypter(new KeyRingTestConfig($new, [$old, $old], 'aes-128-ecb'));

        self::assertSame('ring', $encrypter->decrypt($payload, true));
    }

    public function test_previous_key_equal_to_primary_is_ignored(): void
    {
        $key = str_repeat('k', 16);
        $payload = (new PHPEncrypter(new KeyRingTestConfig($key, [])))->encrypt('self', true);

        // previous list contains only the primary itself → ring still functional
        $encrypter = new PHPEncrypter(new KeyRingTestConfig($key, [$key], 'aes-128-ecb'));

        self::assertSame('self', $encrypter->decrypt($payload, true));
    }

    public function test_previous_key_ring_ignores_blank_entries(): void
    {
        $key = str_repeat('k', 16);
        $payload = (new PHPEncrypter(new KeyRingTestConfig($key, [])))->encrypt('blank', true);

        $encrypter = new PHPEncrypter(new KeyRingTestConfig($key, ['', '  '], 'aes-128-ecb'));

        self::assertSame('blank', $encrypter->decrypt($payload, true));
    }

    // ── Caching (process-lifetime memoization) ──

    public function test_key_and_cipher_are_cached_after_first_use(): void
    {
        $config = new KeyRingTestConfig(str_repeat('a', 16), [], 'aes-128-ecb');
        $encrypter = new PHPEncrypter($config);

        $payload = $encrypter->encrypt('cached', true);

        // Mutate config after first use: cached key/cipher must still be used
        $config->key = str_repeat('b', 16);
        $config->cipher = 'aes-256-gcm';

        self::assertSame('cached', $encrypter->decrypt($payload, true));
    }

    public function test_decryption_ring_is_cached_after_first_use(): void
    {
        $old = str_repeat('o', 16);
        $config = new KeyRingTestConfig(str_repeat('n', 16), [$old], 'aes-128-ecb');
        $encrypter = new PHPEncrypter($config);

        $payload = (new PHPEncrypter(new KeyRingTestConfig($old, [])))->encrypt('ring-cache', true);
        self::assertSame('ring-cache', $encrypter->decrypt($payload, true));

        // Drop the retired key after the ring was built: cached ring still contains it
        $config->previousKeys = [];

        self::assertSame('ring-cache', $encrypter->decrypt($payload, true));
    }
}
