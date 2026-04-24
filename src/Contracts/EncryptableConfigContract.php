<?php

namespace Maize\Encryptable\Contracts;

interface EncryptableConfigContract
{
    public function getKey(): ?string;

    public function getCipher(): ?string;

    /**
     * Retired keys tried only after the primary {@see getKey()} when decrypting application-level payloads.
     * Order should be newest-retired first; all keys must use the same {@see getCipher()} as the primary key.
     *
     * @return list<non-empty-string>
     */
    public function getPreviousKeys(): array;
}
