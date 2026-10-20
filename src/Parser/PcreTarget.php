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

namespace PHPRegex\Parser;

use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Text\Ascii;

/**
 * The PHP version, the PCRE2 release and the LC_CTYPE locale a pattern is
 * judged for.
 *
 * What PCRE2 accepts, and where it reports an error, moved across its
 * releases; a few rules belong to PHP itself. The locale names the tables
 * PHP hands PCRE2, which decide the other case of each byte and the bytes
 * of each type without UCP: PCRE2's own under "C", PHP's default, those the
 * C library gives under any other locale setlocale() names. The engine that runs the
 * analysis need not be the one that will run the pattern, so the target
 * is named once and read everywhere a rule depends on it. Releases older
 * than 10.39 are judged with the 10.39 rules, but for the few older changes
 * the library names (judgedWith()); newer ones than the library knows with
 * the newest rules it has.
 */
final class PcreTarget
{
    /**
     * The oldest release whose rules the library knows in full, 10.39.
     */
    private const KNOWN_IN_FULL = 10_039;

    public readonly string $pcreVersion;

    /**
     * The LC_CTYPE locale the patterns run under, as the C library names
     * it: "C" for PCRE2's own tables.
     */
    public readonly string $locale;

    /**
     * What supports() answered, by release number and feature name: the
     * lexer and the parser ask it many times per pattern, and the answer
     * depends on nothing else. As many entries as releases asked about times
     * features.
     *
     * @var array<int, array<string, bool>>
     */
    private static array $supported = [];

    private readonly int $release;

