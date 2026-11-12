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

namespace PHPRegex\Psalm;

use PhpParser\Error;
use PhpParser\ParserFactory;
use PHPRegex\Linter\Config\JudgedRange;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Config\TargetSpec;
use PHPRegex\Linter\Exception\UnsupportedTargetException;
use PHPRegex\Linter\Extraction\GuardRegions;
use PHPRegex\Linter\Extraction\PhpParserGuards;
use PHPRegex\Linter\Extraction\TargetMarker;
use PHPRegex\Linter\Extraction\TargetMarkers;
use PHPRegex\Parser\Cache\ArrayCache;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use Psalm\Codebase;

/**
 * The versions a call is judged for: the PHP Psalm analyses for, or what
 * the plugin's options name, with the PCRE2 parser() gives it, narrowed by the
 * guards on PHP_VERSION_ID around the call and the "@regex-target" markers
 * of its line. A range <phpVersion> names is judged as regex lint judges
 * it: from its lowest PHP on PCRE2, at every later version a rule of the
 * library changes at that it allows. A PHP below 7.3 is judged as regex
 * lint judges it.
 *
 * @internal
 */
final class CallTarget
{
    private static ?string $phpVersion = null;

    /**
     * Whether <phpVersion> names a range, a Composer constraint, rather
     * than one version.
     */
    private static bool $range = false;

    private static ?string $pcreVersion = null;

    private static ?string $locale = null;

    /**
     * The parser of the floor, the parsers of every version judged when
     * nothing narrows a call, the floor first, and the versions a guard or
     * a marker narrows: by PHP or range, PCRE2 pinned and locale.
     *
     * @var array<string, array{RegexParser, non-empty-list<RegexParser>, JudgedRange}>
     */
    private static array $targets = [];

    /**
     * The PCRE2 release composer.json pins in config.platform.lib-pcre, by
     * Psalm's base directory.
     *
     * @var array<string, string|null>
     */
    private static array $composerPcre = [];

    /**
     * The PHP each PHP below 7.3 Psalm analyses for is judged for, by the
     * version and the directory of the composer.json Psalm read it from.
     *
     * @var array<string, int>
     */
    private static array $belowPcre2 = [];

    /**
     * The "@regex-target" markers and the guards of each file read, by
     * path, with the hash of the source they were read from.
     *
     * @var array<string, array{string, list<TargetMarker>, GuardRegions}>
     */
    private static array $markers = [];

    /**
     * The plugin's <phpVersion>, <pcreVersion> and <locale>, null when not
     * given. <phpVersion> names one version, or a range as a Composer
     * constraint, judged as given. A PHP below 7.3 is refused as regex lint
     * refuses it: it ships PCRE1; so is a range that allows no PHP of 7.3 or
     * later.
     *
     * @throws InvalidRegexOptionException when one cannot be read
     * @throws UnsupportedTargetException  when <phpVersion> names a PHP that ships PCRE1, or a range that allows
     *                                     no PHP on PCRE2
     */
    public static function configure(?string $phpVersion, ?string $pcreVersion, ?string $locale = null): void
    {
        self::check('phpVersion', 'php_version', $phpVersion, 'a version like "8.4", a PHP_VERSION_ID like 80400, "runtime", or a Composer constraint like ">=8.1" that names a lowest version');
        self::check('pcreVersion', 'pcre_version', $pcreVersion, 'a PCRE2 release like "10.44"');
        self::check('locale', 'locale', $locale, 'a locale this machine can set, like "tr_TR.UTF-8"');

        $range = null !== $phpVersion && null !== TargetSpec::intervals($phpVersion);
        if ($range) {
            // Refused now, as a single version on PCRE1 is, before Psalm reads any file.
            self::project($phpVersion, $pcreVersion, $locale);
        } elseif (null !== $phpVersion && 'runtime' !== $phpVersion) {
            $named = ParserOptions::phpVersionIdOf($phpVersion);
            if ($named < PcreTarget::phpVersionBoundaries()[0]) {
                ProjectTarget::belowPcre2($named, '<phpVersion>', null, []);
            }
        }

        self::$phpVersion = $phpVersion;
        self::$range = $range;
        self::$pcreVersion = $pcreVersion;
        self::$locale = $locale;
    }

