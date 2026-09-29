<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RegexParser\Cache\FilesystemCache;
use RegexParser\Cache\NullCache;
use RegexParser\Exception\InvalidRegexOptionException;
use RegexParser\PcreTarget;
use RegexParser\Regex;
use RegexParser\RegexOptions;

final class RegexOptionsTest extends TestCase
{
    public function test_create_with_unknown_option_throws(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        Regex::create(['unknown_option' => true]);
    }

    public function test_create_with_valid_options(): void
    {
        $regex = Regex::create([
            'max_pattern_length' => 50_000,
            'max_lookbehind_length' => 512,
            'cache' => new NullCache(),
            'redos_ignored_patterns' => ['/safe/'],
            'runtime_pcre_validation' => false,
            'max_recursion_depth' => 2048,
            'php_version' => '8.3',
        ]);
        $this->assertSame(50_000, (new \ReflectionProperty($regex, 'maxPatternLength'))->getValue($regex));
        $this->assertSame(512, (new \ReflectionProperty($regex, 'maxLookbehindLength'))->getValue($regex));
        $this->assertFalse((new \ReflectionProperty($regex, 'runtimePcreValidation'))->getValue($regex));
        $this->assertSame(2048, (new \ReflectionProperty($regex, 'maxRecursionDepth'))->getValue($regex));
        $this->assertSame(80300, $regex->target()->phpVersionId);
    }

    public function test_from_array_with_empty_array(): void
    {
        $options = RegexOptions::fromArray([]);
        $this->assertSame(Regex::DEFAULT_MAX_PATTERN_LENGTH, $options->maxPatternLength);
        $this->assertSame(Regex::DEFAULT_MAX_LOOKBEHIND_LENGTH, $options->maxLookbehindLength);
        $this->assertInstanceOf(FilesystemCache::class, $options->cache);
        $this->assertFalse($options->runtimePcreValidation);
        $this->assertSame(1024, $options->maxRecursionDepth);
        $this->assertSame(\PHP_VERSION_ID, $options->target->phpVersionId);
    }

    public function test_from_array_with_null_cache_disables_cache(): void
    {
        $options = RegexOptions::fromArray(['cache' => null]);

        $this->assertInstanceOf(NullCache::class, $options->cache);
    }

    public function test_from_array_parses_php_version_string(): void
    {
        $options = RegexOptions::fromArray(['php_version' => '8.1']);

        $this->assertSame(80100, $options->target->phpVersionId);
    }

    public function test_from_array_parses_php_version_id(): void
    {
        $options = RegexOptions::fromArray(['php_version' => 80401]);

        $this->assertSame(80401, $options->target->phpVersionId);
    }

    public function test_from_array_invalid_php_version(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"php_version" must be a version string like "8.2" or a PHP_VERSION_ID integer.');
        RegexOptions::fromArray(['php_version' => 'invalid']);
    }

    public function test_from_array_invalid_php_version_int_zero(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"php_version" must be a version string like "8.2" or a PHP_VERSION_ID integer.');
        RegexOptions::fromArray(['php_version' => 0]);
    }

    public function test_from_array_invalid_php_version_empty_string(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"php_version" must be a version string like "8.2" or a PHP_VERSION_ID integer.');
        RegexOptions::fromArray(['php_version' => '   ']);
    }

    public function test_from_array_invalid_php_version_digits_low(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"php_version" must be a version string like "8.2" or a PHP_VERSION_ID integer.');
        RegexOptions::fromArray(['php_version' => '8000']);
    }

    public function test_from_array_parses_php_version_digits(): void
    {
        $options = RegexOptions::fromArray(['php_version' => '80100']);

        $this->assertSame(80100, $options->target->phpVersionId);
    }

    public function test_from_array_parses_php_version_patch(): void
    {
        $options = RegexOptions::fromArray(['php_version' => '8.1.2']);

        $this->assertSame(80102, $options->target->phpVersionId);
    }

    public function test_a_php_version_alone_gets_the_pcre2_it_bundles(): void
    {
        $options = RegexOptions::fromArray(['php_version' => '8.3']);

        $this->assertSame('10.42', $options->target->pcreVersion);
    }

    public function test_the_running_php_named_as_a_target_still_gets_its_bundled_pcre2(): void
    {
        $options = RegexOptions::fromArray(['php_version' => \PHP_VERSION_ID]);

        $this->assertEquals(PcreTarget::bundledWith(\PHP_VERSION_ID), $options->target);
    }

