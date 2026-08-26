<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Exceptions\DecryptException;
use Erikwang2013\Encryptable\Exceptions\SerializationException;
use Erikwang2013\Encryptable\Exceptions\UnserializationException;
use Erikwang2013\Encryptable\PHPEncrypter;
use PHPUnit\Framework\TestCase;

final class PHPEncrypterTest extends TestCase
{
    private function make(string $key, ?string $cipher = 'aes-128-ecb', array $previous = []): PHPEncrypter
    {
        return new PHPEncrypter(new KeyRingTestConfig($key, $previous, $cipher));
    }

    // ── V1 (non-AEAD) format ──

    public function test_v1_ecb_payload_structure_and_hmac(): void
    {
        $key = str_repeat('k', 16);
        $encrypter = $this->make($key, 'aes-128-ecb');

        $decoded = base64_decode($encrypter->encrypt('secret', false), true);

        self::assertNotFalse($decoded);
        self::assertSame("\x01", $decoded[0]); // V1 marker
        $hmac = substr($decoded, -32);
        $ciphertext = substr($decoded, 1, -32);

        self::assertSame('crypt:secret', openssl_decrypt($ciphertext, 'aes-128-ecb', $key, OPENSSL_RAW_DATA));
        self::assertSame(hash_hmac('sha256', $ciphertext, hash('sha256', $key . ':hmac', true), true), $hmac);
    }

    public function test_v1_cbc_roundtrip(): void
    {
        $encrypter = $this->make(str_repeat('k', 32), 'aes-256-cbc');

        self::assertSame('cbc-value', $encrypter->decrypt($encrypter->encrypt('cbc-value')));
    }

