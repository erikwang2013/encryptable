<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use DOMDocument;
use Erikwang2013\Encryptable\Encryption;
use Erikwang2013\Encryptable\Support\Mascot;
use PHPUnit\Framework\TestCase;

final class MascotTest extends TestCase
{
    public function test_svg_is_shipped_and_parses_as_xml(): void
    {
        $svg = Mascot::svg();

        self::assertNotSame('', $svg);
        self::assertStringStartsWith('<svg', ltrim($svg));

        $doc = new DOMDocument;
        self::assertTrue($doc->loadXML($svg), 'docs/pet.svg must be well-formed XML.');
        self::assertSame('svg', $doc->documentElement?->nodeName);
    }

    public function test_data_uri_wraps_the_svg(): void
    {
        $prefix = 'data:image/svg+xml;base64,';

        self::assertStringStartsWith($prefix, Mascot::dataUri());
        self::assertSame(Mascot::svg(), base64_decode(substr(Mascot::dataUri(), strlen($prefix))));
    }

    public function test_ascii_pet_is_multiline_and_signed(): void
    {
        $ascii = Mascot::ascii();

        self::assertStringContainsString(Mascot::NAME, $ascii);
        self::assertGreaterThanOrEqual(6, substr_count($ascii, "\n"));
    }

    public function test_facade_exposes_the_pet(): void
    {
        self::assertSame(Mascot::svg(), Encryption::mascot());
    }
}
