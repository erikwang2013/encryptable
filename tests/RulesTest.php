<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Encryption;
use Erikwang2013\Encryptable\Rules\ExistsEncrypted;
use Erikwang2013\Encryptable\Rules\UniqueEncrypted;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\PresenceVerifierInterface;
use LogicException;

/**
 * Captures the data passed to Validator::make() so the tests can assert the
 * value handed to the inner Unique/Exists rule is the encrypted ciphertext.
 */
final class CapturingValidationFactory extends Factory
{
    /** @var array<string, mixed> */
    public array $lastData = [];

    public function make(array $data, array $rules, array $messages = [], array $attributes = []): \Illuminate\Validation\Validator
    {
        $this->lastData = $data;

        return parent::make($data, $rules, $messages, $attributes);
    }
}

/**
 * Presence verifier stub: no real database, the count is scripted per test.
 */
final class PresenceVerifierStub implements PresenceVerifierInterface
{
    public int $count = 0;

    /** @var list<array{0: string, 1: string, 2: mixed}> */
    public array $calls = [];

    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, array $extra = []): int
    {
        $this->calls[] = [$collection, $column, $excludeId, $extra];

        return $this->count;
    }

    public function getMultiCount($collection, $column, $value, $excludeId = null, $idColumn = null, array $extra = []): int
    {
        return $this->count;
    }
}

final class RulesTest extends TestCase
{
    private string $savedCipherEnv = '';

    private Container $app;

    private Translator $translator;

    private PresenceVerifierStub $verifier;

    private CapturingValidationFactory $factory;

    protected function setUp(): void
    {
        $this->savedCipherEnv = $_ENV['ENCRYPTION_CIPHER'] ?? '';
        $_ENV['ENCRYPTION_CIPHER'] = 'aes-128-ecb';
        putenv('ENCRYPTION_CIPHER=aes-128-ecb');

        Encryption::setFallbackConfig(new KeyRingTestConfig(str_repeat('k', 16), [], 'aes-128-ecb'));

        $this->app = new Container;
        Container::setInstance($this->app);
        Facade::setFacadeApplication($this->app);

        $this->translator = new Translator(new ArrayLoader, 'en');
        $this->verifier = new PresenceVerifierStub;
        $this->factory = new CapturingValidationFactory($this->translator);
        $this->factory->setPresenceVerifier($this->verifier);

        // Note: 'config' is intentionally NOT bound — the constructor guard then
        // falls back to EnvEncryptableConfig, keeping the env-based cipher in charge.
        $this->app->instance('validator', $this->factory);
        $this->app->instance('translator', $this->translator);
    }

    protected function tearDown(): void
    {
        if ($this->savedCipherEnv === '') {
            unset($_ENV['ENCRYPTION_CIPHER']);
        } else {
            $_ENV['ENCRYPTION_CIPHER'] = $this->savedCipherEnv;
        }
        putenv('ENCRYPTION_CIPHER');

        Encryption::setFallbackConfig(null);
        Encryption::setContainer(null);
        Encryption::setResolver(null);
        $this->resetContainers();
        parent::tearDown();
    }

    // ── Deterministic-cipher guard ──

    public function test_constructor_throws_with_gcm_cipher(): void
    {
        $_ENV['ENCRYPTION_CIPHER'] = 'aes-256-gcm';
        putenv('ENCRYPTION_CIPHER=aes-256-gcm');

        try {
            new UniqueEncrypted('users', 'email');
            self::fail('Expected LogicException for UniqueEncrypted with GCM.');
        } catch (LogicException $e) {
            self::assertStringContainsString('deterministic cipher', $e->getMessage());
            self::assertStringContainsString('aes-256-gcm', $e->getMessage());
        }

        try {
            new ExistsEncrypted('users', 'email');
            self::fail('Expected LogicException for ExistsEncrypted with GCM.');
        } catch (LogicException $e) {
            self::assertStringContainsString('deterministic cipher', $e->getMessage());
        }
    }