    /**
     * Why a "@regex-target" marker is reported: it cannot be read, or it
     * keeps no version the patterns are judged for, the range <phpVersion>
     * names or the series of Psalm's PHP. Null when it is not.
     */
    public static function markerError(TargetMarker $marker, Codebase $codebase): ?string
    {
        if (null === $marker->constraint) {
            return $marker->error;
        }

        $judged = self::target($codebase)[2];

        return [] === $judged->narrow($marker->constraint)
            ? \sprintf('The %s marker "%s" keeps no version the project is judged for (PHP %s): the patterns it covers are not judged.', $marker->marker, $marker->text, $judged->describe())
            : null;
    }

    /**
     * The parser judging patterns for the PHP Psalm analyses for, or the
     * one <phpVersion> names, the lowest on PCRE2 of the range it names,
     * with the PCRE2 <pcreVersion> names, else the
     * one the composer.json of Psalm's base directory pins in
     * config.platform.lib-pcre, used as is; else the running PCRE2 when the
     * PHP is the running PHP's minor version; else the one it bundles. A PHP
     * below 7.3 is judged as regex lint judges it: from the lowest PHP on
     * PCRE2 of the composer.json Psalm read it from, refused when Psalm was
     * told it.
     *
     * @throws UnsupportedTargetException  when the PHP Psalm analyses for ships PCRE1
     * @throws InvalidRegexOptionException when composer.json's lib-pcre names no PCRE2 release
     */
    public static function parser(Codebase $codebase): RegexParser
    {
        return self::target($codebase)[0];
    }

    /**
     * The parsers judging a call the guards around it and the
     * "@regex-target" markers of its line cover: those of the versions they
     * keep, the lowest first; every version judged when none covers the
     * call, the floor first; none when they keep no version.
     *
     * @return list<RegexParser>
     */
    public static function judgedBy(Codebase $codebase, string $file, int $line, int $offset): array
    {
        [, $points, $judged] = self::target($codebase);
        // Read again when the file changed, as under the language server.
        $content = $codebase->getFileContents($file);
        $hash = hash('xxh128', $content);
        if ($hash !== (self::$markers[$file][0] ?? null)) {
            self::$markers[$file] = [$hash, TargetMarkers::read($content), self::guards($content)];
        }
        [, $markers, $guards] = self::$markers[$file];
        $constraint = TargetMarker::constraintAt($markers, $line);
        $guard = $guards->at($offset);
        $constraint = null === $guard ? $constraint : ($constraint?->intersect($guard) ?? $guard);
        if (null === $constraint) {
            return $points;
        }

        return array_map($judged->parserFor(...), $judged->narrow($constraint));
    }

    /**
     * The PHP release, the PCRE2 and the locale judged: a rule may depend on
     * a patch release, and a verdict on the tables of the locale.
     */
    public static function key(RegexParser $parser): string
    {
        return $parser->target()->key();
    }

    /**
     * The guards on PHP_VERSION_ID of a file, read from its tree as `regex
     * lint` reads them. Psalm's own narrowing of PHP_VERSION_ID is not
     * read: it narrows the constant under function_exists() too, which a
     * polyfill makes true on any version. A file PHP cannot parse holds
     * none: the call is judged for the whole range.
     */
    private static function guards(string $content): GuardRegions
    {
        if (!str_contains($content, 'PHP_VERSION')) {
            return new GuardRegions();
        }

        try {
            $ast = (new ParserFactory())->createForHostVersion()->parse($content);
        } catch (Error|\RangeException) {
            // A RangeException is the parser meeting a token of a PHP newer
            // than itself, as for the lint.
            return new GuardRegions();
        }

        return PhpParserGuards::regions($ast ?? [], $content);
    }

