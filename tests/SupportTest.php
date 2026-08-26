<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\Encryptable\Tests;

use Erikwang2013\Encryptable\Support\PackagePluginPaths;
use Erikwang2013\Encryptable\Support\PreviousKeysParser;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    // ── PreviousKeysParser ──

    public function test_parse_empty_inputs(): void
    {
        self::assertSame([], PreviousKeysParser::parse(null));
        self::assertSame([], PreviousKeysParser::parse(''));
        self::assertSame([], PreviousKeysParser::parse('   '));
        self::assertSame([], PreviousKeysParser::parse([]));
    }

    public function test_parse_comma_list_trims_items(): void
    {
        self::assertSame(['a', 'b', 'c'], PreviousKeysParser::parse(' a , b ,c '));
    }

    public function test_parse_comma_list_skips_empty_segments(): void
    {
        self::assertSame(['a', 'b'], PreviousKeysParser::parse('a,,b'));
    }

    public function test_parse_json_array(): void
    {
        self::assertSame(['x', 'y'], PreviousKeysParser::parse('["x","y"]'));
        self::assertSame(['1', '2'], PreviousKeysParser::parse('[1, 2]'));
    }

    public function test_parse_invalid_json_falls_back_to_comma_split(): void
    {
        self::assertSame(['["x"'], PreviousKeysParser::parse('["x"'));
    }

    public function test_parse_json_object_is_not_decoded(): void
    {
        self::assertSame(['{"a":"x"}'], PreviousKeysParser::parse('{"a":"x"}'));
    }

    public function test_parse_array_directly(): void
    {
        self::assertSame(['k1', 'k2'], PreviousKeysParser::parse(['k1', 'k2']));
    }

    public function test_parse_associative_array_uses_values(): void
    {
        self::assertSame(['x', 'y'], PreviousKeysParser::parse(['a' => 'x', 'b' => 'y']));
    }

    public function test_parse_scalar_items_are_stringified(): void
    {
        self::assertSame(['1', '1.5', 'k'], PreviousKeysParser::parse([1, 1.5, 'k']));
    }

    public function test_parse_skips_non_scalar_items(): void
    {
        self::assertSame(['0'], PreviousKeysParser::parse([true, false, null, ['nested'], 0]));
    }

    public function test_parse_skips_blank_items_in_arrays(): void
    {
        self::assertSame(['a'], PreviousKeysParser::parse(['a', '', '  ']));
    }

    // ── PackagePluginPaths ──

    public function test_split_vendor_package(): void
    {
        self::assertSame(['erikwang2013', 'encryptable'], PackagePluginPaths::splitVendorPackage());
    }

    public function test_plugin_app_config_relative_path(): void
    {
        self::assertSame(
            'config/plugin/erikwang2013/encryptable/app.php',
            PackagePluginPaths::pluginAppConfigRelativePath()
        );
    }

    public function test_hyperf_plugin_autoload_relative_path(): void
    {
        self::assertSame(
            'config/autoload/plugins/erikwang2013/encryptable.php',
            PackagePluginPaths::hyperfPluginAutoloadRelativePath()
        );
    }

    public function test_hyperf_plugin_config_dot_prefix(): void
    {
        self::assertSame('plugins.erikwang2013.encryptable', PackagePluginPaths::hyperfPluginConfigDotPrefix());
    }

    public function test_composer_name_constant(): void
    {
        self::assertSame('erikwang2013/encryptable', PackagePluginPaths::COMPOSER_NAME);
    }
}
