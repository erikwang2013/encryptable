<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Rules;

use Erikwang2013\Encryptable\Encryption;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\ForwardsCalls;
use LogicException;

/**
 * Shared behavior for {@see UniqueEncrypted} and {@see ExistsEncrypted}:
 * both validate against deterministic ciphertext, so the inner rule always
 * receives the encrypted value instead of the plaintext.
 */
abstract class EncryptedRule implements Rule
{
    use ForwardsCalls;

    protected object $rule;

    public function __construct(string $table, string $column = 'NULL')
    {
        $this->assertDeterministicCipher();

        $this->rule = $this->makeRule($table, $column);
    }

    abstract protected function makeRule(string $table, string $column): object;

    abstract protected function messageKey(): string;

    /**
     * Encrypted equality lookups only make sense with a deterministic cipher.
     * Read the cipher from the resolved encrypter so every config source
     * (framework bridge, custom resolver, {@see Encryption::configure()}) agrees;
     * a random-IV cipher would silently break uniqueness/existence checks.
     */
    private function assertDeterministicCipher(): void
    {
        $cipher = Encryption::php()->cipher();

        if (! in_array($cipher, ['aes-128-ecb', 'aes-256-ecb'], true)) {
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
        $msg = __($this->messageKey());

        return is_string($msg) ? $msg : $this->messageKey();
    }
}
