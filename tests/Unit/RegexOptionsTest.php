<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Tests\Unit;

use PHPRegex\Parser\Cache\ArrayCache;
use PHPRegex\Parser\Cache\FilesystemCache;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

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
        $this->assertSame(50_000, (new \ReflectionProperty($regex->parser(), 'maxPatternLength'))->getValue($regex->parser()));
        $this->assertSame(512, (new \ReflectionProperty($regex->parser(), 'maxLookbehindLength'))->getValue($regex->parser()));
        $this->assertFalse((new \ReflectionProperty($regex->parser(), 'runtimePcreValidation'))->getValue($regex->parser()));
        $this->assertSame(2048, (new \ReflectionProperty($regex->parser(), 'maxRecursionDepth'))->getValue($regex->parser()));
        $this->assertSame(80300, $regex->target()->phpVersionId);
    }

    public function test_from_array_with_empty_array(): void
    {
        $options = ParserOptions::fromArray([]);
        $this->assertSame(Regex::DEFAULT_MAX_PATTERN_LENGTH, $options->maxPatternLength);
        $this->assertSame(Regex::DEFAULT_MAX_LOOKBEHIND_LENGTH, $options->maxLookbehindLength);
        $this->assertInstanceOf(ArrayCache::class, $options->cache);
        $this->assertFalse($options->runtimePcreValidation);
        $this->assertSame(1024, $options->maxRecursionDepth);
        $this->assertSame(\PHP_VERSION_ID, $options->target->phpVersionId);
    }

    public function test_from_array_with_null_cache_disables_cache(): void
    {
        $options = ParserOptions::fromArray(['cache' => null]);

        $this->assertInstanceOf(NullCache::class, $options->cache);
    }

    public function test_from_array_parses_php_version_string(): void
    {
        $options = ParserOptions::fromArray(['php_version' => '8.1']);

        $this->assertSame(80100, $options->target->phpVersionId);
    }

    public function test_from_array_parses_php_version_id(): void
    {
        $options = ParserOptions::fromArray(['php_version' => 80401]);

        $this->assertSame(80401, $options->target->phpVersionId);
    }

    public function test_from_array_invalid_php_version(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"php_version" must be a version string like "8.2", a PHP_VERSION_ID integer, or "runtime".');
        ParserOptions::fromArray(['php_version' => 'invalid']);
    }

    public function test_from_array_invalid_php_version_int_zero(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"php_version" must be a version string like "8.2", a PHP_VERSION_ID integer, or "runtime".');
        ParserOptions::fromArray(['php_version' => 0]);
    }

    public function test_from_array_invalid_php_version_empty_string(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"php_version" must be a version string like "8.2", a PHP_VERSION_ID integer, or "runtime".');
        ParserOptions::fromArray(['php_version' => '   ']);
    }

    public function test_from_array_invalid_php_version_digits_low(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"php_version" must be a version string like "8.2", a PHP_VERSION_ID integer, or "runtime".');
        ParserOptions::fromArray(['php_version' => '8000']);
    }

    public function test_from_array_parses_php_version_digits(): void
    {
        $options = ParserOptions::fromArray(['php_version' => '80100']);

        $this->assertSame(80100, $options->target->phpVersionId);
    }

    public function test_from_array_parses_php_version_patch(): void
    {
        $options = ParserOptions::fromArray(['php_version' => '8.1.2']);

        $this->assertSame(80102, $options->target->phpVersionId);
    }

    public function test_a_php_version_alone_gets_the_pcre2_it_bundles(): void
    {
        $options = ParserOptions::fromArray(['php_version' => '8.3']);

        $this->assertSame('10.42', $options->target->pcreVersion);
    }

    public function test_the_running_php_named_as_a_target_still_gets_its_bundled_pcre2(): void
    {
        $options = ParserOptions::fromArray(['php_version' => \PHP_VERSION_ID]);

        $this->assertEquals(PcreTarget::bundledWith(\PHP_VERSION_ID), $options->target);
    }

    public function test_a_pcre_version_alone_keeps_the_running_php(): void
    {
        $options = ParserOptions::fromArray(['pcre_version' => '10.42']);

        $this->assertSame(\PHP_VERSION_ID, $options->target->phpVersionId);
        $this->assertSame('10.42', $options->target->pcreVersion);
    }

    public function test_a_php_and_a_pcre_version_name_the_target(): void
    {
        $options = ParserOptions::fromArray(['php_version' => '8.4', 'pcre_version' => '10.42']);

        $this->assertSame(80400, $options->target->phpVersionId);
        $this->assertSame('10.42', $options->target->pcreVersion);
    }

    public function test_no_version_targets_the_running_engine(): void
    {
        $this->assertEquals(PcreTarget::runtime(), ParserOptions::fromArray(['cache' => null])->target);
        $this->assertEquals(PcreTarget::runtime(), ParserOptions::fromArray([])->target);
    }

    public function test_the_pcre_version_must_name_a_release(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"pcre_version" must be a PCRE2 release like "10.44", not a float.');
        ParserOptions::fromArray(['pcre_version' => 10.42]);
    }

    public function test_runtime_validation_needs_the_running_engine_as_target(): void
    {
        $this->assertTrue(ParserOptions::fromArray(['runtime_pcre_validation' => true])->runtimePcreValidation);

        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"runtime_pcre_validation" compiles with the running PHP, which does not judge the target (PHP 8.4, PCRE2 10.100)');
        ParserOptions::fromArray(['runtime_pcre_validation' => true, 'php_version' => '8.4', 'pcre_version' => '10.100']);
    }

    public function test_from_array_invalid_max_pattern_length(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"max_pattern_length" must be a positive integer.');
        ParserOptions::fromArray(['max_pattern_length' => 0]);
    }

    public function test_from_array_invalid_max_pattern_length_type(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"max_pattern_length" must be a positive integer.');
        ParserOptions::fromArray(['max_pattern_length' => 'invalid']);
    }

    public function test_from_array_invalid_max_lookbehind_length(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"max_lookbehind_length" must be a non-negative integer.');
        ParserOptions::fromArray(['max_lookbehind_length' => -1]);
    }

    public function test_from_array_invalid_max_lookbehind_length_type(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"max_lookbehind_length" must be a non-negative integer.');
        ParserOptions::fromArray(['max_lookbehind_length' => 'invalid']);
    }

    public function test_from_array_invalid_runtime_pcre_validation(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"runtime_pcre_validation" must be a boolean.');
        ParserOptions::fromArray(['runtime_pcre_validation' => 'invalid']);
    }

    public function test_from_array_invalid_max_recursion_depth(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"max_recursion_depth" must be a positive integer.');
        ParserOptions::fromArray(['max_recursion_depth' => 0]);
    }

    public function test_from_array_invalid_max_recursion_depth_type(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"max_recursion_depth" must be a positive integer.');
        ParserOptions::fromArray(['max_recursion_depth' => 'invalid']);
    }

    public function test_from_array_invalid_cache(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('The "cache" option must be null, a cache path, or a CacheInterface implementation.');
        ParserOptions::fromArray(['cache' => 123]);
    }

    public function test_from_array_empty_cache_path(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('The "cache" option cannot be an empty string.');
        ParserOptions::fromArray(['cache' => '']);
    }

    public function test_from_array_valid_cache_path(): void
    {
        $options = ParserOptions::fromArray(['cache' => '/tmp']);
        $this->assertInstanceOf(FilesystemCache::class, $options->cache);
    }

    public function test_from_array_invalid_redos_ignored_patterns(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"redos_ignored_patterns" must be a list of strings.');
        ParserOptions::fromArray(['redos_ignored_patterns' => 'invalid']);
    }

    public function test_from_array_invalid_redos_ignored_patterns_elements(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"redos_ignored_patterns" must contain only strings.');
        ParserOptions::fromArray(['redos_ignored_patterns' => [123]]);
    }

    public function test_from_array_redos_ignored_patterns_deduplicates(): void
    {
        $options = ParserOptions::fromArray(['redos_ignored_patterns' => ['/a/', '/a/', '/b/']]);
        $this->assertSame(['/a/', '/b/'], $options->redosIgnoredPatterns);
    }

    public function test_from_array_invalid_php_version_type(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"php_version" must be a version string like "8.2", a PHP_VERSION_ID integer, or "runtime".');
        ParserOptions::fromArray(['php_version' => []]);
    }

    public function test_php_version_runtime_names_the_running_engine(): void
    {
        // As PHPStan's phpVersion: the running PHP, with the PCRE2 it links.
        $this->assertEquals(PcreTarget::runtime(), ParserOptions::fromArray(['php_version' => 'runtime'])->target);
        $this->assertEquals(PcreTarget::runtime(), ParserOptions::fromArray(['php_version' => ' Runtime '])->target);
        $this->assertSame('10.42', ParserOptions::fromArray(['php_version' => 'runtime', 'pcre_version' => '10.42'])->target->pcreVersion);
    }
}
