<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Config\EnvEncryptableConfig;
use PHPUnit\Framework\TestCase;

final class EnvConfigTest extends TestCase
{
    private const KEYS = ['ENCRYPTION_KEY', 'ENCRYPTION_CIPHER', 'ENCRYPTION_PREVIOUS_KEYS'];

    private array $envBackup = [];

    private array $serverBackup = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->envBackup[$key] = $_ENV[$key] ?? null;
            $this->serverBackup[$key] = $_SERVER[$key] ?? null;
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            if ($this->envBackup[$key] !== null) {
                $_ENV[$key] = $this->envBackup[$key];
            }
            if ($this->serverBackup[$key] !== null) {
                $_SERVER[$key] = $this->serverBackup[$key];
            }
        }
        parent::tearDown();
    }

    public function test_get_key_from_env(): void
    {
        $_ENV['ENCRYPTION_KEY'] = 'env-key';

        self::assertSame('env-key', (new EnvEncryptableConfig)->getKey());
    }

    public function test_get_key_from_server(): void
    {
        $_SERVER['ENCRYPTION_KEY'] = 'server-key';

        self::assertSame('server-key', (new EnvEncryptableConfig)->getKey());
    }

    public function test_get_key_from_getenv(): void
    {
        putenv('ENCRYPTION_KEY=putenv-key');

        self::assertSame('putenv-key', (new EnvEncryptableConfig)->getKey());
    }

    public function test_get_key_returns_null_when_unset_or_empty(): void
    {
        self::assertNull((new EnvEncryptableConfig)->getKey());

        $_ENV['ENCRYPTION_KEY'] = '';

        self::assertNull((new EnvEncryptableConfig)->getKey());
    }

    public function test_get_cipher_defaults_to_aes_256_gcm(): void
    {
        self::assertSame('aes-256-gcm', (new EnvEncryptableConfig)->getCipher());
    }

    public function test_get_cipher_from_env(): void
    {
        $_ENV['ENCRYPTION_CIPHER'] = 'aes-128-ecb';

        self::assertSame('aes-128-ecb', (new EnvEncryptableConfig)->getCipher());
    }

    public function test_get_cipher_empty_value_returns_default_not_null(): void
    {
        $_ENV['ENCRYPTION_CIPHER'] = '';

        self::assertSame('aes-256-gcm', (new EnvEncryptableConfig)->getCipher());
    }

    public function test_get_previous_keys_empty_when_unset(): void
    {
        self::assertSame([], (new EnvEncryptableConfig)->getPreviousKeys());
    }

    public function test_get_previous_keys_comma_list(): void
    {
        $_ENV['ENCRYPTION_PREVIOUS_KEYS'] = 'a, b, c';

        self::assertSame(['a', 'b', 'c'], (new EnvEncryptableConfig)->getPreviousKeys());
    }

    public function test_get_previous_keys_json_array(): void
    {
        putenv('ENCRYPTION_PREVIOUS_KEYS=["x","y"]');

        self::assertSame(['x', 'y'], (new EnvEncryptableConfig)->getPreviousKeys());
    }
}
