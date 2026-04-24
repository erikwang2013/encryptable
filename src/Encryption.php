<?php

namespace Maize\Encryptable;

use Maize\Encryptable\Config\EnvEncryptableConfig;
use Maize\Encryptable\Contracts\EncryptableConfigContract;
use Psr\Container\ContainerInterface;
use RuntimeException;

class Encryption
{
    private static ?ContainerInterface $container = null;

    private static ?EncryptableConfigContract $fallbackConfig = null;

    private $encrypter;

    public function __construct($encrypter)
    {
        $this->encrypter = $encrypter;
    }

    public static function setContainer(?ContainerInterface $container): void
    {
        self::$container = $container;
    }

    public static function setFallbackConfig(?EncryptableConfigContract $config): void
    {
        self::$fallbackConfig = $config;
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

    public static function isEncrypted($value): bool
    {
        return self::php()->encrypter
            ->isEncrypted($value);
    }

    public function encrypt($value, bool $serialize = true)
    {
        return $this->encrypter
            ->encrypt($value, $serialize);
    }

    public function decrypt(?string $payload, bool $unserialize = true)
    {
        return $this->encrypter
            ->decrypt($payload, $unserialize);
    }

    private static function resolve(string $abstract): object
    {
        if (class_exists(\Hyperf\Context\ApplicationContext::class)) {
            try {
                $hyperf = \Hyperf\Context\ApplicationContext::getContainer();
                if ($hyperf->has($abstract)) {
                    return $hyperf->get($abstract);
                }
            } catch (\Throwable) {
                // not in a Hyperf worker context
            }
        }

        if (function_exists('app')) {
            $app = app();
            if ($app instanceof ContainerInterface && $app->has($abstract)) {
                return $app->get($abstract);
            }
        }

        if (self::$container !== null && self::$container->has($abstract)) {
            return self::$container->get($abstract);
        }

        if ($abstract === PHPEncrypter::class) {
            return new PHPEncrypter(self::fallbackEncryptableConfig());
        }

        throw new RuntimeException(
            "Unable to resolve [{$abstract}]. Register bindings in your framework service provider, ".
            'or call Encryption::setContainer() with a PSR-11 container.'
        );
    }

    private static function fallbackEncryptableConfig(): EncryptableConfigContract
    {
        return self::$fallbackConfig ?? new EnvEncryptableConfig;
    }
}