    /**
     * The parser of the floor, the parsers of every version judged, the
     * floor first, and the versions a guard or a marker narrows: the range
     * <phpVersion> names, else the series of the one version judged.
     *
     * @throws UnsupportedTargetException  when the PHP Psalm analyses for ships PCRE1
     * @throws InvalidRegexOptionException when composer.json's lib-pcre names no PCRE2 release
     *
     * @return array{RegexParser, non-empty-list<RegexParser>, JudgedRange}
     */
    private static function target(Codebase $codebase): array
    {
        $phpVersion = self::$phpVersion ?? $codebase->analysis_php_version_id;
        if (\is_int($phpVersion) && $phpVersion < PcreTarget::phpVersionBoundaries()[0]) {
            $composer = 'composer' === $codebase->php_version_source ? $codebase->config->base_dir : null;
            $phpVersion = self::$belowPcre2[$phpVersion.'@'.($composer ?? '')] ??= ProjectTarget::belowPcre2($phpVersion, 'Psalm phpVersion', $composer, getenv())->target()->phpVersionId;
        }

        $baseDir = $codebase->config->base_dir;
        if (!\array_key_exists($baseDir, self::$composerPcre)) {
            self::$composerPcre[$baseDir] = ProjectTarget::composerPcre($baseDir, getenv());
        }
        $pinned = self::$pcreVersion ?? self::$composerPcre[$baseDir];
        $key = serialize([$phpVersion, self::$range, $pinned, self::$locale]);
        if (isset(self::$targets[$key])) {
            return self::$targets[$key];
        }

        if (self::$range && \is_string($phpVersion)) {
            $project = self::project($phpVersion, $pinned, self::$locale);
            $floor = self::parserFor($project->target());
            $judged = $project->judgedRange($floor);

            return self::$targets[$key] = [$floor, [$floor, ...$project->rangeParsers($floor)], $judged];
        }

        $target = ParserOptions::resolveTarget(ParserOptions::phpVersionIdOf($phpVersion), $pinned, self::$locale, true);
        $floor = self::parserFor($target);
        // The series of the version: what a marker narrows.
        $judged = new JudgedRange([$target], [[$target->phpVersionId, (intdiv($target->phpVersionId, 100) + 1) * 100]], $pinned, $floor);

        return self::$targets[$key] = [$floor, [$floor], $judged];
    }

    /**
     * The range <phpVersion> names, judged as regex lint judges a range:
     * from its lowest PHP on PCRE2, at every later version a rule of the
     * library changes at that it allows.
     *
     * @throws InvalidRegexOptionException when the range names no lowest version
     * @throws UnsupportedTargetException  when it allows no PHP on PCRE2
     */
    private static function project(string $range, ?string $pinned, ?string $locale): ProjectTarget
    {
        return ProjectTarget::fromSources(['<phpVersion>' => $range], ['<pcreVersion>' => $pinned], null, [], ['<locale>' => $locale]);
    }

    private static function parserFor(PcreTarget $target): RegexParser
    {
        return RegexParser::fromOptions(new ParserOptions(ParserOptions::DEFAULT_MAX_PATTERN_LENGTH, ParserOptions::DEFAULT_MAX_LOOKBEHIND_LENGTH, new ArrayCache(), target: $target));
    }

    /**
     * @throws InvalidRegexOptionException when the library cannot read the value
     */
    private static function check(string $element, string $option, ?string $value, string $expected): void
    {
        if (null === $value) {
            return;
        }

        try {
            // A PHP on PCRE1 is refused after, with its own reason.
            'php_version' === $option ? TargetSpec::intervals($value) ?? ParserOptions::phpVersionIdOf($value) : ParserOptions::fromArray([$option => $value]);
        } catch (InvalidRegexOptionException $e) {
            throw new InvalidRegexOptionException(\sprintf('The <%s> option of %s must be %s; got "%s".', $element, Plugin::class, $expected, $value), 0, $e);
        }
    }
}