    public function test_a_pcre_version_alone_keeps_the_running_php(): void
    {
        $options = RegexOptions::fromArray(['pcre_version' => '10.42']);

        $this->assertSame(\PHP_VERSION_ID, $options->target->phpVersionId);
        $this->assertSame('10.42', $options->target->pcreVersion);
    }

    public function test_a_php_and_a_pcre_version_name_the_target(): void
    {
        $options = RegexOptions::fromArray(['php_version' => '8.4', 'pcre_version' => '10.42']);

        $this->assertSame(80400, $options->target->phpVersionId);
        $this->assertSame('10.42', $options->target->pcreVersion);
    }

    public function test_no_version_targets_the_running_engine(): void
    {
        $this->assertEquals(PcreTarget::runtime(), RegexOptions::fromArray(['cache' => null])->target);
        $this->assertEquals(PcreTarget::runtime(), RegexOptions::fromArray([])->target);
    }

    public function test_the_pcre_version_must_name_a_release(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"pcre_version" must be a PCRE2 release like "10.44", not a float.');
        RegexOptions::fromArray(['pcre_version' => 10.42]);
    }

    public function test_runtime_validation_needs_the_running_engine_as_target(): void
    {
        $this->assertTrue(RegexOptions::fromArray(['runtime_pcre_validation' => true])->runtimePcreValidation);

        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"runtime_pcre_validation" compiles with the running PHP, which does not judge the target (PHP 8.4, PCRE2 10.100)');
        RegexOptions::fromArray(['runtime_pcre_validation' => true, 'php_version' => '8.4', 'pcre_version' => '10.100']);
    }

    public function test_from_array_invalid_max_pattern_length(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"max_pattern_length" must be a positive integer.');
        RegexOptions::fromArray(['max_pattern_length' => 0]);
    }

    public function test_from_array_invalid_max_pattern_length_type(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"max_pattern_length" must be a positive integer.');
        RegexOptions::fromArray(['max_pattern_length' => 'invalid']);
    }

    public function test_from_array_invalid_max_lookbehind_length(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"max_lookbehind_length" must be a non-negative integer.');
        RegexOptions::fromArray(['max_lookbehind_length' => -1]);
    }

    public function test_from_array_invalid_max_lookbehind_length_type(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"max_lookbehind_length" must be a non-negative integer.');
        RegexOptions::fromArray(['max_lookbehind_length' => 'invalid']);
    }

    public function test_from_array_invalid_runtime_pcre_validation(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"runtime_pcre_validation" must be a boolean.');
        RegexOptions::fromArray(['runtime_pcre_validation' => 'invalid']);
    }

    public function test_from_array_invalid_max_recursion_depth(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"max_recursion_depth" must be a positive integer.');
        RegexOptions::fromArray(['max_recursion_depth' => 0]);
    }

    public function test_from_array_invalid_max_recursion_depth_type(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"max_recursion_depth" must be a positive integer.');
        RegexOptions::fromArray(['max_recursion_depth' => 'invalid']);
    }

    public function test_from_array_invalid_cache(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('The "cache" option must be null, a cache path, or a CacheInterface implementation.');
        RegexOptions::fromArray(['cache' => 123]);
    }

    public function test_from_array_empty_cache_path(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('The "cache" option cannot be an empty string.');
        RegexOptions::fromArray(['cache' => '']);
    }

    public function test_from_array_valid_cache_path(): void
    {
        $options = RegexOptions::fromArray(['cache' => '/tmp']);
        $this->assertInstanceOf(FilesystemCache::class, $options->cache);
    }

    public function test_from_array_invalid_redos_ignored_patterns(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"redos_ignored_patterns" must be a list of strings.');
        RegexOptions::fromArray(['redos_ignored_patterns' => 'invalid']);
    }

    public function test_from_array_invalid_redos_ignored_patterns_elements(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"redos_ignored_patterns" must contain only strings.');
        RegexOptions::fromArray(['redos_ignored_patterns' => [123]]);
    }

    public function test_from_array_redos_ignored_patterns_deduplicates(): void
    {
        $options = RegexOptions::fromArray(['redos_ignored_patterns' => ['/a/', '/a/', '/b/']]);
        $this->assertSame(['/a/', '/b/'], $options->redosIgnoredPatterns);
    }

    public function test_from_array_invalid_php_version_type(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"php_version" must be a version string like "8.2" or a PHP_VERSION_ID integer.');
        RegexOptions::fromArray(['php_version' => []]);
    }
}
