<?php

namespace Maize\Encryptable\Bridge\Hyperf;

use Maize\Encryptable\Contracts\DbDriverDetector;
use Maize\Encryptable\Contracts\EncryptableConfigContract;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                EncryptableConfigContract::class => HyperfEncryptableConfig::class,
                DbDriverDetector::class => HyperfDbDriverDetector::class,
            ],
        ];
    }
}
