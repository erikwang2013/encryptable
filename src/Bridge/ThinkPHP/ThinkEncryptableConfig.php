<?php

namespace Maize\Encryptable\Bridge\ThinkPHP;

use Maize\Encryptable\Contracts\EncryptableConfigContract;
use think\facade\Config;

class ThinkEncryptableConfig implements EncryptableConfigContract
{
    public function getKey(): ?string
    {
        $key = Config::get('encryptable.key');

        if ($key === null || $key === '') {
            return null;
        }

        return (string) $key;
    }

    public function getCipher(): ?string
    {
        $cipher = Config::get('encryptable.cipher', 'aes-128-ecb');

        if ($cipher === null || $cipher === '') {
            return null;
        }

        return (string) $cipher;
    }
}
