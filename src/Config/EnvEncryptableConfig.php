<?php

namespace Maize\Encryptable\Config;

use Maize\Encryptable\Contracts\EncryptableConfigContract;

class EnvEncryptableConfig implements EncryptableConfigContract
{
    public function getKey(): ?string
    {
        $value = $_ENV['ENCRYPTION_KEY'] ?? $_SERVER['ENCRYPTION_KEY'] ?? getenv('ENCRYPTION_KEY');

        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    public function getCipher(): ?string
    {
        $value = $_ENV['ENCRYPTION_CIPHER'] ?? $_SERVER['ENCRYPTION_CIPHER'] ?? getenv('ENCRYPTION_CIPHER');

        if ($value === false || $value === null || $value === '') {
            return 'aes-128-ecb';
        }

        return (string) $value;
    }
}
