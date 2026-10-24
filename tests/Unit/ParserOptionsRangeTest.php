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

use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A PHP version names a release whole, and a limit is in its range, or the
 * options are refused with the value they cannot read: PHP_VERSION_ID 8
 * judged no PHP, "8.2.100" judged PHP 8.3, and a negative length refused
 * every pattern.
 */
final class ParserOptionsRangeTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    #[DataProvider('provideShortNumbers')]
    public function test_a_number_too_short_for_a_php_version_id_says_how_to_write_it(int|string $version, string $message): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains($message);

        ParserOptions::fromArray(['php_version' => $version]);
    }

    /**
     * @return iterable<string, array{version: int|string, message: string}>
     */
    public static function provideShortNumbers(): iterable
    {
        yield 'a major alone' => ['version' => 8, 'message' => '"php_version" 8 is no PHP_VERSION_ID: write PHP 8.0 as 80000 or "8.0".'];
        yield 'a major and a minor run together' => ['version' => 82, 'message' => '"php_version" 82 is no PHP_VERSION_ID: write PHP 8.2 as 80200 or "8.2".'];
        yield 'a major alone, as a string' => ['version' => '8', 'message' => '"php_version" 8 is no PHP_VERSION_ID: write PHP 8.0 as 80000 or "8.0".'];
        yield 'PHP 7.4 run together' => ['version' => '74', 'message' => '"php_version" 74 is no PHP_VERSION_ID: write PHP 7.4 as 70400 or "7.4".'];
        $below = static fn (int $version): string => \sprintf('"php_version" %d is below 70000, the PHP_VERSION_ID of PHP 7.0: write a version as "8.2", or as its PHP_VERSION_ID, 80200.', $version);
        // Read as a major or a major and a minor, they would name no PHP
        // the library judges: no hint to write one.
        yield 'a major below 7' => ['version' => 5, 'message' => $below(5)];
        yield 'a number read as 1.0' => ['version' => 10, 'message' => $below(10)];
        yield 'a number read as 6.9' => ['version' => 69, 'message' => $below(69)];
    }

    #[Test]
    #[DataProvider('provideRefusedVersions')]
    public function test_a_php_version_that_names_no_release_is_refused(int|string $version, string $message): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains($message);

        ParserOptions::fromArray(['php_version' => $version]);
    }

    /**
     * @return iterable<string, array{version: int|string, message: string}>
     */
    public static function provideRefusedVersions(): iterable
    {
        $invalid = '"php_version" must be a version string like "8.2", a PHP_VERSION_ID integer, or "runtime".';

        yield 'text after the version' => ['version' => '8.2foo', 'message' => $invalid];
        yield 'a wildcard minor' => ['version' => '8.x', 'message' => $invalid];
        yield 'four parts' => ['version' => '8.2.1.9', 'message' => $invalid];
        yield 'a patch past 99' => ['version' => '8.2.100', 'message' => $invalid];
        yield 'a minor past 99' => ['version' => '8.100', 'message' => $invalid];
        yield 'a trailing dot' => ['version' => '8.', 'message' => $invalid];
        yield 'a version id below PHP 7' => ['version' => 69999, 'message' => '"php_version" 69999 is below 70000, the PHP_VERSION_ID of PHP 7.0: write a version as "8.2", or as its PHP_VERSION_ID, 80200.'];
        yield 'a version id string below PHP 7' => ['version' => '50600', 'message' => '"php_version" 50600 is below 70000, the PHP_VERSION_ID of PHP 7.0: write a version as "8.2", or as its PHP_VERSION_ID, 80200.'];
        yield 'zero' => ['version' => 0, 'message' => $invalid];
        yield 'a negative id' => ['version' => -80200, 'message' => $invalid];
        yield 'a version of zero' => ['version' => '0.0', 'message' => $invalid];
        $below = static fn (string $version): string => \sprintf('"php_version" %s is below 7.0: write a version as "8.2", or as its PHP_VERSION_ID, 80200.', $version);
        yield 'a version below PHP 7' => ['version' => '5.6', 'message' => $below('5.6')];
        yield 'a version just below PHP 7' => ['version' => '6.99.99', 'message' => $below('6.99.99')];
        $past = static fn (string $version): string => \sprintf('"php_version" %s is past PHP 9.99: write a version as "8.2", or as its PHP_VERSION_ID, 80200.', $version);
        yield 'a major past 9' => ['version' => '10.0', 'message' => $past('10.0')];
        yield 'a major far past 9' => ['version' => '99.0', 'message' => $past('99.0')];
        yield 'a version id past PHP 9' => ['version' => 100000, 'message' => $past('100000')];
        yield 'a version id string past PHP 9' => ['version' => '999999999', 'message' => $past('999999999')];
        yield 'the largest integer' => ['version' => \PHP_INT_MAX, 'message' => $past((string) \PHP_INT_MAX)];
    }

    #[Test]
    #[DataProvider('provideAcceptedVersions')]
    public function test_a_php_version_that_names_a_release_is_read(int|string $version, int $phpVersionId): void
    {
        $this->assertSame($phpVersionId, ParserOptions::fromArray(['php_version' => $version])->target->phpVersionId);
    }

    /**
     * @return iterable<string, array{version: int|string, phpVersionId: int}>
     */
    public static function provideAcceptedVersions(): iterable
    {
        yield 'the oldest version id' => ['version' => 70000, 'phpVersionId' => 70000];
        yield 'the oldest version id, as a string' => ['version' => '70000', 'phpVersionId' => 70000];
        yield 'the oldest version' => ['version' => '7.0', 'phpVersionId' => 70000];
        yield 'a major alone with its dot zero' => ['version' => '8.0', 'phpVersionId' => 80000];
        yield 'the highest patch' => ['version' => '8.2.99', 'phpVersionId' => 80299];
        yield 'the highest minor' => ['version' => '8.99', 'phpVersionId' => 89900];
        yield 'padded with blanks' => ['version' => ' 8.4.1 ', 'phpVersionId' => 80401];
        yield 'the last version' => ['version' => '9.99.99', 'phpVersionId' => 99999];
        yield 'the last version id' => ['version' => 99999, 'phpVersionId' => 99999];
    }

    /**
     * A setting read for a bridge names its release, however old: the
     * bridge refuses a PHP on PCRE1 with its own reason. A version past
     * PHP 9.99, or of zero, is refused there too.
     */
    #[Test]
    public function test_a_bridge_reads_a_version_below_php_7_and_refuses_one_past_9(): void
    {
        $this->assertSame(50600, ParserOptions::phpVersionIdOf('5.6'));
        $this->assertSame(80200, ParserOptions::phpVersionIdOf(80200));

        foreach (['0.0', '10.0', 100000] as $version) {
            try {
                ParserOptions::phpVersionIdOf($version);
                $this->fail(\sprintf('"%s" was read as a version.', $version));
            } catch (InvalidRegexOptionException $exception) {
                $this->assertStringStartsWith('"php_version"', $exception->getMessage());
            }
        }
    }

    #[Test]
    #[DataProvider('provideRefusedPcreVersions')]
    public function test_a_pcre_version_below_10_is_refused(string $version): void
    {
        try {
            ParserOptions::fromArray(['pcre_version' => $version]);
            $this->fail(\sprintf('"%s" was read as a PCRE2 release.', $version));
        } catch (InvalidRegexOptionException $exception) {
            $this->assertSame(\sprintf('"pcre_version" %s names no PCRE2 release: the first is 10.00.', $version), $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{version: string}>
     */
    public static function provideRefusedPcreVersions(): iterable
    {
        yield 'PCRE1' => ['version' => '8.45'];
        yield 'a major of one' => ['version' => '1.0'];
        yield 'zero' => ['version' => '0.0'];
        yield 'just below 10' => ['version' => '9.99'];
    }

    #[Test]
    #[DataProvider('provideAcceptedPcreVersions')]
    public function test_a_pcre_version_from_10_is_read(string $version, string $pcreVersion): void
    {
        $this->assertSame($pcreVersion, ParserOptions::fromArray(['pcre_version' => $version])->target->pcreVersion);
    }

    /**
     * @return iterable<string, array{version: string, pcreVersion: string}>
     */
    public static function provideAcceptedPcreVersions(): iterable
    {
        yield 'the first release' => ['version' => '10.00', 'pcreVersion' => '10.00'];
        yield 'a release' => ['version' => '10.44', 'pcreVersion' => '10.44'];
        // A release the library does not know yet is judged as the latest.
        yield 'a minor past the known ones' => ['version' => '10.999', 'pcreVersion' => '10.999'];
        yield 'a later major' => ['version' => '11.0', 'pcreVersion' => '11.0'];
    }

    #[Test]
    #[DataProvider('provideOutOfRangeLimits')]
    public function test_the_constructor_refuses_a_limit_out_of_its_range(int $maxPatternLength, int $maxLookbehindLength, int $maxRecursionDepth, string $message): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains($message);

        new ParserOptions($maxPatternLength, $maxLookbehindLength, new NullCache(), maxRecursionDepth: $maxRecursionDepth);
    }

    /**
     * @return iterable<string, array{maxPatternLength: int, maxLookbehindLength: int, maxRecursionDepth: int, message: string}>
     */
    public static function provideOutOfRangeLimits(): iterable
    {
        yield 'a negative pattern length' => ['maxPatternLength' => -5, 'maxLookbehindLength' => 255, 'maxRecursionDepth' => 1024, 'message' => '"max_pattern_length" must be a positive integer.'];
        yield 'a pattern length of zero' => ['maxPatternLength' => 0, 'maxLookbehindLength' => 255, 'maxRecursionDepth' => 1024, 'message' => '"max_pattern_length" must be a positive integer.'];
        yield 'a negative lookbehind length' => ['maxPatternLength' => 100, 'maxLookbehindLength' => -1, 'maxRecursionDepth' => 1024, 'message' => '"max_lookbehind_length" must be a non-negative integer.'];
        yield 'a recursion depth of zero' => ['maxPatternLength' => 100, 'maxLookbehindLength' => 255, 'maxRecursionDepth' => 0, 'message' => '"max_recursion_depth" must be a positive integer.'];
    }

    #[Test]
    public function test_the_constructor_takes_the_smallest_limits(): void
    {
        $options = new ParserOptions(1, 0, new NullCache(), maxRecursionDepth: 1);

        $this->assertSame(1, $options->maxPatternLength);
        $this->assertSame(0, $options->maxLookbehindLength);
        $this->assertSame(1, $options->maxRecursionDepth);
    }
}
