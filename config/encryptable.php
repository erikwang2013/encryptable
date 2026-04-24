<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Encryption key
    |--------------------------------------------------------------------------
    |
    | The key used to encrypt data.
    | Once defined, never change it or encrypted data cannot be correctly decrypted.
    |
    */

    'key' => env('ENCRYPTION_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Encryption cipher
    |--------------------------------------------------------------------------
    |
    | The cipher used to encrypt data.
    | Once defined, never change it or encrypted data cannot be correctly decrypted.
    | Default value is the cipher algorithm used by default in MySQL.
    |
    */

    'cipher' => env('ENCRYPTION_CIPHER', 'aes-128-ecb'),

    /*
    |--------------------------------------------------------------------------
    | Previous encryption keys (rotation)
    |--------------------------------------------------------------------------
    |
    | Retired keys still accepted for decrypting existing ciphertext. Use the
    | same cipher as the primary key. Env ENCRYPTION_PREVIOUS_KEYS: comma list
    | or JSON array, e.g. "old16bytekeyaaa,older16bytekey" or ["k1","k2"].
    |
    */

    'previous_keys' => \Maize\Encryptable\Support\PreviousKeysParser::parse(env('ENCRYPTION_PREVIOUS_KEYS')),
];
