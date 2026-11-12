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

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Assert;

/**
 * What a command that reads one pattern reports when it judges a range of
 * PHP versions: the "range" list of its JSON document, one entry per PHP
 * version judged, the floor first, each with its own verdict.
 *
 * The patterns and the range judged are RangePatterns', each read first
 * on the running engine with RangeFixtureReadings.
 */
trait SinglePatternRange
{
    use PcreRule;
    use RangeFixtureReadings;

    /**
     * The verdict a version gives a pattern on its own, as a single
     * --php-version judges it: the error a refused pattern is reported with.
     *
     * @return array{is_valid: bool, error: string|null, error_code: string|null}
     */
    private static function verdictAt(int $phpVersionId, string $pattern): array
    {
        $validation = Regex::create(['php_version' => $phpVersionId, 'pcre_version' => self::judgedPcre($phpVersionId)])->validate($pattern);

        return [
            'is_valid' => $validation->isValid,
            'error' => $validation->isValid ? null : $validation->error,
            'error_code' => $validation->isValid ? null : $validation->errorCode?->value,
        ];
    }

    /**
     * Whether a version reads "a{,3}" into another tree than the floor:
     * one of them reads a quantifier (PCRE2 10.43 and later), the other
     * text. The floor's PHP may link 10.43 or later, as PHP 8.1 does on
     * some hosts: then 8.2 and 8.3, which bundle older releases, differ.
     *
     * @param list<int> $points the floor first
     *
     * @return \Closure(int): bool
     */
    private static function readsQuantifierOtherwiseThanTheFloor(array $points): \Closure
    {
        $reads = static fn (int $point): bool => version_compare(self::judgedPcre($point), '10.43', '>=');

        return static fn (int $point): bool => $reads($point) !== $reads($points[0]);
    }

    /**
     * The first version after the floor that reads "a{,3}" otherwise than
     * the floor, which the range's warning names.
     *
     * @param list<int> $points the floor first
     */
    private static function firstPointReadingQuantifierOtherwise(array $points): int
    {
        $point = self::readsQuantifierOtherwiseFrom($points);
        Assert::assertNotNull($point, 'Every version of the range reads "a{,3}" as the floor does.');

        return $point;
    }

    /**
     * The words lint and the language server use for a pattern a later
     * version reads into another tree (RangeWording::readsOtherwise()).
     */
    private static function parsesDifferentlyMessage(int $phpVersionId): string
    {
        return \sprintf('From PHP %s (PCRE2 %s) the pattern parses differently: the same text means something else there.', ProjectTarget::phpLabel($phpVersionId), self::judgedPcre($phpVersionId));
    }

    /**
     * The words lint and the language server use for a pattern later
     * versions refuse: "On PHP 8.5 and later: " then the error.
     */
    private static function refusedOnMessage(string $versions, int $phpVersionId, string $pattern): string
    {
        $error = self::verdictAt($phpVersionId, $pattern)['error'];
        Assert::assertIsString($error, 'PHP '.ProjectTarget::phpLabel($phpVersionId).' refuses '.$pattern);

        return 'On '.$versions.': '.$error;
    }

    /**
     * Check a "range" list: one entry per version, in that order, each
     * with its PHP, its PCRE2 and its own verdict; "parses_differently"
     * where $differs says for the versions that accept it, and false where
     * the version refuses it: there is no tree to compare there.
     *
     * @param list<int>           $points
     * @param callable(int): bool $isValid whether the version accepts the pattern
     * @param callable(int): bool $differs whether the version reads the pattern into another tree than the floor's
     */
    private static function assertRange(mixed $range, array $points, string $pattern, callable $isValid, callable $differs): void
    {
        Assert::assertIsArray($range, 'The document has no "range" list.');
        Assert::assertTrue(array_is_list($range), 'The "range" is a list.');
        Assert::assertSame(
            array_map(ProjectTarget::phpLabel(...), $points),
            array_map(static fn (mixed $entry): mixed => \is_array($entry) ? ($entry['php'] ?? null) : null, $range),
            'One entry per version judged, the floor first.',
        );

        foreach ($points as $index => $point) {
            $entry = JsonContract::asArray($range[$index] ?? null);
            $label = 'PHP '.ProjectTarget::phpLabel($point);
            Assert::assertSame(['php', 'pcre', 'is_valid', 'error', 'error_code', 'parses_differently'], array_keys($entry), $label);
            Assert::assertSame(self::judgedPcre($point), $entry['pcre'], $label);

            $valid = $isValid($point);
            Assert::assertSame($valid, $entry['is_valid'], $label);
            Assert::assertSame(self::verdictAt($point, $pattern), ['is_valid' => $entry['is_valid'], 'error' => $entry['error'], 'error_code' => $entry['error_code']], $label);
            Assert::assertSame($valid && $differs($point), $entry['parses_differently'], $label.': parses_differently');
        }
    }
}
