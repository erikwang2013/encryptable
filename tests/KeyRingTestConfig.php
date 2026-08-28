<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Contracts\EncryptableConfigContract;

/**
 * Shared config stub for tests. Public props so caching behavior of the
 * abstract Encrypter base can be observed by mutating config after first use.
 */
final class KeyRingTestConfig implements EncryptableConfigContract
{
    public string $key;

    /** @var list<string> */
    public array $previousKeys;

    public ?string $cipher;

    public function __construct(string $key, array $previousKeys = [], ?string $cipher = 'aes-128-ecb')
    {
        $this->key = $key;
        $this->previousKeys = $previousKeys;
        $this->cipher = $cipher;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function getCipher(): ?string
    {
        return $this->cipher;
    }

    public function getPreviousKeys(): array
    {
        return $this->previousKeys;
    }
}
