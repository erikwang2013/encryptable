<?php

namespace Maize\Encryptable\Bridge\Hyperf;

use Hyperf\DbConnection\Db;
use Maize\Encryptable\Contracts\DbDriverDetector;

class HyperfDbDriverDetector implements DbDriverDetector
{
    public function __construct(
        protected ?string $connection = null
    ) {
    }

    public function isPostgres(): bool
    {
        $connection = $this->connection !== null
            ? Db::connection($this->connection)
            : Db::connection();

        if (method_exists($connection, 'getDriverName')) {
            $name = (string) $connection->getDriverName();
        } elseif (method_exists($connection, 'getConfig')) {
            $name = (string) ($connection->getConfig('driver') ?? '');
        } else {
            return false;
        }

        return in_array(strtolower($name), ['pgsql', 'postgres'], true);
    }
}