    /**
     * @param int         $phpVersionId a PHP_VERSION_ID, 80400 for PHP 8.4
     * @param string      $pcreVersion  a PCRE2 release, "10.44", as PCRE_VERSION
     *                                  spells it or without its date
     * @param string|null $locale       an LC_CTYPE locale, "tr_TR.UTF-8"; the
     *                                  runtime's when null
     *
     * @throws InvalidRegexOptionException when $pcreVersion names no release, or
     *                                     $locale one this machine cannot set
     */
    public function __construct(
        public readonly int $phpVersionId,
        string $pcreVersion,
        ?string $locale = null
    ) {
        [$major, $minor] = self::releaseParts($pcreVersion)
            ?? throw new InvalidRegexOptionException(\sprintf('"pcre_version" must be a PCRE2 release like "10.44", not "%s".', $pcreVersion));
        $this->release = (int) $major * 1000 + (int) $minor;
        $this->pcreVersion = (int) $major.'.'.$minor;
        $this->locale = null === $locale ? CharacterTables::current() : CharacterTables::forLocale($locale)->locale;
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
     * The PHP versions, as PHP_VERSION_IDs in ascending order, at which a
     * verdict of the library may change: the lowest PHP it supports, then
     * each version that bundles a newer PCRE2 or where a rule changes (8.5
     * refuses "\K" in a lookaround, and stops refusing "\C" under "u" until
     * 8.5.10). Judging a range of PHP versions at these points judges all of
     * it. The list may grow in a minor release, when the library learns a
     * PHP release or a rule.
     *
     * @return list<int>
     */
    public static function phpVersionBoundaries(): array
    {
        return PhpVersionGates::POINTS;
    }

    /**
     * A PHP version with the PCRE2 its sources bundle: 10.32 for 7.3, 10.33
     * for 7.4, 10.35 for 8.0, 10.39 for 8.1, 10.40 for 8.2, 10.42 for 8.3,
     * 10.44 for 8.4 through 8.6. A series is given the release of its x.y.0,
     * but for 8.1: 8.1.0 bundled 10.37, and 8.1.1 onwards 10.39. PHP 7.4.6
     * moved to 10.34 and 7.4.12 to 10.35, judged as 10.33. PHP 7.2 and
     * earlier link PCRE1, which no PCRE2 release stands for: they get 10.32.
     */
    public static function bundledWith(int $phpVersionId): self
    {
        $release = match (true) {
            $phpVersionId >= PhpVersionGates::BUNDLES_PCRE2_10_44 => '10.44',
            $phpVersionId >= PhpVersionGates::BUNDLES_PCRE2_10_42 => '10.42',
            $phpVersionId >= PhpVersionGates::BUNDLES_PCRE2_10_40 => '10.40',
            $phpVersionId >= PhpVersionGates::BUNDLES_PCRE2_10_39 => '10.39',
            $phpVersionId >= PhpVersionGates::BUNDLES_PCRE2_10_35 => '10.35',
            $phpVersionId >= PhpVersionGates::BUNDLES_PCRE2_10_33 => '10.33',
            default => '10.32',
        };

        return new self($phpVersionId, $release);
    }

    /**
     * The same PHP and PCRE2 under another locale, the runtime's for null.
     *
     * @throws InvalidRegexOptionException when $locale names one this machine
     *                                     cannot set
     */
    public function withLocale(?string $locale): self
    {
        return new self($this->phpVersionId, $this->pcreVersion, $locale);
    }

    /**
     * The PCRE2 release whose rules judge this one: its own, or 10.39 for
     * an older release. The library knows the rules of 10.39 and later in
     * full; of an older release, only the few changes it names.
     */
    public function judgedWith(): string
    {
        return $this->release < self::KNOWN_IN_FULL ? '10.39' : $this->pcreVersion;
    }

    /**
     * Whether the PCRE2 judged is at least $release, as "10.47".
     */
    public function pcreAtLeast(string $release): bool
    {
        // "10.4" would read as 10.04 and hold for every release: a minor is
        // written with two digits at least, as PCRE2 writes it.
        [, $minor] = explode('.', $release, 2) + [1 => ''];
        $number = \strlen($minor) >= 2 ? self::releaseNumber($release) : null;
        if (null === $number) {
            throw new InvalidRegexOptionException(\sprintf('"%s" is not a PCRE2 release like "10.45".', $release));
        }

        return $this->release >= $number;
    }

    /**
     * Whether the PCRE2 judged has a behaviour: it arrived in that release
     * or an earlier one.
     */
    public function supports(PcreFeature $feature): bool
    {
        if (isset(self::$supported[$this->release][$feature->name])) {
            return self::$supported[$this->release][$feature->name];
        }

        StaticCaches::register(self::class, static function (): void {
            self::$supported = [];
        });

        return self::$supported[$this->release][$feature->name] = $this->pcreAtLeast($feature->release());
    }

    /**
     * Where PCRE2 reports an error it reports past the character at fault
     * from 10.47, given that later offset: $shift bytes earlier, on the
     * character itself, before 10.47, which every PHP release bundles. A
     * character of several bytes in UTF mode moves back whole.
     *
     * @internal
     */
    public function faultOffset(int $offset, int $shift = 1): int
    {
        return $this->supports(PcreFeature::ErrorOffsetPastTheFault) ? $offset : $offset - $shift;
    }

    /**
     * Whether the running engine is the one judged: the same PHP minor
     * version, linking the same PCRE2 release, under the same locale. Only
     * then can PHP itself be asked about a pattern.
     */
    public function isRunningEngine(): bool
    {
        $running = self::runtime();

        return intdiv($this->phpVersionId, 100) === intdiv($running->phpVersionId, 100)
            && $this->release === $running->release
            && $this->locale === $running->locale;
    }

    /**
     * What a cached tree was read for: the PHP major and minor version, as
     * no parse rule depends on a patch release, the PCRE2 release, and the
     * locale but "C", whose tables may judge a pattern otherwise. The rules
     * that depend on a patch release, such as "\C" under "u" from PHP
     * 8.4.25, belong to the validator, which runs on every call, cached
     * tree or not.
     */
    public function cacheKey(): string
    {
        return \sprintf(
            'php%d.%d/pcre%d.%d%s',
            intdiv($this->phpVersionId, 10000),
            intdiv($this->phpVersionId, 100) % 100,
            intdiv($this->release, 1000),
            $this->release % 1000,
            CharacterTables::BUILT_IN === $this->locale ? '' : '/'.$this->locale,
        );
    }

    /**
     * What tells this target from another: the PHP_VERSION_ID, the PCRE2
     * release and the locale, as "80426/10.44/C".
     */
    public function key(): string
    {
        return $this->phpVersionId.'/'.$this->pcreVersion.'/'.$this->locale;
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
        if (2 !== \count($parts) || !Ascii::isDigit($parts[0]) || !Ascii::isDigit($parts[1])) {
            return null;
        }
        if (null !== $build && !Ascii::isAlnum($build)) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }
}
