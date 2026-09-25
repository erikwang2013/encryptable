<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Config\ArrayEncryptableConfig;
use Erikwang2013\Encryptable\Config\EnvDbDriverDetector;
use Erikwang2013\Encryptable\Encryption;
use Erikwang2013\Encryptable\Exceptions\MissingEncryptionKeyException;
use Erikwang2013\Encryptable\Exceptions\SerializationException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * 原生 PHP（无框架、无容器、无配置文件）场景：configure() 数组配置 + 环境变量探测数据库方言。
 */
final class NativePhpTest extends TestCase
{
    private const ENV_KEYS = ['ENCRYPTION_KEY', 'ENCRYPTION_CIPHER', 'ENCRYPTION_PREVIOUS_KEYS', 'ENCRYPTION_DB_DRIVER'];

    private const KEY = 'nativephp-nativephp-nativephp-42';

    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->envBackup[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            if ($this->envBackup[$key] !== null) {
                $_ENV[$key] = $this->envBackup[$key];
            }
        }

        Encryption::setFallbackConfig(null);
        Encryption::setContainer(null);
        Encryption::setResolver(null);
        parent::tearDown();
    }

    // ── ArrayEncryptableConfig ──

    public function test_array_config_defaults(): void
    {
        $config = new ArrayEncryptableConfig;

        self::assertNull($config->getKey());
        self::assertSame('aes-256-gcm', $config->getCipher());
        self::assertSame([], $config->getPreviousKeys());
        self::assertNull($config->getDbDriver());
    }

    public function test_array_config_reads_all_keys(): void
    {
        $config = new ArrayEncryptableConfig([
            'key' => self::KEY,
            'cipher' => 'aes-256-ecb',
            'previous_keys' => 'old-1,old-2',
            'db_driver' => 'pgsql',
        ]);

        self::assertSame(self::KEY, $config->getKey());
        self::assertSame('aes-256-ecb', $config->getCipher());
        self::assertSame(['old-1', 'old-2'], $config->getPreviousKeys());
        self::assertSame('pgsql', $config->getDbDriver());
    }

    public function test_array_config_treats_empty_strings_as_unset(): void
    {
        $config = new ArrayEncryptableConfig(['key' => '', 'cipher' => '', 'db_driver' => '']);

        self::assertNull($config->getKey());
        self::assertSame('aes-256-gcm', $config->getCipher());
        self::assertNull($config->getDbDriver());
    }

    // ── EnvDbDriverDetector ──

    public function test_driver_detector_defaults_to_mysql_when_nothing_is_configured(): void
    {
        self::assertFalse((new EnvDbDriverDetector)->isPostgres());
    }

    public function test_driver_detector_reads_env_var(): void
    {
        $_ENV['ENCRYPTION_DB_DRIVER'] = 'pgsql';
        self::assertTrue((new EnvDbDriverDetector)->isPostgres());

        $_ENV['ENCRYPTION_DB_DRIVER'] = ' mysql ';
        self::assertFalse((new EnvDbDriverDetector)->isPostgres());
    }

    public function test_driver_detector_argument_wins_over_env_and_pdo(): void
    {
        $_ENV['ENCRYPTION_DB_DRIVER'] = 'mysql';
        $sqlite = new PDO('sqlite::memory:');

        self::assertTrue((new EnvDbDriverDetector('postgresql', $sqlite))->isPostgres());
        self::assertFalse((new EnvDbDriverDetector(null, $sqlite))->isPostgres());
    }

    public function test_driver_detector_reads_pdo_driver_name(): void
    {
        self::assertFalse((new EnvDbDriverDetector(null, new PDO('sqlite::memory:')))->isPostgres());
    }

    // ── native facade ──

    public function test_configure_enables_php_round_trip_without_env_or_container(): void
    {
        Encryption::configure(['key' => self::KEY]);

        $ciphertext = Encryption::php()->encrypt('123-45-6789');

        self::assertIsString($ciphertext);
        self::assertTrue(Encryption::isEncrypted($ciphertext));
        self::assertSame('123-45-6789', Encryption::php()->decrypt($ciphertext));
    }

    public function test_configure_without_key_still_throws(): void
    {
        Encryption::configure([]);

        $this->expectException(MissingEncryptionKeyException::class);
        Encryption::php()->encrypt('x');
    }

    public function test_db_falls_back_to_mysql_grammar_natively(): void
    {
        Encryption::configure(['key' => self::KEY]);

        $sql = Encryption::db()->decrypt('phone');

        self::assertStringContainsString('CONVERT( SUBSTRING( AES_DECRYPT( FROM_BASE64(phone)', $sql);
    }

    public function test_db_uses_configured_db_driver_for_postgres_grammar(): void
    {
        Encryption::configure(['key' => self::KEY, 'db_driver' => 'pgsql']);

        $sql = Encryption::db()->decrypt('phone');

        self::assertStringContainsString("decode(phone, 'base64')", $sql);
        self::assertStringContainsString("'aes-ecb'", $sql);
        self::assertStringNotContainsString('FROM_BASE64', $sql);
    }

    // ── non-string payloads with $serialize = false ──

    public function test_scalar_payloads_can_skip_serialization(): void
    {
        Encryption::configure(['key' => self::KEY]);

        $ciphertext = Encryption::php()->encrypt(42, false);

        self::assertIsString($ciphertext);
        self::assertSame('42', Encryption::php()->decrypt($ciphertext, false));
    }

    public function test_arrays_without_serialization_throw_instead_of_type_error(): void
    {
        Encryption::configure(['key' => self::KEY]);

        $this->expectException(SerializationException::class);
        $this->expectExceptionMessageMatches('/need the serialization envelope/');
        Encryption::php()->encrypt(['a' => 1], false);
    }

    public function test_arrays_are_rejected_by_the_serialization_envelope_too(): void
    {
        Encryption::configure(['key' => self::KEY]);

        $this->expectException(SerializationException::class);
        $this->expectExceptionMessageMatches('/cannot be serialized/');
        Encryption::php()->encrypt(['a' => 1]);
    }

    public function test_db_encrypter_accepts_scalars_without_serialization(): void
    {
        Encryption::configure(['key' => self::KEY, 'cipher' => 'aes-256-ecb']);

        $ciphertext = Encryption::db()->encrypt(42, false);

        // Deterministic ECB + the isEncrypted() short-circuit: re-encrypting must be a no-op.
        self::assertIsString($ciphertext);
        self::assertSame($ciphertext, Encryption::db()->encrypt($ciphertext, false));
        self::assertSame($ciphertext, Encryption::db()->encrypt(42, false));
    }
}
