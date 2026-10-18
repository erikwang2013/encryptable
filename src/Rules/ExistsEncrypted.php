<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Rules;

use Illuminate\Validation\Rules\Exists;

class ExistsEncrypted extends EncryptedRule
{
    protected function makeRule(string $table, string $column): object
    {
        return new Exists($table, $column);
    }

    protected function messageKey(): string
    {
        return 'validation.exists';
    }
}
