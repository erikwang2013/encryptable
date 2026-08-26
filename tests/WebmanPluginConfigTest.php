<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Bridge\Webman\WebmanPluginEncryptableConfig;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class WebmanPluginConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(new Container);
        Facade::setFacadeApplication(null);
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_composer_package_name(): void
    {
        self::assertSame('erikwang2013/encryptable', WebmanPluginEncryptableConfig::composerPackageName());
    }

    public function test_config_dot_prefix(): void
    {
        self::assertSame('plugin.erikwang2013.encryptable.app', WebmanPluginEncryptableConfig::configDotPrefix());
    }

    public function test_app_config_relative_path(): void
    {
        self::assertSame(
            'config/plugin/erikwang2013/encryptable/app.php',
            WebmanPluginEncryptableConfig::appConfigRelativePath()
        );
    }

    public function test_app_config_absolute_path_uses_base_path(): void
    {
        $basePath = sys_get_temp_dir() . '/encryptable-webman-' . uniqid();
        $app = new Application($basePath);
        Container::setInstance($app);
        Facade::setFacadeApplication($app);

        self::assertSame(
            $basePath . '/config/plugin/erikwang2013/encryptable/app.php',
            WebmanPluginEncryptableConfig::appConfigAbsolutePath()
        );
    }

    public function test_get_key_from_plugin_config(): void
    {
        $this->withConfig(['key' => 'webman-key']);

        self::assertSame('webman-key', (new WebmanPluginEncryptableConfig)->getKey());
    }

    public function test_get_key_null_when_missing(): void
    {
        $this->withConfig([]);

        self::assertNull((new WebmanPluginEncryptableConfig)->getKey());
    }

    public function test_get_cipher_defaults_to_gcm(): void
    {
        $this->withConfig([]);

        self::assertSame('aes-256-gcm', (new WebmanPluginEncryptableConfig)->getCipher());
    }

    public function test_get_cipher_from_plugin_config(): void
    {
        $this->withConfig(['cipher' => 'aes-256-ecb']);

        self::assertSame('aes-256-ecb', (new WebmanPluginEncryptableConfig)->getCipher());
    }

    public function test_get_previous_keys_from_plugin_config(): void
    {
        $this->withConfig(['previous_keys' => 'k1,k2']);

        self::assertSame(['k1', 'k2'], (new WebmanPluginEncryptableConfig)->getPreviousKeys());
    }

    private function withConfig(array $values): void
    {
        $config = new Repository(['plugin' => ['erikwang2013' => ['encryptable' => ['app' => $values]]]]);
        Container::setInstance(new Container);
        app()->instance('config', $config);
        Facade::setFacadeApplication(app());
    }
}
