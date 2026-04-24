<?php

namespace Maize\Encryptable\Contracts;

interface DbDriverDetector
{
    public function isPostgres(): bool;
}
