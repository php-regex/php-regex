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

use PHPRegex\Parser\PhpVersionGates;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\LibrarySource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every rule that depends on the PHP version reads it through one class,
 * which also lists the PHP versions a rule changes at: the compatibility
 * matrix and the linter's range read those points from it, so a new gate
 * widens both. A version compared as a raw integer anywhere else in src/
 * would be a gate they do not see.
 *
 * Comparisons with PHP_VERSION_ID, which ask about the PHP running the
 * library rather than the PHP judged, are not gates.
 *
 * A gate where a pattern keeps its verdict and a refused one only gets
 * another error is no point: the matrix and the ranges have nothing to
 * judge there.
 */
final class PhpVersionGatesTest extends TestCase
{
    private const GATE_CLASS = 'src/Parser/PhpVersionGates.php';

    private const COMPARISONS = [\T_IS_SMALLER_OR_EQUAL, \T_IS_GREATER_OR_EQUAL, \T_IS_EQUAL, \T_IS_IDENTICAL, \T_IS_NOT_EQUAL, \T_IS_NOT_IDENTICAL, \T_SPACESHIP];

    #[Test]
    public function test_the_gate_class_exists_and_is_internal(): void
    {
        $this->assertFileExists(__DIR__.'/../../../'.self::GATE_CLASS);
        $this->assertStringContainsString('@internal', (string) file_get_contents(__DIR__.'/../../../'.self::GATE_CLASS));
    }

    #[Test]
    public function test_every_gate_the_library_judges_is_a_point(): void
    {
        // Every integer constant names a PHP version a rule changes at; one
        // below the floor is a version the library never judges.
        $missing = [];
        foreach ((new \ReflectionClass(PhpVersionGates::class))->getReflectionConstants() as $constant) {
            $version = $constant->getValue();
            // A gate where only the error reported changes is named apart.
            if (\is_int($version) && $version >= PhpVersionGates::FLOOR && !\in_array($version, PhpVersionGates::POINTS, true) && !\in_array($version, PhpVersionGates::ERROR_ONLY, true)) {
                $missing[] = $constant->getName().' = '.$version;
            }
        }

        $this->assertSame([], $missing, 'Add each gate to PhpVersionGates::POINTS, or the matrix and the lint range do not judge it.');
        $this->assertSame([], array_values(array_intersect(PhpVersionGates::ERROR_ONLY, PhpVersionGates::POINTS)), 'A gate is a point or changes the error reported only, never both.');
        $this->assertContains(PhpVersionGates::BUNDLES_PCRE2_10_40_WITH_ITS_FIXES, PhpVersionGates::ERROR_ONLY);
        $this->assertNotContains(PhpVersionGates::BUNDLES_PCRE2_10_40_WITH_ITS_FIXES, PhpVersionGates::POINTS);
        $this->assertContains(PhpVersionGates::NO_AUTO_CAPTURE_MODIFIER, PhpVersionGates::POINTS);
        $this->assertNotContains(PhpVersionGates::EVAL_MODIFIER_REMOVED, PhpVersionGates::POINTS);
    }

