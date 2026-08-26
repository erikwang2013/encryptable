<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Bridge\Hyperf\ConfigProvider;
use Erikwang2013\Encryptable\Bridge\Hyperf\HyperfDbDriverDetector;
use Erikwang2013\Encryptable\Bridge\Hyperf\HyperfEncryptableConfig;
use Erikwang2013\Encryptable\Bridge\ThinkPHP\ThinkPsrContainerAdapter;
use Erikwang2013\Encryptable\Contracts\DbDriverDetector;
use Erikwang2013\Encryptable\Contracts\EncryptableConfigContract;
use Erikwang2013\Encryptable\DBEncrypter;
use Erikwang2013\Encryptable\PHPEncrypter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for bridge classes whose logic does not depend on the framework being installed.
 * Hyperf/ThinkPHP framework-dependent classes are listed in tests/UNCOVERED.md.
 */
final class BridgePureTest extends TestCase
{
    public function test_hyperf_config_provider_maps_dependencies(): void
    {
        $dependencies = (new ConfigProvider)()['dependencies'];

        self::assertSame(HyperfEncryptableConfig::class, $dependencies[EncryptableConfigContract::class]);
        self::assertSame(HyperfDbDriverDetector::class, $dependencies[DbDriverDetector::class]);
        self::assertSame(PHPEncrypter::class, $dependencies[PHPEncrypter::class]);
        self::assertSame(DBEncrypter::class, $dependencies[DBEncrypter::class]);
    }

    public function test_think_psr_adapter_get_delegates_to_make(): void
    {
        $think = new class {
            public function make(string $id): string
            {
                return 'made:' . $id;
            }
        };

        self::assertSame('made:service', (new ThinkPsrContainerAdapter($think))->get('service'));
    }

    public function test_think_psr_adapter_has_via_bound(): void
    {
        $think = new class {
            public function bound(string $id): bool
            {
                return $id === 'bound-id';
            }
        };

        $adapter = new ThinkPsrContainerAdapter($think);

        self::assertTrue($adapter->has('bound-id'));
        self::assertFalse($adapter->has('other'));
    }

    public function test_think_psr_adapter_has_via_psr_container(): void
    {
        $think = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return $id;
            }

            public function has(string $id): bool
            {
                return $id === 'psr-id';
            }
        };

        $adapter = new ThinkPsrContainerAdapter($think);

        self::assertTrue($adapter->has('psr-id'));
        self::assertFalse($adapter->has('nope'));
    }

    public function test_think_psr_adapter_has_false_without_capabilities(): void
    {
        self::assertFalse((new ThinkPsrContainerAdapter(new \stdClass))->has('anything'));
    }
}
