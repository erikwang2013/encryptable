<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable;

use Erikwang2013\Encryptable\Config\ArrayEncryptableConfig;
use Erikwang2013\Encryptable\Config\EnvDbDriverDetector;
use Erikwang2013\Encryptable\Config\EnvEncryptableConfig;
use Erikwang2013\Encryptable\Contracts\EncryptableConfigContract;
use Erikwang2013\Encryptable\Support\Mascot;
use Psr\Container\ContainerInterface;
use RuntimeException;

class Encryption
{
    private static ?ContainerInterface $container = null;

    private static ?EncryptableConfigContract $fallbackConfig = null;

    /** @var array<string, Encrypter> Resolved instances keyed by abstract class name */
    private static array $resolved = [];

    /** @var null|callable(string): Encrypter */
    private static $resolver = null;

    private Encrypter $encrypter;

    public function __construct(Encrypter $encrypter)
    {
        $this->encrypter = $encrypter;
    }

    public static function setContainer(?ContainerInterface $container): void
    {
        self::$container = $container;
        self::$resolved = [];
    }

    public static function setFallbackConfig(?EncryptableConfigContract $config): void
    {
        self::$fallbackConfig = $config;
        self::$resolved = [];
    }

    /**
     * 原生 PHP（无框架、无容器）的一步式配置入口：
     *
     *     Encryption::configure(['key' => '…32 字节…', 'cipher' => 'aes-256-gcm', 'db_driver' => 'pgsql']);
     *
     * 支持 {@code key}、{@code cipher}、{@code previous_keys}、{@code db_driver}。
     * 容器绑定与 {@see self::setResolver()} 优先级更高：Laravel/Hyperf 等场景不会被这里覆盖。
     * 等价于 {@code Encryption::setFallbackConfig(new ArrayEncryptableConfig($config))}。
     *
     * @param array{key?: mixed, cipher?: mixed, previous_keys?: mixed, db_driver?: mixed} $config
     */
    public static function configure(array $config): void
    {
        self::setFallbackConfig(new ArrayEncryptableConfig($config));
    }

    /**
     * @param null|callable(string): Encrypter $resolver Passing null restores default fallback resolution.
     */
    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver;
        self::$resolved = [];
    }

    public static function php(): self
    {
        return new self(
            self::resolve(PHPEncrypter::class)
        );
    }

    public static function db(): self
    {
        return new self(
            self::resolve(DBEncrypter::class)
        );
    }

    public static function isEncrypted(mixed $value): bool
    {
        return self::php()->encrypter
            ->isEncrypted($value);
    }

    /**
     * Locky · 小锁灵 — the project pet as SVG markup ({@see Mascot::ascii()} for CLI).
     * Decorative only: it never touches keys, ciphers or payloads.
     */
    public static function mascot(): string
    {
        return Mascot::svg();
    }

    public function encrypt(mixed $value, bool $serialize = true): ?string
    {
        return $this->encrypter
            ->encrypt($value, $serialize);
    }

    public function decrypt(?string $payload, bool $unserialize = true): mixed
    {
        return $this->encrypter
            ->decrypt($payload, $unserialize);
    }

    /**
     * Re-encrypt a payload with the current primary key after decrypting with any key in the ring.
     * Only available when using {@see self::php()} (application-level OpenSSL payloads).
     */
    public function rotateToCurrentKey(?string $payload, bool $serialize = true): ?string
    {
        if (! $this->encrypter instanceof PHPEncrypter) {
            throw new RuntimeException('rotateToCurrentKey is only supported for Encryption::php().');
        }

        return $this->encrypter->rotateToCurrentKey($payload, $serialize);
    }

    private static function resolve(string $abstract): Encrypter
    {
        if (isset(self::$resolved[$abstract])) {
            return self::$resolved[$abstract];
        }

        return self::$resolved[$abstract] = self::doResolve($abstract);
    }

    private static function doResolve(string $abstract): Encrypter
    {
        if (self::$resolver !== null) {
            return (self::$resolver)($abstract);
        }

        if (class_exists(\Hyperf\Context\ApplicationContext::class)) {
            try {
                $hyperf = \Hyperf\Context\ApplicationContext::getContainer();
                if ($hyperf->has($abstract)) {
                    return $hyperf->get($abstract);
                }
            } catch (\RuntimeException) {
                // Hyperf throws RuntimeException when not in a worker/coroutine context
            }
        }

        if (function_exists('app')) {
            $app = app();
            if ($app instanceof ContainerInterface && $app->has($abstract)) {
                return $app->get($abstract);
            }
            if (is_object($app) && method_exists($app, 'bound') && method_exists($app, 'make') && $app->bound($abstract)) {
                return $app->make($abstract);
            }
        }

        if (self::$container !== null && self::$container->has($abstract)) {
            return self::$container->get($abstract);
        }

        if ($abstract === PHPEncrypter::class) {
            return new PHPEncrypter(self::fallbackEncryptableConfig());
        }

        if ($abstract === DBEncrypter::class) {
            $config = self::fallbackEncryptableConfig();

            return new DBEncrypter($config, new EnvDbDriverDetector(
                $config instanceof ArrayEncryptableConfig ? $config->getDbDriver() : null
            ));
        }

        throw new RuntimeException(
            "Unable to resolve [{$abstract}]. Register bindings in your framework service provider, ".
            'or call Encryption::setResolver() with a PSR-11 container callback.'
        );
    }

    private static function fallbackEncryptableConfig(): EncryptableConfigContract
    {
        return self::$fallbackConfig ?? new EnvEncryptableConfig;
    }
}
