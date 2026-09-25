<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Config;

use Erikwang2013\Encryptable\Contracts\DbDriverDetector;
use Erikwang2013\Encryptable\DBEncrypter;
use Erikwang2013\Encryptable\Encryption;
use PDO;

/**
 * 原生 PHP（无框架）场景下的数据库方言探测，供 {@see Encryption::db()} 使用。
 *
 * 取值优先级：构造函数传入的 driver → 传入的 PDO 连接 → 环境变量 {@code ENCRYPTION_DB_DRIVER}。
 * 三者都没有时按 MySQL 处理（与 {@see DBEncrypter} 的默认方言一致）。
 */
final class EnvDbDriverDetector implements DbDriverDetector
{
    /** @var list<string> Postgres 在各驱动层中的常见写法 */
    private const POSTGRES_DRIVERS = ['pgsql', 'postgres', 'postgresql'];

    public function __construct(
        private ?string $driver = null,
        private ?PDO $pdo = null
    ) {}

    public function isPostgres(): bool
    {
        return in_array($this->driverName(), self::POSTGRES_DRIVERS, true);
    }

    private function driverName(): string
    {
        if ($this->driver !== null && trim($this->driver) !== '') {
            return strtolower(trim($this->driver));
        }

        if ($this->pdo !== null) {
            $name = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (is_string($name) && $name !== '') {
                return strtolower($name);
            }
        }

        $env = $_ENV['ENCRYPTION_DB_DRIVER'] ?? $_SERVER['ENCRYPTION_DB_DRIVER'] ?? getenv('ENCRYPTION_DB_DRIVER');

        return is_string($env) ? strtolower(trim($env)) : '';
    }
}
