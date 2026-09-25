<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Config;

use Erikwang2013\Encryptable\Contracts\EncryptableConfigContract;
use Erikwang2013\Encryptable\Encryption;
use Erikwang2013\Encryptable\Support\PreviousKeysParser;

/**
 * 数组配置：原生 PHP（无框架、无容器）场景下的配置入口，见 {@see Encryption::configure()}。
 *
 * 键与配置文件一致：{@code key}、{@code cipher}、{@code previous_keys}，另加原生场景专用的 {@code db_driver}。
 */
final class ArrayEncryptableConfig implements EncryptableConfigContract
{
    public const DEFAULT_CIPHER = 'aes-256-gcm';

    /**
     * @param  array{key?: mixed, cipher?: mixed, previous_keys?: mixed, db_driver?: mixed}  $config
     */
    public function __construct(
        private array $config = []
    ) {}

    public function getKey(): ?string
    {
        $key = $this->config['key'] ?? null;

        return $key === null || $key === '' ? null : (string) $key;
    }

    /**
     * 与契约兼容的协变返回类型：本实现永远有默认值，不会返回 null。
     */
    public function getCipher(): string
    {
        $cipher = $this->config['cipher'] ?? null;

        return $cipher === null || $cipher === '' ? self::DEFAULT_CIPHER : (string) $cipher;
    }

    public function getPreviousKeys(): array
    {
        return PreviousKeysParser::parse($this->config['previous_keys'] ?? []);
    }

    /**
     * 仅供原生 PHP 的 {@see EnvDbDriverDetector} 使用；
     * 框架场景由各框架桥接探测数据库驱动（driver 配置键不参与 {@see EncryptableConfigContract} 契约）。
     */
    public function getDbDriver(): ?string
    {
        $driver = $this->config['db_driver'] ?? null;

        return $driver === null || $driver === '' ? null : (string) $driver;
    }
}
