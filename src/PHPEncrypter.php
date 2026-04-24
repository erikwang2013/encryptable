<?php

namespace Maize\Encryptable;

use Maize\Encryptable\Exceptions\DecryptException;
use Maize\Encryptable\Exceptions\EncryptException;
use Maize\Encryptable\Utils\Serializer;

class PHPEncrypter extends Encrypter
{
    public function encrypt($value, bool $serialize = true): ?string
    {
        if (is_null($value)) {
            return null;
        }

        if ($this->isEncrypted($value)) {
            return $value;
        }

        if ($serialize) {
            $value = Serializer::serialize($value);
        }

        $value = $this->addDirtyBit($value);

        $value = $this->openSSLEncrypt($value);

        $value = $this->base64Encode($value);

        return $value;
    }

    public function decrypt(?string $payload, bool $unserialize = true)
    {
        if (is_null($payload)) {
            return null;
        }

        if (! $this->isEncrypted($payload)) {
            return $payload;
        }

        $payload = $this->base64Decode($payload);

        $payload = $this->openSSLDecrypt($payload);

        $payload = $this->removeDirtyBit($payload);

        if ($unserialize) {
            $payload = Serializer::unserialize($payload);
        }

        return $payload;
    }

    public function isEncrypted($value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        try {
            $value = $this->base64Decode($value);

            $value = $this->openSSLDecrypt($value);

            return str_starts_with($value, self::DIRTY_BIT_KEY);
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function addDirtyBit(string $value): string
    {
        $prefix = self::DIRTY_BIT_KEY;

        if (! str_starts_with($value, $prefix)) {
            return $prefix.$value;
        }

        return $value;
    }

    protected function openSSLEncrypt(string $value): string
    {
        $value = openssl_encrypt(
            $value,
            $this->getEncryptionCipher(),
            $this->getEncryptionKey(),
            OPENSSL_RAW_DATA
        );

        if (! $value) {
            throw new EncryptException('Could not encrypt the data.');
        }

        return $value;
    }

    protected function base64Encode(string $value): string
    {
        $value = base64_encode($value);

        if (! $value) {
            throw new EncryptException('Could not encrypt the data.');
        }

        return $value;
    }

    protected function base64Decode(string $payload): string
    {
        $payload = base64_decode($payload);

        if (! $payload) {
            throw new DecryptException('Could not decrypt the data.');
        }

        return $payload;
    }

    protected function openSSLDecrypt(string $payload): string
    {
        $payload = openssl_decrypt(
            $payload,
            $this->getEncryptionCipher(),
            $this->getEncryptionKey(),
            OPENSSL_RAW_DATA
        );

        if (! $payload) {
            throw new DecryptException('Could not decrypt the data.');
        }

        return $payload;
    }

    protected function removeDirtyBit(string $payload): string
    {
        if (! str_starts_with($payload, self::DIRTY_BIT_KEY)) {
            throw new DecryptException('Could not decrypt the data.');
        }

        return substr($payload, strlen(self::DIRTY_BIT_KEY));
    }
}
