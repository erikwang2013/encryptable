<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Support;

/**
 * Locky · 小锁灵 — the project pet, shipped as {@code docs/pet.svg}.
 *
 * The pet tells the key-rotation story: the amber key is the current primary key,
 * the grey keys on the same ring are {@code previous_keys} (still able to decrypt
 * old ciphertext). Purely decorative — nothing here touches keys, ciphers or payloads.
 * Use it in install notices, CLI banners, or an admin/error page of your own app.
 */
final class Mascot
{
    /** Display name of the pet / 项目宠物名称 */
    public const NAME = 'Locky · 小锁灵';

    private const SVG_PATH = __DIR__.'/../../docs/pet.svg';

    private const DATA_URI_PREFIX = 'data:image/svg+xml;base64,';

    private static ?string $svgCache = null;

    /**
     * Raw SVG markup of the pet, or an empty string when {@code docs/pet.svg} is not shipped.
     * The file is read once per process.
     */
    public static function svg(): string
    {
        return self::$svgCache ??= (is_file(self::SVG_PATH) ? (string) file_get_contents(self::SVG_PATH) : '');
    }

    /**
     * The same SVG as a data URI, ready for {@code <img src="...">} in HTML output.
     */
    public static function dataUri(): string
    {
        return self::DATA_URI_PREFIX.base64_encode(self::svg());
    }

    /**
     * Monospace rendition for CLI / Composer output.
     */
    public static function ascii(): string
    {
        return <<<'ASCII'
           .--.
          /    \
         _|    |_
        |        |
        |  o  o  |
        |    ^   |
        |________|
            Locky · 小锁灵
        ASCII;
    }
}
