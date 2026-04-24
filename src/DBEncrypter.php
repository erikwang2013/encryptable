<?php

namespace Maize\Encryptable;

use Maize\Encryptable\Contracts\DbDriverDetector;
use Maize\Encryptable\Contracts\EncryptableConfigContract;
use Maize\Encryptable\Exceptions\EncryptException;

class DBEncrypter extends Encrypter
{
    public function __construct(
        EncryptableConfigContract $encryptableConfig,
        protected DbDriverDetector $driverDetector
    ) {
        parent::__construct($encryptableConfig);
    }

    public function encrypt($value, bool $serialize = true): ?string
    {
        throw new EncryptException('Operation not supported.');
    }

    public function decrypt(?string $payload, bool $unserialize = true)
    {
        if (is_null($payload)) {
            return null;
        }

        if ($this->driverDetector->isPostgres()) {
            $grammar = $this->getPostgresGrammarDecrypt();
        } else {
            $grammar = $this->getMysqlGrammarDecrypt();
        }

        return sprintf(
            $grammar,
            $payload,
            $this->getEncryptionKey(),
            $this->getEncryptionCipherAlgorithm()
        );
    }

    protected function getMysqlGrammarDecrypt(): string
    {
        return "CONVERT( SUBSTRING_INDEX( AES_DECRYPT( FROM_BASE64(%s), '%s' ), ':', -1 ) USING 'UTF8' )";
    }

    protected function getPostgresGrammarDecrypt(): string
    {
        return "split_part( convert_from( decrypt( decode(%s, 'base64'), '%s', '%s'), 'UTF8' ), ':', 3 )";
    }

    protected function getEncryptionCipherAlgorithm(): string
    {
        $cipher = $this->getEncryptionCipher();

        $dash = strrpos($cipher, '-');
        if ($dash === false) {
            return $cipher;
        }

        $algorithm = strstr($cipher, '-', true) ?: $cipher;
        $mode = substr($cipher, $dash + 1);

        return "{$algorithm}-{$mode}";
    }
}
