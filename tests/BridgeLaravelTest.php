<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Bridge\Laravel\IlluminateDbDriverDetector;
use Erikwang2013\Encryptable\Bridge\Laravel\IlluminateEncryptableConfig;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

final class BridgeLaravelTest extends TestCase
{
    private Container $app;

    private Repository $config;

    protected function setUp(): void
    {
        $this->app = new Container;
        Container::setInstance($this->app);
        Facade::setFacadeApplication($this->app);

        $this->config = new Repository;
        $this->app->instance('config', $this->config);
    }

    protected function tearDown(): void
    {
        $this->resetContainers();
        parent::tearDown();
    }

    // ── IlluminateEncryptableConfig ──

    public function test_get_key_from_config(): void
    {
        $this->config->set('encryptable.key', 'the-key');

        self::assertSame('the-key', (new IlluminateEncryptableConfig)->getKey());
    }

    public function test_get_key_returns_null_when_missing_or_empty(): void
    {
        self::assertNull((new IlluminateEncryptableConfig)->getKey());

        $this->config->set('encryptable.key', '');

        self::assertNull((new IlluminateEncryptableConfig)->getKey());
    }

    public function test_get_cipher_defaults_to_gcm(): void
    {
        self::assertSame('aes-256-gcm', (new IlluminateEncryptableConfig)->getCipher());
    }

    public function test_get_cipher_from_config(): void
    {
        $this->config->set('encryptable.cipher', 'aes-128-ecb');

        self::assertSame('aes-128-ecb', (new IlluminateEncryptableConfig)->getCipher());
    }

    public function test_get_cipher_null_when_explicitly_empty(): void
    {
        $this->config->set('encryptable.cipher', '');

        self::assertNull((new IlluminateEncryptableConfig)->getCipher());
    }

    public function test_get_previous_keys_from_config(): void
    {
        $this->config->set('encryptable.previous_keys', 'old1,old2');

        self::assertSame(['old1', 'old2'], (new IlluminateEncryptableConfig)->getPreviousKeys());
    }

    public function test_get_previous_keys_empty_by_default(): void
    {
        self::assertSame([], (new IlluminateEncryptableConfig)->getPreviousKeys());
    }

    // ── IlluminateDbDriverDetector ──

    public function test_detector_uses_named_connection_and_detects_postgres(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn('pgsql');

        $manager = $this->createMock(DatabaseManager::class);
        $manager->expects($this->once())->method('connection')->with('tenant')->willReturn($connection);
        $this->app->instance('db', $manager);

        self::assertTrue((new IlluminateDbDriverDetector('tenant'))->isPostgres());
    }

    public function test_detector_uses_default_connection_and_rejects_mysql(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn('mysql');

        $manager = $this->createMock(DatabaseManager::class);
        $manager->expects($this->once())->method('connection')->with(null)->willReturn($connection);
        $this->app->instance('db', $manager);

        self::assertFalse((new IlluminateDbDriverDetector)->isPostgres());
    }

    public function test_detector_does_not_treat_sqlite_as_postgres(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn('sqlite');

        $manager = $this->createMock(DatabaseManager::class);
        $manager->method('connection')->willReturn($connection);
        $this->app->instance('db', $manager);

        self::assertFalse((new IlluminateDbDriverDetector)->isPostgres());
    }
}
