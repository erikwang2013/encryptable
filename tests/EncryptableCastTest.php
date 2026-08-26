<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Encryptable;
use Erikwang2013\Encryptable\Encryption;
use PHPUnit\Framework\TestCase;

final class EncryptableCastTest extends TestCase
{
    private Encryptable $cast;

    protected function setUp(): void
    {
        Encryption::setFallbackConfig(new KeyRingTestConfig(str_repeat('k', 16), [], 'aes-128-ecb'));
        $this->cast = new Encryptable;
    }

    protected function tearDown(): void
    {
        Encryption::setFallbackConfig(null);
        parent::tearDown();
    }

    public function test_get_decrypts_stored_value(): void
    {
        $payload = $this->cast->set(new \stdClass, 'email', 'user@example.com', []);

        self::assertSame('user@example.com', $this->cast->get(new \stdClass, 'email', $payload, []));
    }

    public function test_set_encrypts_value(): void
    {
        $payload = $this->cast->set(new \stdClass, 'email', 'secret', []);

        self::assertIsString($payload);
        self::assertTrue(Encryption::isEncrypted($payload));
        self::assertSame('secret', Encryption::php()->decrypt($payload));
    }

    public function test_get_null_returns_null(): void
    {
        self::assertNull($this->cast->get(new \stdClass, 'email', null, []));
    }

    public function test_set_null_returns_null(): void
    {
        self::assertNull($this->cast->set(new \stdClass, 'email', null, []));
    }

    public function test_roundtrip_preserves_scalar_types(): void
    {
        foreach ([42, 3.14, true, 'str'] as $value) {
            $payload = $this->cast->set(new \stdClass, 'attr', $value, []);
            self::assertSame($value, $this->cast->get(new \stdClass, 'attr', $payload, []));
        }
    }
}
