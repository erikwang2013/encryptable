<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Exceptions\SerializationException;
use Erikwang2013\Encryptable\Exceptions\UnserializationException;
use Erikwang2013\Encryptable\Utils\Serializer;
use PHPUnit\Framework\TestCase;

final class SerializerTest extends TestCase
{
    public function test_serialize_scalar_formats(): void
    {
        self::assertSame('string:hello', Serializer::serialize('hello'));
        self::assertSame('integer:42', Serializer::serialize(42));
        self::assertSame('double:3.14', Serializer::serialize(3.14));
        self::assertSame('boolean:1', Serializer::serialize(true));
        self::assertSame('boolean:0', Serializer::serialize(false));
        self::assertSame('NULL:', Serializer::serialize(null));
    }

    public function test_unserialize_scalars(): void
    {
        self::assertSame('hello', Serializer::unserialize('string:hello'));
        self::assertSame(42, Serializer::unserialize('integer:42'));
        self::assertSame(3.14, Serializer::unserialize('double:3.14'));
        self::assertSame(3.0, Serializer::unserialize('double:3'));
        self::assertTrue(Serializer::unserialize('boolean:1'));
        self::assertFalse(Serializer::unserialize('boolean:0'));
        self::assertNull(Serializer::unserialize('NULL:'));
    }

    public function test_serialize_rejects_unsupported_types(): void
    {
        foreach ([['array'], new \stdClass] as $value) {
            try {
                Serializer::serialize($value);
                self::fail('Expected SerializationException for ' . gettype($value) . '.');
            } catch (SerializationException $e) {
                self::assertInstanceOf(SerializationException::class, $e);
            }
        }
    }

    public function test_serialize_rejects_resources(): void
    {
        $resource = fopen('php://memory', 'r');

        try {
            Serializer::serialize($resource);
            self::fail('Expected SerializationException for resource.');
        } catch (SerializationException $e) {
            self::assertInstanceOf(SerializationException::class, $e);
        } finally {
            fclose($resource);
        }
    }

    public function test_unserialize_rejects_payload_without_colon(): void
    {
        $this->expectException(UnserializationException::class);
        Serializer::unserialize('no-colon');
    }

    public function test_unserialize_rejects_unsupported_types(): void
    {
        foreach (['array:1', 'float:1.5', 'object:stdClass', 'resource:x'] as $payload) {
            try {
                Serializer::unserialize($payload);
                self::fail("Expected UnserializationException for [{$payload}].");
            } catch (UnserializationException $e) {
                self::assertInstanceOf(UnserializationException::class, $e);
            }
        }
    }

    public function test_unserialize_preserves_colons_inside_value(): void
    {
        self::assertSame('a:b', Serializer::unserialize('string:a:b'));
        self::assertSame('http://x:8080', Serializer::unserialize('string:http://x:8080'));
    }

    public function test_unserialize_null_ignores_payload_value(): void
    {
        self::assertNull(Serializer::unserialize('NULL:anything'));
    }

    public function test_unserialize_non_numeric_integer_coerces_to_zero(): void
    {
        // Observed settype('abc', 'integer') behavior: true, value becomes 0
        self::assertSame(0, Serializer::unserialize('integer:abc'));
    }

    public function test_roundtrip_through_serialize(): void
    {
        foreach (['s', 7, 1.5, true, false, null] as $value) {
            self::assertSame($value, Serializer::unserialize(Serializer::serialize($value)));
        }
    }
}
