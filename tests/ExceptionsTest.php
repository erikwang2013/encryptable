<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Exceptions\DecryptException;
use Erikwang2013\Encryptable\Exceptions\EncryptException;
use Erikwang2013\Encryptable\Exceptions\MissingEncryptionCipherException;
use Erikwang2013\Encryptable\Exceptions\MissingEncryptionKeyException;
use Erikwang2013\Encryptable\Exceptions\SerializationException;
use Erikwang2013\Encryptable\Exceptions\UnserializationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionsTest extends TestCase
{
    /** @var list<class-string<RuntimeException>> */
    private const EXCEPTIONS = [
        DecryptException::class,
        EncryptException::class,
        MissingEncryptionCipherException::class,
        MissingEncryptionKeyException::class,
        SerializationException::class,
        UnserializationException::class,
    ];

    public function test_all_exceptions_extend_runtime_exception(): void
    {
        foreach (self::EXCEPTIONS as $exception) {
            self::assertTrue(
                is_subclass_of($exception, RuntimeException::class),
                "{$exception} must extend RuntimeException."
            );
            self::assertInstanceOf($exception, new $exception);
        }
    }

    public function test_default_messages(): void
    {
        self::assertSame('', (new DecryptException)->getMessage());
        self::assertSame('', (new EncryptException)->getMessage());
        self::assertSame('No encryption cipher has been specified.', (new MissingEncryptionCipherException)->getMessage());
        self::assertSame('No encryption key has been specified.', (new MissingEncryptionKeyException)->getMessage());
        self::assertSame('The given value cannot be serialized.', (new SerializationException)->getMessage());
        self::assertSame('The given value cannot be unserialized.', (new UnserializationException)->getMessage());
    }

    public function test_custom_messages_are_preserved(): void
    {
        $custom = 'custom message';

        foreach (self::EXCEPTIONS as $exception) {
            self::assertSame($custom, (new $exception($custom))->getMessage());
        }
    }
}
