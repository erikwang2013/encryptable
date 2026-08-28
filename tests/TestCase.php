<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Reset Illuminate static container/facade state for isolation between tests.
     */
    protected function resetContainers(): void
    {
        Container::setInstance(new Container);
        Facade::setFacadeApplication(null);
        Facade::clearResolvedInstances();
    }
}
