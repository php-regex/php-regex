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

use PHPRegex\Parser\Internal\PhpVersionGates;
use PHPRegex\Tests\Support\LibrarySource;
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
 */
final class PhpVersionGatesTest extends TestCase
{
    private const GATE_CLASS = 'src/Parser/Internal/PhpVersionGates.php';

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
            if (\is_int($version) && $version >= PhpVersionGates::FLOOR && !\in_array($version, PhpVersionGates::POINTS, true)) {
                $missing[] = $constant->getName().' = '.$version;
            }
        }

        $this->assertSame([], $missing, 'Add each gate to PhpVersionGates::POINTS, or the matrix and the lint range do not judge it.');
        $this->assertContains(PhpVersionGates::NO_AUTO_CAPTURE_MODIFIER, PhpVersionGates::POINTS);
        $this->assertNotContains(PhpVersionGates::EVAL_MODIFIER_REMOVED, PhpVersionGates::POINTS);
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
