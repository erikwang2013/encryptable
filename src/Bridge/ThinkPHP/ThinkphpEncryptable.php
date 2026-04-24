<?php

namespace Maize\Encryptable\Bridge\ThinkPHP;

use Maize\Encryptable\Contracts\DbDriverDetector;
use Maize\Encryptable\Contracts\EncryptableConfigContract;
use Maize\Encryptable\DBEncrypter;
use Maize\Encryptable\Encryption;
use Maize\Encryptable\PHPEncrypter;
use Psr\Container\ContainerInterface;
use think\App;

class ThinkphpEncryptable
{
    public static function register(App $app): void
    {
        $app->singleton(EncryptableConfigContract::class, ThinkEncryptableConfig::class);
        $app->singleton(DbDriverDetector::class, ThinkDbDriverDetector::class);

        $app->singleton(PHPEncrypter::class, function () use ($app) {
            return new PHPEncrypter(
                $app->make(EncryptableConfigContract::class)
            );
        });

        $app->singleton(DBEncrypter::class, function () use ($app) {
            return new DBEncrypter(
                $app->make(EncryptableConfigContract::class),
                $app->make(DbDriverDetector::class)
            );
        });

        $psr = $app instanceof ContainerInterface
            ? $app
            : new ThinkPsrContainerAdapter($app);

        Encryption::setContainer($psr);
    }
}