    /**
     * PHP 8.2.35 bundles PCRE2 10.40 with the fixes PCRE2 keeps for that
     * release. One of them changes which error a pattern that is both too
     * large and wrong elsewhere is refused with, never whether it is
     * refused: the gate is not a point.
     */
    #[Test]
    #[DataProvider('providePatternsAroundTheSizeLimit')]
    public function test_the_fixes_php_8_2_35_bundles_change_the_error_reported_only(string $pattern, bool $valid, bool $sameError): void
    {
        $before = RegexParser::create(['cache' => null, 'php_version' => 80234, 'pcre_version' => '10.40', 'runtime_pcre_validation' => false])->validate($pattern);
        $from = RegexParser::create(['cache' => null, 'php_version' => PhpVersionGates::BUNDLES_PCRE2_10_40_WITH_ITS_FIXES, 'pcre_version' => '10.40', 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame($valid, $before->isValid, (string) $before->error);
        $this->assertSame($valid, $from->isValid, (string) $from->error);
        $this->assertSame($sameError, [$before->errorCode, $before->offset] === [$from->errorCode, $from->offset]);
    }

    /**
     * @return iterable<string, array{pattern: string, valid: bool, sameError: bool}>
     */
    public static function providePatternsAroundTheSizeLimit(): iterable
    {
        yield 'under the limit' => ['pattern' => '/(?:[^a]){8190}aa|b/', 'valid' => true, 'sameError' => true];
        yield 'at the limit' => ['pattern' => '/(?:[^a]){8190}aaaa\\d/', 'valid' => true, 'sameError' => true];
        yield 'one unit past the limit' => ['pattern' => '/(?:[^a]){8190}aaaaa/', 'valid' => false, 'sameError' => true];
        yield 'past the limit where a branch ends' => ['pattern' => '/(?:[^a]){8190}aaaa|b/', 'valid' => false, 'sameError' => false];
        yield 'a missing group under the limit' => ['pattern' => '/(?:[^a]){8190}aa|\\k<n>/', 'valid' => false, 'sameError' => true];
        yield 'a missing group past the limit' => ['pattern' => '/(?:[^a]){5000}|(?:[^a]){4000}\\k<n>(?:[^a]){1000}/', 'valid' => false, 'sameError' => false];
    }

    #[Test]
    public function test_the_gate_of_the_fixes_holds_for_the_php_8_2_releases_from_8_2_35(): void
    {
        $this->assertFalse(PhpVersionGates::bundlesPcre2WithItsFixes(80234));
        $this->assertTrue(PhpVersionGates::bundlesPcre2WithItsFixes(80235));
        $this->assertTrue(PhpVersionGates::bundlesPcre2WithItsFixes(80299));
        $this->assertFalse(PhpVersionGates::bundlesPcre2WithItsFixes(80300));
        $this->assertFalse(PhpVersionGates::bundlesPcre2WithItsFixes(80500));
    }

    #[Test]
    public function test_the_points_ascend_from_the_floor_without_repeating(): void
    {
        $points = PhpVersionGates::POINTS;
        $sorted = array_values(array_unique($points));
        sort($sorted);

        $this->assertSame($sorted, $points);
        $this->assertSame(PhpVersionGates::FLOOR, $points[0]);
    }

    #[Test]
    public function test_no_php_version_is_compared_as_a_raw_integer_outside_the_gate_class(): void
    {
        $found = [];
        foreach (LibrarySource::files() as $path => $tokens) {
            if (self::GATE_CLASS === $path || str_contains($path, '/Tests/')) {
                continue;
            }

            foreach ($tokens as $index => $token) {
                if (!\is_array($token) || \T_LNUMBER !== $token[0] || 1 !== preg_match('/^[5-9]0\d{3}$/', $token[1])) {
                    continue;
                }

                $before = self::significant($tokens, $index, -1);
                $after = self::significant($tokens, $index, 1);
                if (!self::isComparison($before) && !self::isComparison($after)) {
                    continue;
                }

                // The running PHP is not a target.
                $other = self::isComparison($before) ? self::significant($tokens, self::position($tokens, $index, -1), -1) : self::significant($tokens, self::position($tokens, $index, 1), 1);
                if (\is_array($other) && 'PHP_VERSION_ID' === ltrim($other[1], '\\')) {
                    continue;
                }

                $found[] = \sprintf('%s:%d compares with %s', $path, $token[2], $token[1]);
            }
        }

        $this->assertSame([], $found, 'Move each PHP-version comparison to a named gate on '.self::GATE_CLASS.'.');
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string|null $token
     */
    private static function isComparison(array|string|null $token): bool
    {
        if (\is_string($token)) {
            return '<' === $token || '>' === $token;
        }

        return \is_array($token) && \in_array($token[0], self::COMPARISONS, true);
    }

    /**
     * The nearest token that is not whitespace or a comment, before
     * ($direction -1) or after (1) $index.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function significant(array $tokens, int $index, int $direction): array|string|null
    {
        $position = self::position($tokens, $index, $direction);

        return $tokens[$position] ?? null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function position(array $tokens, int $index, int $direction): int
    {
        $position = $index + $direction;
        while (isset($tokens[$position]) && \is_array($tokens[$position]) && \in_array($tokens[$position][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
            $position += $direction;
        }

        return $position;
    }
}
