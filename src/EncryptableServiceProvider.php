<?php

namespace Maize\Encryptable;

use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rule;
use Maize\Encryptable\Bridge\Laravel\IlluminateDbDriverDetector;
use Maize\Encryptable\Bridge\Laravel\IlluminateEncryptableConfig;
use Maize\Encryptable\Contracts\DbDriverDetector;
use Maize\Encryptable\Contracts\EncryptableConfigContract;
use Maize\Encryptable\Rules\ExistsEncrypted;
use Maize\Encryptable\Rules\UniqueEncrypted;

class EncryptableServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__).'/config/encryptable.php',
            'encryptable'
        );

        $this->app->singleton(EncryptableConfigContract::class, IlluminateEncryptableConfig::class);

        $this->app->singleton(DbDriverDetector::class, function () {
            return new IlluminateDbDriverDetector;
        });

        $this->app->singleton(PHPEncrypter::class, function ($app) {
            return new PHPEncrypter(
                $app->make(EncryptableConfigContract::class)
            );
        });

        $this->app->singleton(DBEncrypter::class, function ($app) {
            return new DBEncrypter(
                $app->make(EncryptableConfigContract::class),
                $app->make(DbDriverDetector::class)
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                dirname(__DIR__).'/config/encryptable.php' => config_path('encryptable.php'),
            ], 'encryptable-config');
        }

        Rule::macro(
            'uniqueEncrypted',
            fn (string $table, string $column = 'NULL') => new UniqueEncrypted($table, $column)
        );

        Rule::macro(
            'existsEncrypted',
            fn (string $table, string $column = 'NULL') => new ExistsEncrypted($table, $column)
        );
    }
}