    public function test_constructor_accepts_ecb_cipher(): void
    {
        self::assertInstanceOf(UniqueEncrypted::class, new UniqueEncrypted('users', 'email'));
        self::assertInstanceOf(ExistsEncrypted::class, new ExistsEncrypted('users', 'email'));
    }

    // ── __call forwarding ──

    public function test_call_forwards_to_inner_rule(): void
    {
        $rule = new UniqueEncrypted('users', 'email');

        $returned = $rule->where('tenant_id', 1)->ignore(5);

        self::assertSame($rule, $returned);
        self::assertSame('unique:users,email,"5",id,tenant_id,"1"', (string) $this->innerRule($rule));
    }

    public function test_exists_call_forwards_to_inner_rule(): void
    {
        $rule = new ExistsEncrypted('users', 'email');

        self::assertSame($rule, $rule->where('tenant_id', 1));
        self::assertSame('exists:users,email,tenant_id,"1"', (string) $this->innerRule($rule));
    }

    private function innerRule(object $rule): object
    {
        $property = new \ReflectionProperty($rule, 'rule');

        return $property->getValue($rule);
    }

    // ── passes() with scripted presence verifier ──

    public function test_unique_passes_when_no_duplicate(): void
    {
        $rule = new UniqueEncrypted('users', 'email');
        $this->verifier->count = 0;

        self::assertTrue($rule->passes('email', 'jane@example.com'));
        self::assertSame([['users', 'email', null, []]], $this->verifier->calls);
    }

    public function test_unique_fails_when_duplicate_exists(): void
    {
        $rule = new UniqueEncrypted('users', 'email');
        $this->verifier->count = 1;

        self::assertFalse($rule->passes('email', 'jane@example.com'));
    }

    public function test_exists_passes_when_row_exists(): void
    {
        $rule = new ExistsEncrypted('users', 'email');
        $this->verifier->count = 1;

        self::assertTrue($rule->passes('email', 'jane@example.com'));
    }

    public function test_exists_fails_when_row_missing(): void
    {
        $rule = new ExistsEncrypted('users', 'email');
        $this->verifier->count = 0;

        self::assertFalse($rule->passes('email', 'jane@example.com'));
    }

    public function test_passes_validates_encrypted_value_not_plaintext(): void
    {
        $rule = new UniqueEncrypted('users', 'email');
        $this->verifier->count = 0;

        self::assertTrue($rule->passes('email', 'jane@example.com'));

        $validated = $this->factory->lastData['email'] ?? null;
        self::assertIsString($validated);
        self::assertNotSame('jane@example.com', $validated);
        self::assertTrue(Encryption::isEncrypted($validated));
        self::assertSame('jane@example.com', Encryption::php()->decrypt($validated));
    }

    public function test_dotted_attribute_is_stripped_to_segment(): void
    {
        $rule = new UniqueEncrypted('users', 'email');
        $this->verifier->count = 0;

        self::assertTrue($rule->passes('contact.email', 'a@b.c'));

        self::assertArrayHasKey('contact', $this->factory->lastData);
        self::assertArrayNotHasKey('contact.email', $this->factory->lastData);
    }

    // ── message() ──

    public function test_unique_message_uses_translation(): void
    {
        $this->translator->addLines(['validation.unique' => 'email already taken'], 'en');
        $rule = new UniqueEncrypted('users', 'email');

        self::assertSame('email already taken', $rule->message());
    }

    public function test_exists_message_uses_translation(): void
    {
        $this->translator->addLines(['validation.exists' => 'email must exist'], 'en');
        $rule = new ExistsEncrypted('users', 'email');

        self::assertSame('email must exist', $rule->message());
    }

    public function test_message_falls_back_to_key_when_untranslated(): void
    {
        self::assertSame('validation.unique', (new UniqueEncrypted('users', 'email'))->message());
        self::assertSame('validation.exists', (new ExistsEncrypted('users', 'email'))->message());
    }
}
