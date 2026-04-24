<?php

namespace Maize\Encryptable;

use Maize\Encryptable\Contracts\EncryptableConfigContract;
use Maize\Encryptable\Exceptions\MissingEncryptionCipherException;
use Maize\Encryptable\Exceptions\MissingEncryptionKeyException;

abstract class Encrypter
{
    const DIRTY_BIT_KEY = 'crypt:';

    public function __construct(
        protected EncryptableConfigContract $encryptableConfig
    ) {
    }

    abstract public function encrypt($value, bool $serialize = true): ?string;

    abstract public function decrypt(?string $payload, bool $unserialize = true);

    protected function getEncryptionKey(): string
    {
        $key = $this->encryptableConfig->getKey();

        if ($key === null || $key === '') {
            throw new MissingEncryptionKeyException;
        }

        return $key;
    }

    protected function getEncryptionCipher(): string
    {
        $cipher = $this->encryptableConfig->getCipher();

        if ($cipher === null || $cipher === '') {
            throw new MissingEncryptionCipherException;
        }

        return $cipher;
    }
}
