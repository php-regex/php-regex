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

namespace PHPRegex\Tests\Support;

use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\RegexParser;

/**
 * Every string CaptureShape writes for a pattern, read for PHP 8.4 with
 * PCRE2 10.44, and their digest: what
 * tests/Fixtures/CaptureShapeParity/digests.php records for the parity
 * corpus, so a change to how the strings are built (and not to what the
 * analyzer knows) shows as a changed digest.
 */
final class CaptureShapeDigest
{
    public const FILE = __DIR__.'/../Fixtures/CaptureShapeParity/digests.php';

    public const CORPUS = __DIR__.'/../Fixtures/CaptureShapeParity/cases.php';

    private const MATCH_FLAGS = [0, \PREG_OFFSET_CAPTURE, \PREG_UNMATCHED_AS_NULL, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL];

    private const MATCH_ALL_FLAGS = [
        \PREG_PATTERN_ORDER, \PREG_PATTERN_ORDER | \PREG_OFFSET_CAPTURE, \PREG_PATTERN_ORDER | \PREG_UNMATCHED_AS_NULL, \PREG_PATTERN_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL,
        \PREG_SET_ORDER, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL,
    ];

    /**
     * The strings, keyed by the call that writes them: "matchShape(256)".
     *
     * @return array<string, string>
     */
    public static function strings(string $pattern): array
    {
        // A fixed target: the digests must not change with the PCRE2 running the tests.
        $shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create(['php_version' => '8.4', 'pcre_version' => '10.44'])->parse($pattern));

        $strings = [];
        foreach (self::MATCH_FLAGS as $flags) {
            $strings['matchShape('.$flags.')'] = $shape->matchShape($flags);
        }
        foreach (self::MATCH_ALL_FLAGS as $flags) {
            $strings['matchAllShape('.$flags.')'] = $shape->matchAllShape($flags);
        }

        return $strings;
    }

    public static function of(string $pattern): string
    {
        return sha1(json_encode(self::strings($pattern), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * The patterns of the parity corpus, each once, in corpus order.
     *
     * @return list<string>
     */
    public static function patterns(): array
    {
        /** @var list<array{pattern: string}> $rows */
        $rows = require self::CORPUS;

        return array_values(array_unique(array_column($rows, 'pattern')));
    }
}
