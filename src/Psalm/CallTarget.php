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
use PHPRegex\Linter\Config\UnsupportedTargetException;
use PHPRegex\Linter\Extraction\GuardRegions;
use PHPRegex\Linter\Extraction\PhpParserGuards;
use PHPRegex\Linter\Extraction\TargetMarker;
use PHPRegex\Linter\Extraction\TargetMarkers;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use Psalm\Codebase;

/**
 * The versions a call is judged for: the PHP Psalm analyses for, with the
 * PCRE2 it bundles, or what the plugin's options name, narrowed by the
 * guards on PHP_VERSION_ID around the call and the "@regex-target" markers
 * of its line. A PHP below 7.3 is judged as regex lint judges it.
 *
 * @internal
 */
final class CallTarget
{
    private static ?string $phpVersion = null;

    private static ?string $pcreVersion = null;

    private static ?string $locale = null;

    /**
     * @var array<string, RegexParser> by target options
     */
    private static array $parsers = [];

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
     * The versions a marker narrows, by target.
     *
     * @var array<string, JudgedRange>
     */
    private static array $judgedRanges = [];

    /**
     * The plugin's <phpVersion>, <pcreVersion> and <locale>, null when not
     * given. A PHP below 7.3 is refused as regex lint refuses it: it ships
     * PCRE1.
     *
     * @throws InvalidRegexOptionException when one cannot be read
     * @throws UnsupportedTargetException  when <phpVersion> names a PHP that ships PCRE1
     */
    public static function configure(?string $phpVersion, ?string $pcreVersion, ?string $locale = null): void
    {
        self::check('phpVersion', 'php_version', $phpVersion, 'a version like "8.4", a PHP_VERSION_ID like 80400, or "runtime"');
        self::check('pcreVersion', 'pcre_version', $pcreVersion, 'a PCRE2 release like "10.44"');
        self::check('locale', 'locale', $locale, 'a locale this machine can set, like "tr_TR.UTF-8"');

        if (null !== $phpVersion && 'runtime' !== $phpVersion) {
            $named = ParserOptions::fromArray(['php_version' => $phpVersion])->target->phpVersionId;
            if ($named < PcreTarget::phpVersionBoundaries()[0]) {
                ProjectTarget::belowPcre2($named, '<phpVersion>', null, []);
            }
        }

        self::$phpVersion = $phpVersion;
        self::$pcreVersion = $pcreVersion;
        self::$locale = $locale;
    }

    /**
     * Why a "@regex-target" marker is reported: it cannot be read, or it
     * keeps no version of the series of Psalm's PHP. Null when it is not.
     */
    public static function markerError(TargetMarker $marker, Codebase $codebase): ?string
    {
        if (null === $marker->constraint) {
            return $marker->error;
        }

        $judged = self::judgedRange(self::parser($codebase));

        return [] === $judged->narrow($marker->constraint)
            ? \sprintf('The %s marker "%s" keeps no version the project is judged for (PHP %s): the patterns it covers are not judged.', $marker->marker, $marker->text, $judged->describe())
            : null;
    }

    /**
     * The parser judging patterns for the PHP Psalm analyses for, with the
     * PCRE2 it bundles, or for what the options name. A PHP below 7.3 is
     * judged as regex lint judges it: from the lowest PHP on PCRE2 of the
     * composer.json Psalm read it from, refused when Psalm was told it.
     *
     * @throws UnsupportedTargetException when the PHP Psalm analyses for ships PCRE1
     */
    public static function parser(Codebase $codebase): RegexParser
    {
        $phpVersion = self::$phpVersion ?? $codebase->analysis_php_version_id;
        if (\is_int($phpVersion) && $phpVersion < PcreTarget::phpVersionBoundaries()[0]) {
            $composer = 'composer' === $codebase->php_version_source ? $codebase->config->base_dir : null;
            $phpVersion = self::$belowPcre2[$phpVersion.'@'.($composer ?? '')] ??= ProjectTarget::belowPcre2($phpVersion, 'Psalm phpVersion', $composer, getenv())->target()->phpVersionId;
        }

        $options = ['php_version' => $phpVersion];
        if (null !== self::$pcreVersion) {
            $options['pcre_version'] = self::$pcreVersion;
        }
        if (null !== self::$locale) {
            $options['locale'] = self::$locale;
        }

        return self::$parsers[serialize($options)] ??= RegexParser::create($options);
    }

    /**
     * The parser judging a call the guards around it and the
     * "@regex-target" markers of its line cover: that of the lowest version
     * they keep, Psalm's when they keep it or none covers the call; null
     * when they keep no version.
     */
    public static function judgedBy(RegexParser $parser, Codebase $codebase, string $file, int $line, int $offset): ?RegexParser
    {
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
            return $parser;
        }

        $lowest = self::judgedRange($parser)->narrow($constraint)[0] ?? null;

        return null === $lowest ? null : self::judgedRange($parser)->parserFor($lowest);
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
     * The series of Psalm's PHP: the versions a marker narrows.
     */
    private static function judgedRange(RegexParser $parser): JudgedRange
    {
        $target = $parser->target();

        return self::$judgedRanges[self::key($parser)] ??= new JudgedRange([$target], [[$target->phpVersionId, (intdiv($target->phpVersionId, 100) + 1) * 100]], self::$pcreVersion, $parser);
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
            ParserOptions::fromArray([$option => $value]);
        } catch (InvalidRegexOptionException $e) {
            throw new InvalidRegexOptionException(\sprintf('The <%s> option of %s must be %s; got "%s".', $element, Plugin::class, $expected, $value), 0, $e);
        }
    }
}
