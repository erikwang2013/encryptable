<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\ForwardsCalls;
use Illuminate\Validation\Rules\Exists;
use Erikwang2013\Encryptable\Encryption;

class ExistsEncrypted implements Rule
{
    use ForwardsCalls;

    private Exists $rule;

    public function __construct(string $table, string $column = 'NULL')
    {
        $this->rule = new Exists($table, $column);
    }

    /**
     * @param  array<int, mixed>  $arguments
     * @return $this
     */
    public function __call(string $name, array $arguments)
    {
        $this->forwardCallTo($this->rule, $name, $arguments);

        return $this;
    }

    public function passes($attribute, $value): bool
    {
        $attribute = Str::before($attribute, '.');

        return ! Validator::make([
            $attribute => Encryption::php()->encrypt($value),
        ], [
            $attribute => $this->rule,
        ])->fails();
    }

    public function message(): string
    {
        $msg = __('validation.exists');

        return is_string($msg) ? $msg : 'validation.exists';
    }
}
