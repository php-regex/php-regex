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

namespace PHPRegex\Linter\Config;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\PcreTarget;

/**
 * The PHP and PCRE2 versions a piece of code runs on, as a guard on
 * PHP_VERSION_ID or a "@regex-target" marker says them: each a union of
 * intervals, the lowest version admitted and the first above it no longer
 * admitted (null when none is), null for every version.
 *
 * A PHP version is a PHP_VERSION_ID; a PCRE2 release "10.43" is written
 * 104300, as "8.4" is 80400.
 *
 * @internal
 */
final class TargetConstraint
{
    /**
     * @param list<array{int, int|null}>|null $php  the PHP versions admitted, every one when null
     * @param list<array{int, int|null}>|null $pcre the PCRE2 releases admitted, every one when null
     */
    public function __construct(public readonly ?array $php, public readonly ?array $pcre = null) {}

    /**
     * The PHP versions from $from, below $below.
     */
    public static function phpFrom(int $from, ?int $below): self
    {
        return new self([[$from, $below]]);
    }

    /**
     * What a "@regex-target" marker holds after its name: "php" then a
     * Composer constraint, "pcre" then one, or both in that order, each
     * read by the interval parser of composer.json's require.php, a branch
     * without a lower bound starting at the first version. Null when the
     * text is no such constraint.
     */
    public static function fromMarker(string $text): ?self
    {
        if (1 !== LibraryPcre::match('/^\s*(?:php\s*(?<php>(?:(?!pcre).)+?))?\s*(?:pcre\s*(?<pcre>.+?))?\s*$/s', $text, $parts)) {
            return null;
        }

        $php = '' === ($parts['php'] ?? '') ? null : ComposerConstraint::intervals($parts['php'], true);
        $pcre = '' === ($parts['pcre'] ?? '') ? null : ComposerConstraint::intervals($parts['pcre'], true);
        if ((null === $php && '' !== ($parts['php'] ?? '')) || (null === $pcre && '' !== ($parts['pcre'] ?? '')) || (null === $php && null === $pcre)) {
            return null;
        }

        return new self($php, $pcre);
    }

    /**
     * The versions both admit.
     */
    public function intersect(self $other): self
    {
        return new self(self::intersectIntervals($this->php, $other->php), self::intersectIntervals($this->pcre, $other->pcre));
    }

    /**
     * The versions either admits. A PCRE2 constraint holds only when both
     * name one: a guard on PHP_VERSION_ID names none.
     */
    public function union(self $other): self
    {
        $php = null === $this->php || null === $other->php ? null : self::merge([...$this->php, ...$other->php]);
        $pcre = null === $this->pcre || null === $other->pcre ? null : self::merge([...$this->pcre, ...$other->pcre]);

        return new self($php, $pcre);
    }

    /**
     * Whether the target is one of the versions admitted.
     */
    public function admits(PcreTarget $target): bool
    {
        return self::holds($this->php, $target->phpVersionId) && self::holds($this->pcre, self::releaseId($target->pcreVersion));
    }

    /**
     * Whether the PHP version is one of those admitted.
     */
    public function admitsPhp(int $phpVersionId): bool
    {
        return self::holds($this->php, $phpVersionId);
    }

    /**
     * The PHP versions admitted, intersected with $intervals.
     *
     * @param list<array{int, int|null}> $intervals
     *
     * @return list<array{int, int|null}>
     */
    public function phpWithin(array $intervals): array
    {
        return self::intersectIntervals($this->php, $intervals) ?? $intervals;
    }

    /**
     * The PHP versions admitted as a Composer constraint (">=8.4",
     * ">=8.2 <8.4", "<8.3 || >=8.5"), null when every one is.
     */
    public function phpConstraint(): ?string
    {
        return null === $this->php ? null : self::describe($this->php);
    }

    /**
     * The PCRE2 releases admitted as a Composer constraint (">=10.43"),
     * null when every one is.
     */
    public function pcreConstraint(): ?string
    {
        return null === $this->pcre ? null : self::describe($this->pcre);
    }

    /**
     * A PCRE2 release as the intervals write it: "10.43" is 104300.
     */
    public static function releaseId(string $release): int
    {
        [$major, $minor] = explode('.', $release.'.0');

        return (int) $major * 10000 + (int) $minor * 100;
    }

    /**
     * Intervals as a Composer constraint: each branch ">=from <below", the
     * bound left out where it is the first version or none.
     *
     * @param list<array{int, int|null}> $intervals
     */
    public static function describe(array $intervals): string
    {
        $branches = [];
        foreach ($intervals as [$from, $below]) {
            $bounds = [];
            if ($from > 0) {
                $bounds[] = '>='.ProjectTarget::phpLabel($from);
            }
            if (null !== $below) {
                $bounds[] = '<'.ProjectTarget::phpLabel($below);
            }
            $branches[] = [] === $bounds ? '*' : implode(' ', $bounds);
        }

        return [] === $branches ? '<0' : implode(' || ', $branches);
    }

    /**
     * Intervals that overlap or touch made one, sorted by lowest version.
     *
     * @param list<array{int, int|null}> $intervals
     *
     * @return list<array{int, int|null}>
     */
    private static function merge(array $intervals): array
    {
        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as [$from, $below]) {
            $last = \count($merged) - 1;
            if ($last >= 0 && (null === $merged[$last][1] || $from <= $merged[$last][1])) {
                $merged[$last][1] = null === $merged[$last][1] || null === $below ? null : max($merged[$last][1], $below);

                continue;
            }
            $merged[] = [$from, $below];
        }

        return $merged;
    }

    /**
     * @param list<array{int, int|null}>|null $intervals
     */
    private static function holds(?array $intervals, int $version): bool
    {
        if (null === $intervals) {
            return true;
        }

        foreach ($intervals as [$from, $below]) {
            if ($version >= $from && (null === $below || $version < $below)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The versions in both unions, as a union sorted by lowest version.
     *
     * @param list<array{int, int|null}>|null $left
     * @param list<array{int, int|null}>|null $right
     *
     * @return list<array{int, int|null}>|null
     */
    private static function intersectIntervals(?array $left, ?array $right): ?array
    {
        if (null === $left || null === $right) {
            return $left ?? $right;
        }

        $both = [];
        foreach ($left as [$leftFrom, $leftBelow]) {
            foreach ($right as [$rightFrom, $rightBelow]) {
                $from = max($leftFrom, $rightFrom);
                $below = null === $leftBelow ? $rightBelow : (null === $rightBelow ? $leftBelow : min($leftBelow, $rightBelow));
                if (null === $below || $from < $below) {
                    $both[] = [$from, $below];
                }
            }
        }
        usort($both, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $both;
    }
}
