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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Tests\Support\CaptureShapeDigest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The strings CaptureShape::matchShape() and matchAllShape() write stay
 * byte-identical for every pattern of the parity corpus while the analysis
 * version stays: how the strings are built may change, what they say may
 * not. A new analysis version records new digests
 * (tests/Tools/write_capture_shape_digests.php).
 */
final class CaptureShapeDigestTest extends TestCase
{
    #[Test]
    public function test_digests_describe_the_current_analysis_version(): void
    {
        $this->assertSame(CaptureShapeAnalyzer::ANALYSIS_VERSION, self::recorded()['version'], 'The analysis version rose: record its digests with tests/Tools/write_capture_shape_digests.php.');
        $this->assertSame(CaptureShapeDigest::patterns(), array_keys(self::recorded()['digests']), 'The parity corpus changed: record its digests with tests/Tools/write_capture_shape_digests.php.');
    }

    #[Test]
    #[DataProvider('providePatterns')]
    public function test_shape_strings_stay_byte_identical(string $pattern): void
    {
        $this->assertSame(
            self::recorded()['digests'][$pattern] ?? null,
            CaptureShapeDigest::of($pattern),
            \sprintf("The strings written for %s changed under the same analysis version; they now read:\n%s", $pattern, json_encode(CaptureShapeDigest::strings($pattern), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE)),
        );
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        foreach (CaptureShapeDigest::patterns() as $index => $pattern) {
            yield \sprintf('#%d %s', $index, $pattern) => ['pattern' => $pattern];
        }
    }

    /**
     * @return array{version: string, digests: array<string, string>}
     */
    private static function recorded(): array
    {
        /** @var array{version: string, digests: array<string, string>} $recorded */
        $recorded = require CaptureShapeDigest::FILE;

        return $recorded;
    }
}
