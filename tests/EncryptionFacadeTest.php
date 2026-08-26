<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Contracts\EncryptableConfigContract;
use Erikwang2013\Encryptable\Encryption;
use Erikwang2013\Encryptable\Exceptions\MissingEncryptionKeyException;
use Erikwang2013\Encryptable\PHPEncrypter;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class EncryptionFacadeTest extends TestCase
{
    private EncryptableConfigContract $config;

    protected function setUp(): void
    {
        $this->config = new KeyRingTestConfig(str_repeat('k', 16), [], 'aes-128-ecb');
    }

    protected function tearDown(): void
    {
        Encryption::setFallbackConfig(null);
        Encryption::setContainer(null);
        Encryption::setResolver(null);
        Container::setInstance(new Container);
        parent::tearDown();
    }

    public function test_php_resolves_fallback_config_without_container(): void
    {
        Encryption::setFallbackConfig($this->config);

        $encrypted = Encryption::php()->encrypt('fallback');

        self::assertIsString($encrypted);
        self::assertSame('fallback', Encryption::php()->decrypt($encrypted));
    }

    public function test_db_resolution_fails_without_any_binding(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unable to resolve/');
        Encryption::db();
    }

    public function test_php_fallback_without_config_uses_env(): void
    {
        $_ENV['ENCRYPTION_KEY'] = str_repeat('k', 16);
        $_ENV['ENCRYPTION_CIPHER'] = 'aes-128-ecb';

        try {
            self::assertSame('env-key', Encryption::php()->decrypt(Encryption::php()->encrypt('env-key')));
        } finally {
            unset($_ENV['ENCRYPTION_KEY'], $_ENV['ENCRYPTION_CIPHER']);
        }
    }

    public function test_php_throws_when_env_key_missing(): void
    {
        unset($_ENV['ENCRYPTION_KEY'], $_SERVER['ENCRYPTION_KEY']);
        putenv('ENCRYPTION_KEY');
        Encryption::setFallbackConfig(null);

        $this->expectException(MissingEncryptionKeyException::class);
        Encryption::php()->encrypt('x');
    }

    public function test_resolver_is_used_and_instances_are_cached(): void
    {
        $resolves = 0;
        $encrypter = new PHPEncrypter($this->config);

        Encryption::setResolver(function () use (&$resolves, $encrypter) {
            $resolves++;

            return $encrypter;
        });

        Encryption::php()->encrypt('a');
        Encryption::php()->encrypt('b');

        self::assertSame(1, $resolves, 'Resolver must be invoked only once per abstract.');
    }

    public function test_set_resolver_clears_resolved_cache(): void
    {
        Encryption::setFallbackConfig($this->config);
        $first = Encryption::php()->encrypt('cache-flush');

        // Fallback change triggers cache flush → next resolve uses the new config (GCM)
        Encryption::setFallbackConfig(new KeyRingTestConfig(str_repeat('k', 32), [], 'aes-256-gcm'));
        $second = Encryption::php()->encrypt('cache-flush');

        self::assertNotSame($first, $second);
        $marker = base64_decode($second, true);
        self::assertSame("\x02", $marker[0], 'Second resolve should use the new GCM config (V2 format).');
    }

    public function test_psr_container_binding_is_used(): void
    {
        $stub = new class extends PHPEncrypter {
            public function __construct()
            {
            }

            public function encrypt(mixed $value, bool $serialize = true): ?string
            {
                return 'stub:' . (string) $value;
            }
        };

        $container = new class ($stub) implements ContainerInterface {
            public function __construct(private object $encrypter)
            {
            }

            public function get(string $id): mixed
            {
                return $this->encrypter;
            }

            public function has(string $id): bool
            {
                return $id === PHPEncrypter::class;
            }
        };

        Encryption::setContainer($container);

        self::assertSame('stub:hello', Encryption::php()->encrypt('hello'));
    }

    public function test_app_container_binding_is_used(): void
    {
        $app = new Container;
        Container::setInstance($app);

        $stub = new class extends PHPEncrypter {
            public function __construct()
            {
            }

            public function encrypt(mixed $value, bool $serialize = true): ?string
            {
                return 'app-stub:' . (string) $value;
            }
        };

        $app->instance(PHPEncrypter::class, $stub);

        self::assertSame('app-stub:x', Encryption::php()->encrypt('x'));
    }

    public function test_db_rotate_to_current_key_is_rejected(): void
    {
        Encryption::setFallbackConfig($this->config);

        // db() requires a resolver/container binding; bind a DBEncrypter via resolver
        Encryption::setResolver(function (string $abstract) {
            $detector = new class implements \Erikwang2013\Encryptable\Contracts\DbDriverDetector {
                public function isPostgres(): bool
                {
                    return false;
                }
            };

            return new \Erikwang2013\Encryptable\DBEncrypter(new KeyRingTestConfig(str_repeat('k', 16), [], 'aes-128-ecb'), $detector);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rotateToCurrentKey is only supported for Encryption::php().');
        Encryption::db()->rotateToCurrentKey('anything');
    }

    public function test_static_is_encrypted(): void
    {
        Encryption::setFallbackConfig($this->config);

        self::assertTrue(Encryption::isEncrypted(Encryption::php()->encrypt('x')));
        self::assertFalse(Encryption::isEncrypted('plain'));
        self::assertFalse(Encryption::isEncrypted(null));
    }
}