    public function test_v1_cbc_payload_structure(): void
    {
        $key = str_repeat('k', 32);
        $encrypter = $this->make($key, 'aes-256-cbc');

        $decoded = base64_decode($encrypter->encrypt('secret', false), true);
        self::assertNotFalse($decoded);

        $iv = substr($decoded, 1, 16);
        $hmac = substr($decoded, -32);
        $ciphertext = substr($decoded, 1 + 16, -32);

        self::assertSame('crypt:secret', openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv));
        self::assertSame(hash_hmac('sha256', $iv . $ciphertext, hash('sha256', $key . ':hmac', true), true), $hmac);
    }

    // ── V2 (AEAD/GCM) format ──

    public function test_v2_gcm_payload_structure(): void
    {
        $key = str_repeat('k', 32);
        $encrypter = $this->make($key, 'aes-256-gcm');

        $decoded = base64_decode($encrypter->encrypt('secret', false), true);
        self::assertNotFalse($decoded);
        self::assertSame("\x02", $decoded[0]); // V2 marker

        $iv = substr($decoded, 1, 12);
        $tag = substr($decoded, 1 + 12, 16);
        $hmac = substr($decoded, -32);
        $ciphertext = substr($decoded, 1 + 12 + 16, -32);

        self::assertSame('crypt:secret', openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag));
        self::assertSame(hash_hmac('sha256', $iv . $tag . $ciphertext, hash('sha256', $key . ':hmac', true), true), $hmac);
    }

    public function test_v2_aes_128_gcm_roundtrip(): void
    {
        $encrypter = $this->make(str_repeat('k', 16), 'aes-128-gcm');

        self::assertSame('gcm-128', $encrypter->decrypt($encrypter->encrypt('gcm-128')));
    }

    public function test_gcm_uses_random_iv_for_distinct_ciphertexts(): void
    {
        $encrypter = $this->make(str_repeat('k', 32), 'aes-256-gcm');

        self::assertNotSame($encrypter->encrypt('same', false), $encrypter->encrypt('same', false));
    }

    // ── Integrity / tampering ──

    public function test_tampered_ciphertext_throws_in_strict_mode(): void
    {
        $encrypter = $this->make(str_repeat('k', 32), 'aes-256-gcm');
        $payload = $encrypter->encrypt('integrity-check');

        $decoded = base64_decode($payload, true);
        $decoded[2] = $decoded[2] === "\x00" ? "\x01" : "\x00"; // flip a byte in the IV

        $this->expectException(DecryptException::class);
        $encrypter->decrypt(base64_encode($decoded), true, true);
    }

    public function test_tampered_payload_passes_through_in_lax_mode(): void
    {
        $encrypter = $this->make(str_repeat('k', 32), 'aes-256-gcm');
        $payload = $encrypter->encrypt('integrity-check');

        $decoded = base64_decode($payload, true);
        $decoded[2] = $decoded[2] === "\x00" ? "\x01" : "\x00";
        $tampered = base64_encode($decoded);

        self::assertSame($tampered, $encrypter->decrypt($tampered, true, false));
    }

    public function test_invalid_base64_throws_in_strict_mode(): void
    {
        $this->expectException(DecryptException::class);
        $this->expectExceptionMessage('Base64 decoding failed.');
        $this->make(str_repeat('k', 16))->decrypt('!!!not-base64!!!', true, true);
    }

    public function test_invalid_base64_passes_through_in_lax_mode(): void
    {
        $encrypter = $this->make(str_repeat('k', 16));

        self::assertSame('!!!not-base64!!!', $encrypter->decrypt('!!!not-base64!!!', true, false));
    }

    // ── Null / empty handling ──

    public function test_encrypt_null_returns_null(): void
    {
        self::assertNull($this->make(str_repeat('k', 16))->encrypt(null));
    }

    public function test_decrypt_null_returns_null(): void
    {
        self::assertNull($this->make(str_repeat('k', 16))->decrypt(null));
    }

    public function test_decrypt_empty_string_returns_empty_string(): void
    {
        $encrypter = $this->make(str_repeat('k', 16));

        self::assertSame('', $encrypter->decrypt(''));
    }

    // ── isEncrypted ──

    public function test_is_encrypted_false_for_non_strings(): void
    {
        $encrypter = $this->make(str_repeat('k', 16));

        self::assertFalse($encrypter->isEncrypted(123));
        self::assertFalse($encrypter->isEncrypted(null));
        self::assertFalse($encrypter->isEncrypted(['arr']));
        self::assertFalse($encrypter->isEncrypted(3.14));
    }

    public function test_is_encrypted_false_for_plaintext_and_garbage(): void
    {
        $encrypter = $this->make(str_repeat('k', 16));

        self::assertFalse($encrypter->isEncrypted('plain text'));
        self::assertFalse($encrypter->isEncrypted('crypt:abc'));
        self::assertFalse($encrypter->isEncrypted(''));
    }

    public function test_is_encrypted_enforces_length_floor(): void
    {
        $encrypter = $this->make(str_repeat('k', 16));

        // \x01 + 32 bytes = 33 bytes → below the 34-byte floor (marker + HMAC + 1)
        self::assertFalse($encrypter->isEncrypted(base64_encode("\x01" . str_repeat("\x00", 32))));
        // 34 bytes → recognized
        self::assertTrue($encrypter->isEncrypted(base64_encode("\x01" . str_repeat("\x00", 33))));
        self::assertTrue($encrypter->isEncrypted(base64_encode("\x02" . str_repeat("\x00", 33))));
    }

    // ── V0 legacy (prefixless) payloads ──

    public function test_v0_legacy_payload_decrypts_without_marker(): void
    {
        $key = str_repeat('k', 16);
        $encrypter = $this->make($key, 'aes-128-ecb');

        $legacy = base64_encode(openssl_encrypt('crypt:legacy', 'aes-128-ecb', $key, OPENSSL_RAW_DATA));

        self::assertSame('legacy', $encrypter->decrypt($legacy, false));
    }

    public function test_v0_legacy_serialized_payload_decrypts(): void
    {
        $key = str_repeat('k', 16);
        $encrypter = $this->make($key, 'aes-128-ecb');

        $legacy = base64_encode(openssl_encrypt('crypt:string:legacy-value', 'aes-128-ecb', $key, OPENSSL_RAW_DATA));

        self::assertSame('legacy-value', $encrypter->decrypt($legacy, true));
    }

    public function test_v0_legacy_payload_is_not_detected_as_encrypted(): void
    {
        $key = str_repeat('k', 16);
        $encrypter = $this->make($key, 'aes-128-ecb');

        $legacy = base64_encode(openssl_encrypt('crypt:legacy', 'aes-128-ecb', $key, OPENSSL_RAW_DATA));

        self::assertFalse($encrypter->isEncrypted($legacy));
        // rotateToCurrentKey therefore treats it as plaintext and returns it as-is
        self::assertSame($legacy, $encrypter->rotateToCurrentKey($legacy, false));
    }

    // ── Rotation ──

    public function test_rotate_to_current_key_propagates_strict_failure(): void
    {
        $encrypter = $this->make(str_repeat('k', 16));
        $payload = $encrypter->encrypt('rotate-me');

        $decoded = base64_decode($payload, true);
        $decoded[0] = "\x02"; // claim V2 format → decrypt must fail hard
        // also corrupt ciphertext so the HMAC check rejects before openssl_decrypt
        $decoded[3] = $decoded[3] === "\x00" ? "\x01" : "\x00";

        $this->expectException(DecryptException::class);
        $encrypter->rotateToCurrentKey(base64_encode($decoded));
    }

    public function test_v2_marker_with_non_aead_cipher_does_not_emit_warning(): void
    {
        $encrypter = $this->make(str_repeat('k', 16)); // aes-128-ecb

        $decoded = base64_decode($encrypter->encrypt('guard-value', false), true);
        $decoded[0] = "\x02"; // claim V2 while HMAC stays valid (HMAC does not cover the marker byte)

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ((bool) (error_reporting() & $errno)) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $result = $encrypter->decrypt(base64_encode($decoded));
        } finally {
            restore_error_handler();
        }

        self::assertSame(base64_encode($decoded), $result, 'Invalid combo must fail silently.');
        self::assertSame([], $warnings, 'Non-AEAD cipher must not be passed a tag.');
    }

    public function test_rotate_to_current_key_noop_for_null(): void
    {
        self::assertNull($this->make(str_repeat('k', 16))->rotateToCurrentKey(null));
    }

    // ── Serialization coupling ──

    public function test_encrypt_with_serialize_false_stores_raw_string(): void
    {
        $encrypter = $this->make(str_repeat('k', 16));

        $encrypted = $encrypter->encrypt('raw', false);

        self::assertSame('raw', $encrypter->decrypt($encrypted, false));
    }

    public function test_decrypt_raw_payload_with_unserialize_throws(): void
    {
        $encrypter = $this->make(str_repeat('k', 16));
        $encrypted = $encrypter->encrypt('raw', false);

        $this->expectException(UnserializationException::class);
        $encrypter->decrypt($encrypted, true);
    }

    public function test_encrypt_unsupported_type_throws(): void
    {
        $this->expectException(SerializationException::class);
        $this->make(str_repeat('k', 16))->encrypt(['nested']);
    }

    // ── Double-encryption guard ──

    public function test_encrypt_already_encrypted_payload_is_unchanged(): void
    {
        $encrypter = $this->make(str_repeat('k', 32), 'aes-256-gcm');
        $once = $encrypter->encrypt('double');

        self::assertSame($once, $encrypter->encrypt($once));
    }

    // ── Scalar roundtrips through serialize ──

    public function test_scalar_roundtrips(): void
    {
        $encrypter = $this->make(str_repeat('k', 16));

        self::assertSame('str', $encrypter->decrypt($encrypter->encrypt('str')));
        self::assertSame(42, $encrypter->decrypt($encrypter->encrypt(42)));
        self::assertSame(3.14, $encrypter->decrypt($encrypter->encrypt(3.14)));
        self::assertTrue($encrypter->decrypt($encrypter->encrypt(true)));
        self::assertFalse($encrypter->decrypt($encrypter->encrypt(false)));
    }
}
