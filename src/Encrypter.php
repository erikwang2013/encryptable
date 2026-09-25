<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable;

use Erikwang2013\Encryptable\Contracts\EncryptableConfigContract;
use Erikwang2013\Encryptable\Exceptions\MissingEncryptionCipherException;
use Erikwang2013\Encryptable\Exceptions\MissingEncryptionKeyException;
use Erikwang2013\Encryptable\Exceptions\SerializationException;
use Erikwang2013\Encryptable\Utils\Serializer;

abstract class Encrypter
{
    const DIRTY_BIT_KEY = 'crypt:';

    private const ALLOWED_CIPHERS = [
        'aes-256-gcm',
        'aes-256-cbc',
        'aes-128-gcm',
        'aes-128-cbc',
        'aes-256-ecb',
        'aes-128-ecb',
    ];

    private ?string $cachedKey = null;

    private ?string $cachedCipher = null;

    /** @var null|list<string> */
    private ?array $cachedKeyRing = null;

    public function __construct(
        protected EncryptableConfigContract $encryptableConfig
    ) {
    }

    abstract public function encrypt(mixed $value, bool $serialize = true): ?string;

    abstract public function decrypt(?string $payload, bool $unserialize = true): mixed;

    abstract public function isEncrypted(mixed $value): bool;

    /**
     * Normalized (lowercase, whitelisted) cipher in use — for deterministic-mode detection.
     */
    public function cipher(): string
    {
        return $this->getEncryptionCipher();
    }

    protected function getEncryptionKey(): string
    {
        if ($this->cachedKey === null) {
            $cipher = $this->getEncryptionCipher();
            $key = $this->encryptableConfig->getKey();

            if ($key === null || $key === '') {
                throw new MissingEncryptionKeyException;
            }

            if (str_starts_with($key, 'base64:')) {
                $key = base64_decode(substr($key, 7), true);
                if ($key === false) {
                    throw new MissingEncryptionKeyException('The encryption key is not valid base64.');
                }
            }

            $expectedLength = str_contains($cipher, '256') ? 32 : 16;
            if (strlen($key) !== $expectedLength) {
                throw new MissingEncryptionKeyException(
                    "The encryption key must be {$expectedLength} bytes for cipher [{$cipher}]."
                );
            }

            $this->cachedKey = $key;
        }

        return $this->cachedKey;
    }

    protected function getEncryptionCipher(): string
    {
        if ($this->cachedCipher === null) {
            $cipher = $this->encryptableConfig->getCipher();

            if ($cipher === null || $cipher === '') {
                throw new MissingEncryptionCipherException;
            }

            $cipher = strtolower($cipher);

            if (! in_array($cipher, self::ALLOWED_CIPHERS, true)) {
                throw new MissingEncryptionCipherException(
                    "Unsupported encryption cipher [{$cipher}]."
                );
            }

            $this->cachedCipher = $cipher;
        }

        return $this->cachedCipher;
    }

    /**
     * 待加密的明文字节串：{@code $serialize} 为 true 时走 {@see Serializer} 信封；
     * 为 false 时要求标量/字符串（数组与对象必须先序列化，否则抛出明确异常而不是 TypeError）。
     *
     * `$serialize = false` 用于需要按明文等值比较的列，因此这里不做任何隐式包装。
     */
    protected function preparePlaintext(mixed $value, bool $serialize): string
    {
        if ($serialize) {
            return Serializer::serialize($value);
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw new SerializationException(
            'Values of type '.get_debug_type($value).' need the serialization envelope;'
            .' pass $serialize = true (arrays and objects cannot be encrypted with $serialize = false).'
        );
    }

    /**
     * Primary key first, then {@see EncryptableConfigContract::getPreviousKeys()} (deduplicated).
     *
     * @return list<string>
     */
    protected function getDecryptionKeyRing(): array
    {
        if ($this->cachedKeyRing === null) {
            $primary = $this->getEncryptionKey();
            $ring = [$primary];

            foreach ($this->encryptableConfig->getPreviousKeys() as $key) {
                $key = trim((string) $key);
                if ($key === '' || $key === $primary) {
                    continue;
                }
                if (in_array($key, $ring, true)) {
                    continue;
                }
                $ring[] = $key;
            }

            $this->cachedKeyRing = $ring;
        }

        return $this->cachedKeyRing;
    }
}
