<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Bridge\Laravel\IlluminateDbDriverDetector;
use Erikwang2013\Encryptable\Bridge\Laravel\IlluminateEncryptableConfig;
use Erikwang2013\Encryptable\Contracts\DbDriverDetector;
use Erikwang2013\Encryptable\Contracts\EncryptableConfigContract;
use Erikwang2013\Encryptable\DBEncrypter;
use Erikwang2013\Encryptable\EncryptableServiceProvider;
use Erikwang2013\Encryptable\Exceptions\MissingEncryptionKeyException;
use Erikwang2013\Encryptable\PHPEncrypter;
use Erikwang2013\Encryptable\Rules\ExistsEncrypted;
use Erikwang2013\Encryptable\Rules\UniqueEncrypted;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\Rule;
use ReflectionProperty;

final class ServiceProviderTest extends TestCase
{
    private string $basePath = '';

    private Application $app;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/encryptable-sp-' . uniqid();
        mkdir($this->basePath);

        $this->app = new Application($this->basePath);
        $this->app->instance('config', new Repository);
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        $this->resetContainers();
        array_map('unlink', glob($this->basePath . '/config/encryptable.php') ?: []);
        @rmdir($this->basePath . '/config');
        @rmdir($this->basePath);
        parent::tearDown();
    }

    private function registerProvider(): EncryptableServiceProvider
    {
        $provider = new EncryptableServiceProvider($this->app);
        $provider->register();

        return $provider;
    }

    public function test_register_merges_package_config_defaults(): void
    {
        $this->registerProvider();

        $config = $this->app->make('config');
        self::assertSame('aes-256-gcm', $config->get('encryptable.cipher'));
        self::assertNull($config->get('encryptable.key'));
        self::assertSame([], $config->get('encryptable.previous_keys'));
    }

    public function test_register_binds_config_contract_to_illuminate_config(): void
    {
        $this->registerProvider();
        $config = $this->app->make('config');
        $config->set('encryptable.key', str_repeat('k', 16));

        $resolved = $this->app->make(EncryptableConfigContract::class);

        self::assertInstanceOf(IlluminateEncryptableConfig::class, $resolved);
        self::assertSame(str_repeat('k', 16), $resolved->getKey());
    }

    public function test_register_binds_detector_contract(): void
    {
        $this->registerProvider();

        self::assertInstanceOf(IlluminateDbDriverDetector::class, $this->app->make(DbDriverDetector::class));
    }

    public function test_register_binds_php_encrypter_singleton_that_roundtrips(): void
    {
        $this->registerProvider();
        $this->app->make('config')->set('encryptable.key', str_repeat('k', 32));

        $encrypter = $this->app->make(PHPEncrypter::class);

        self::assertInstanceOf(PHPEncrypter::class, $encrypter);
        self::assertSame($encrypter, $this->app->make(PHPEncrypter::class), 'PHPEncrypter must be a singleton.');
        self::assertSame('via-container', $encrypter->decrypt($encrypter->encrypt('via-container')));
    }

    public function test_register_binds_db_encrypter_singleton(): void
    {
        $this->registerProvider();
        $this->app->make('config')->set('encryptable.key', str_repeat('k', 32));

        // decrypt() consults IlluminateDbDriverDetector → DB facade → needs a db binding
        $connection = $this->createMock(\Illuminate\Database\Connection::class);
        $connection->method('getDriverName')->willReturn('mysql');
        $manager = $this->createMock(\Illuminate\Database\DatabaseManager::class);
        $manager->method('connection')->willReturn($connection);
        $this->app->instance('db', $manager);

        $db = $this->app->make(DBEncrypter::class);

        self::assertInstanceOf(DBEncrypter::class, $db);
        self::assertSame($db, $this->app->make(DBEncrypter::class), 'DBEncrypter must be a singleton.');
        self::assertStringContainsString('AES_DECRYPT', $db->decrypt('col'));
    }

    public function test_container_encrypter_propagates_missing_key(): void
    {
        $this->registerProvider();

        $this->expectException(MissingEncryptionKeyException::class);
        $this->app->make(PHPEncrypter::class)->encrypt('x');
    }

    public function test_boot_registers_validation_rule_macros(): void
    {
        $this->registerProvider();
        $this->app->make('config')->set('encryptable.cipher', 'aes-256-ecb');
        $this->app->make('config')->set('encryptable.key', str_repeat('k', 32));

        (new EncryptableServiceProvider($this->app))->boot();

        self::assertTrue(Rule::hasMacro('uniqueEncrypted'));
        self::assertTrue(Rule::hasMacro('existsEncrypted'));

        self::assertInstanceOf(UniqueEncrypted::class, Rule::uniqueEncrypted('users', 'email'));
        self::assertInstanceOf(ExistsEncrypted::class, Rule::existsEncrypted('users', 'email'));
    }

    public function test_boot_registers_publishable_configs(): void
    {
        $this->registerProvider();
        (new EncryptableServiceProvider($this->app))->boot();

        $property = new ReflectionProperty(\Illuminate\Support\ServiceProvider::class, 'publishes');
        $publishes = $property->getValue();

        self::assertArrayHasKey(EncryptableServiceProvider::class, $publishes);

        // publishes map: source stub path => destination config_path() path
        $destinations = array_values($publishes[EncryptableServiceProvider::class]);
        self::assertContains($this->basePath . '/config/encryptable.php', $destinations);
        self::assertContains(
            $this->basePath . '/config/plugin/erikwang2013/encryptable/app.php',
            $destinations
        );
        self::assertContains(dirname(__DIR__) . '/config/encryptable.php', array_keys($publishes[EncryptableServiceProvider::class]));
    }
}
