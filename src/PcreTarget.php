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

namespace RegexParser;

use RegexParser\Exception\InvalidRegexOptionException;

/**
 * The PHP version and the PCRE2 release a pattern is judged for.
 *
 * What PCRE2 accepts, and where it reports an error, moved across its
 * releases; a few rules belong to PHP itself. The engine that runs the
 * analysis need not be the one that will run the pattern, so the target
 * is named once and read everywhere a rule depends on it. Releases older
 * than 10.40 are judged with the 10.40 rules, newer ones than the library
 * knows with the newest rules it has.
 */
final readonly class PcreTarget
{
    public string $pcreVersion;

    private int $release;

    /**
     * @param int    $phpVersionId a PHP_VERSION_ID, 80400 for PHP 8.4
     * @param string $pcreVersion  a PCRE2 release, "10.44", as PCRE_VERSION
     *                             spells it or without its date
     *
     * @throws InvalidRegexOptionException when $pcreVersion names no release
     */
    public function __construct(public int $phpVersionId, string $pcreVersion)
    {
        [$major, $minor] = self::releaseParts($pcreVersion)
            ?? throw new InvalidRegexOptionException(\sprintf('"pcre_version" must be a PCRE2 release like "10.44", not "%s".', $pcreVersion));
        $this->release = (int) $major * 1000 + (int) $minor;
        $this->pcreVersion = (int) $major.'.'.$minor;
    }

    /**
     * The running PHP and the PCRE2 it links, which may not be the one its
     * version bundles: the PHP 8.4 packages of a distribution may link an
     * older one.
     */
    public static function runtime(): self
    {
        return new self(\PHP_VERSION_ID, \PCRE_VERSION);
    }

    /**
     * A PHP version with the PCRE2 its sources bundle: 10.40 for 8.2, 10.42
     * for 8.3, 10.44 for 8.4 and 8.5.
     */
    public static function bundledWith(int $phpVersionId): self
    {
        $release = match (true) {
            $phpVersionId >= 80400 => '10.44',
            $phpVersionId >= 80300 => '10.42',
            default => '10.40',
        };

        return new self($phpVersionId, $release);
    }

    /**
     * Whether the PCRE2 judged is at least $release, as "10.47".
     */
    public function pcreAtLeast(string $release): bool
    {
        return $this->release >= (self::releaseNumber($release) ?? \PHP_INT_MAX);
    }

    /**
     * Whether the running engine is the one judged: the same PHP minor
     * version, linking the same PCRE2 release. Only then can PHP itself be
     * asked about a pattern.
     */
    public function isRunningEngine(): bool
    {
        $running = self::runtime();

        return intdiv($this->phpVersionId, 100) === intdiv($running->phpVersionId, 100)
            && $this->release === $running->release;
    }

    /**
     * What a cached tree was read for: the PHP major and minor version, as
     * no rule depends on a patch release, and the PCRE2 release.
     */
    public function cacheKey(): string
    {
        return \sprintf(
            'php%d.%d/pcre%d.%d',
            intdiv($this->phpVersionId, 10000),
            intdiv($this->phpVersionId, 100) % 100,
            intdiv($this->release, 1000),
            $this->release % 1000,
        );
    }

    private static function releaseNumber(string $version): ?int
    {
        $parts = self::releaseParts($version);

        return null === $parts ? null : (int) $parts[0] * 1000 + (int) $parts[1];
    }

    /**
     * The major and minor digits of a release, read without the engine: a
     * backtrack limit set low on the host must not make it unreadable.
     *
     * @return array{string, string}|null
     */
    private static function releaseParts(string $version): ?array
    {
        $whitespace = " \t\n\v\f\r";
        $release = ltrim($version, $whitespace);
        $release = substr($release, 0, strcspn($release, $whitespace));

        // A pre-release build is spelled "10.45-RC1 2024-12-01".
        $hyphen = strpos($release, '-');
        $build = false === $hyphen ? null : substr($release, $hyphen + 1);
        $parts = explode('.', false === $hyphen ? $release : substr($release, 0, $hyphen));
        if (2 !== \count($parts) || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            return null;
        }
        if (null !== $build && !ctype_alnum($build)) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }
}
