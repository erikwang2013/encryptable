<?php

namespace Maize\Encryptable\Bridge\Hyperf;

use Hyperf\Contract\ConfigInterface;
use Maize\Encryptable\Contracts\EncryptableConfigContract;

class HyperfEncryptableConfig implements EncryptableConfigContract
{
    public function __construct(
        protected ConfigInterface $config
    ) {
    }

    public function getKey(): ?string
    {
        $key = $this->config->get('encryptable.key');

        if ($key === null || $key === '') {
            return null;
        }

        return (string) $key;
    }

    public function getCipher(): ?string
    {
        $cipher = $this->config->get('encryptable.cipher', 'aes-128-ecb');

        if ($cipher === null || $cipher === '') {
            return null;
        }

        return (string) $cipher;
    }
}
