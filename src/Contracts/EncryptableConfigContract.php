<?php

namespace Maize\Encryptable\Contracts;

interface EncryptableConfigContract
{
    public function getKey(): ?string;

    public function getCipher(): ?string;
}
