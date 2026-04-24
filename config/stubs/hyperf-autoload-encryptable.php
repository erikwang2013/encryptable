<?php

declare(strict_types=1);

/**
 * Hyperf：复制本文件为项目下 `config/autoload/encryptable.php`。
 * 键路径须为 `encryptable.key` / `encryptable.cipher`，与 HyperfEncryptableConfig 一致。
 */
return [
    'encryptable' => [
        'key' => env('ENCRYPTION_KEY'),
        'cipher' => env('ENCRYPTION_CIPHER', 'aes-128-ecb'),
    ],
];
