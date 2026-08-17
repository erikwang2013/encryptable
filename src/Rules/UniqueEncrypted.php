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
use Illuminate\Validation\Rules\Unique;
use Erikwang2013\Encryptable\Config\EnvEncryptableConfig;
use Erikwang2013\Encryptable\Encryption;
use LogicException;

class UniqueEncrypted implements Rule
{
    use ForwardsCalls;

    private Unique $rule;

    public function __construct(string $table, string $column = 'NULL')
    {
        $this->assertDeterministicCipher();

        $this->rule = new Unique($table, $column);
    }

    /**
     * UniqueEncrypted 用密文做等值查询，只有确定性加密（ECB）才语义成立。
     * GCM 每次加密结果不同，会让唯一性校验静默失效。
     */
    private function assertDeterministicCipher(): void
    {
        $cipher = function_exists('config') && app()->bound('config')
            ? (string) config('encryptable.cipher', 'aes-256-gcm')
            : (string) (new EnvEncryptableConfig)->getCipher();

        if (! in_array(strtolower($cipher), ['aes-128-ecb', 'aes-256-ecb'], true)) {
            throw new LogicException(
                'UniqueEncrypted/ExistsEncrypted requires a deterministic cipher (ECB). '
                . "Current cipher is [{$cipher}]. "
                . "Set config('encryptable.cipher') or ENCRYPTION_CIPHER to aes-256-ecb."
            );
        }
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
        $msg = __('validation.unique');

        return is_string($msg) ? $msg : 'validation.unique';
    }
}
