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

namespace PHPRegex\Tests\TestUtils;

use PHPRegex\Linter\Internal\SilentMeaningChange;
use PHPRegex\Parser\Internal\PhpVersionGates;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Integration\Bridge\Rector\PossessiveRepeatOnceRector\PossessiveRepeatOnceWitnessTest;
use PHPRegex\Tests\Support\ReplaySubjects;
use PHPRegex\Tests\Unit\NodeVisitor\ErrorOffsetReleaseTest;
use PHPRegex\Tests\Unit\NodeVisitor\PaddedBraceVersionTest;
use PHPRegex\Tests\Unit\NodeVisitor\PcreVersionGatingTest;
use PHPRegex\Tests\Unit\Parser\BadEscapeIsLiteralTest;
use PHPRegex\Tests\Unit\Parser\BeforePcre2Ten39Test;
use PHPRegex\Tests\Unit\Parser\GroupNameLengthReleaseTest;
use PHPRegex\Tests\Unit\Parser\NulByteBeforePhp82Test;

/**
 * What the library claims the engines of PHP 7.3 to 8.6 do, written down for
 * tests/Tools/verify_engine_claims.php, which runs on those engines where the
 * library cannot: PHP 7.3 to 8.0 cannot run it at all.
 *
 * Three kinds of claim, each for the PCRE2 releases the official php:X-cli
 * images run, and for the PHP versions where the claim depends on them:
 *  - compile: the verdict the validator gives the pattern, judged for that
 *    PHP and that release: it compiles, or it is refused at an offset;
 *  - match: what a pattern whose meaning changed finds in a subject, before
 *    and from the release the change arrived in, as the lint reports it;
 *    and what a pattern PHP 7.3 and 7.4 read through
 *    PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL finds, where the validator accepts
 *    it, false where it refuses it;
 *  - same: a rewrite a Rector rule makes for a PHP finds what the call it
 *    replaces finds, on the engine of that PHP.
 *
 * The patterns are the ones the tests already pin release by release.
 */
final class EngineClaims
{
    public const FIXTURE = __DIR__.'/../Fixtures/EngineClaims/engine-claims.json';

    /**
     * The PCRE2 the official images run, keyed by the image: 7.4 and 8.0
     * both run 10.35, as PHP 7.4.12 moved to it, so no image runs the 10.33
     * the library judges PHP 7.4 with; 8.1 runs 10.39, 8.4 to 8.6 run 10.44.
     */
    public const IMAGES = [
        '7.3' => '10.32',
        '7.4' => '10.35',
        '8.0' => '10.35',
        '8.1' => '10.39',
        '8.2' => '10.40',
        '8.3' => '10.42',
        '8.4' => '10.44',
        '8.5' => '10.44',
        '8.6-rc' => '10.44',
    ];

    /**
     * The oldest release whose rules the library knows in full. Below it, a
     * release is judged with the rules of 10.39 but for the changes the
     * library names (PcreTarget::judgedWith()): there the fixture claims only
     * what the tests of those changes pin.
     */
    private const KNOWN_IN_FULL = '10.39';

    /**
     * The test providers whose rows pin a verdict release by release, and
     * the oldest release each one makes a claim for: the rows of the older
     * changes, and the NUL bytes PHP itself refuses, from the oldest; the
     * rest from the release the library knows in full.
     */
    private const PROVIDERS = [
        [BeforePcre2Ten39Test::class, ['provideRefusals', 'provideRefusalsWhereReadingStopped', 'provideAcceptedEverywhere', 'provideClassicSpellings', 'provideFullRefusals', 'provideEscapesPhp7ReadsAsText'], '10.32'],
        [BadEscapeIsLiteralTest::class, ['provideBadEscapes', 'provideClassEscapesOnlyPcre2Ten32Reads', 'provideRefusalsTheOptionKeeps', 'provideEscapesTheOptionLeavesAlone'], '10.32'],
        [NulByteBeforePhp82Test::class, ['providePatternsHoldingANul'], '10.32'],
        [PcreVersionGatingTest::class, ['provideVariableLengthLookbehinds', 'provideFixedLengthLookbehinds'], self::KNOWN_IN_FULL],
        [PaddedBraceVersionTest::class, ['providePaddedEscapes'], self::KNOWN_IN_FULL],
        [GroupNameLengthReleaseTest::class, ['provideLongNames', 'provideReferencesToLongNames'], self::KNOWN_IN_FULL],
        [ErrorOffsetReleaseTest::class, ['provideErrors'], self::KNOWN_IN_FULL],
    ];

    /**
     * The changes between the oldest and the newest release above that no
     * provider row spells.
     */
    private const FEATURE_WITNESSES = [
        'BadEscapeInClassStaysRefused' => ['/[\B]/', '/[a-\d]/'],
        'LoosePropertyNames' => ['/\p{greek}/u', '/\p{sc:Greek}/u', '/\p{Katakana}/u'],
        'CaselessRestrictModifier' => ['/(?r)k/i', '/k/ir'],
        'AsciiOptions' => ['/(?aD)\d/', '/(?a)\w/u'],
    ];

    /**
     * The witnesses of the changes of meaning the lint reports: each pattern
     * with its subject, the group read, and what that group holds before the
     * release of the change and from it (null for no match).
     */
    private const MEANING_CHANGES = [
        'PossessiveRepeatOnceIsAtomic' => ['pattern' => '/(a|ab){1}+c/', 'subject' => 'abc', 'group' => 0, 'before' => 'abc', 'from' => null],
        'GroupHoldingItsOwnReferenceBacktracks' => ['pattern' => '/^(a|ab|\1x)+b$/', 'subject' => 'abb', 'group' => 0, 'before' => null, 'from' => 'abb'],
        'RepeatedAssertionKeepsItsCount' => ['pattern' => '/^(?=(\1?a)){2}/', 'subject' => 'aa', 'group' => 1, 'before' => 'a', 'from' => 'aa'],
        'UcpFoldsPastAsciiWithoutUtf' => ['pattern' => '/(*UCP)\xC9/i', 'subject' => "\xE9", 'group' => 0, 'before' => null, 'from' => "\xE9"],
        'LoosePropertyNames' => ['pattern' => '/\p{Katakana}/u', 'subject' => "\u{30FB}", 'group' => 0, 'before' => null, 'from' => "\u{30FB}"],
    ];

    /**
     * Bad escapes with the "X" modifier, which makes PHP 7.3 and 7.4 refuse
     * them as PHP 8.0 does.
     */
    private const BAD_ESCAPES_UNDER_X = ['/\y/X', '/[\y]/X', '/\p{Bogus}/X', '/\x{zz}/X', '/\214748364/X'];

    private const RECTOR_TESTS = __DIR__.'/../Integration/Bridge/Rector';

    private const SPLIT = "\n-----\n";

    /**
     * @return list<string>
     */
    public static function releases(): array
    {
        $releases = array_values(array_unique(self::IMAGES));
        sort($releases, \SORT_NATURAL);

        return $releases;
    }

    /**
     * The claims, in a stable order: compile, then match, then same.
     *
     * @return list<array<string, mixed>>
     */
    public static function build(): array
    {
        return [...self::compileClaims(), ...self::matchClaims(), ...self::sameClaims()];
    }

    /**
     * The fixture: one claim a line, so that a change shows as the lines of
     * the claims it moves.
     *
     * @param list<array<string, mixed>> $claims
     */
    public static function encode(array $claims): string
    {
        $lines = [];
        foreach ($claims as $claim) {
            $lines[] = '        '.json_encode(self::encodeStrings($claim), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        }

        return "{\n"
            .'    "about": '.json_encode('What the library claims the engines of the official php:X-cli images do. Written by tests/Tools/write_engine_claims.php, checked on each engine by tests/Tools/verify_engine_claims.php.', \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR).",\n"
            .'    "releases": '.json_encode(self::releases(), \JSON_THROW_ON_ERROR).",\n"
            ."    \"claims\": [\n".implode(",\n", $lines)."\n    ]\n"
            ."}\n";
    }

    /**
     * The verdict the validator gives the pattern for a PHP version and a
     * PCRE2 release: true when it compiles, else the offset of the refusal,
     * or false when the refusal names none.
     */
    public static function verdict(string $pattern, int $phpVersionId, string $release): bool|int
    {
        static $parsers = [];
        $parser = $parsers[$phpVersionId.'/'.$release] ??= RegexParser::create(['php_version' => $phpVersionId, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
        $validation = $parser->validate($pattern);
        if ($validation->isValid) {
            return true;
        }

        return $validation->offset ?? false;
    }

    /**
     * Whether the lint reports the witness's change of meaning, at the
     * release the change arrived in, with the witness in its message.
     *
     * @param array{pattern: string, subject: string, group: int, before: string|null, from: string|null} $witness
     */
    public static function lintReports(PcreFeature $feature, array $witness): bool
    {
        [$major, $minor] = array_map(intval(...), explode('.', $feature->release()));
        $before = new PcreTarget(PhpVersionGates::FLOOR, $major.'.'.($minor - 1));
        $from = new PcreTarget(PhpVersionGates::FLOOR, $feature->release());
        $ast = RegexParser::create(['php_version' => PhpVersionGates::FLOOR, 'pcre_version' => $before->pcreVersion, 'runtime_pcre_validation' => false])->parse($witness['pattern']);
        $change = SilentMeaningChange::first($ast, $before, [$from]);
        if (null === $change || $change->target->pcreVersion !== $feature->release()) {
            return false;
        }

        // The Katakana witness is named by its code point.
        return str_contains($change->message, $witness['pattern']) || str_contains($change->message, 'U+30FB for \p{Katakana}');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function compileClaims(): array
    {
        $sources = [];
        $floors = [];
        foreach (self::PROVIDERS as [$class, $providers, $floor]) {
            foreach ($providers as $provider) {
                /** @var iterable<string, array<string, mixed>> $rows */
                $rows = $class::$provider();
                foreach ($rows as $name => $row) {
                    $pattern = $row['pattern'] ?? null;
                    if (!\is_string($pattern)) {
                        throw new \LogicException(\sprintf('%s::%s() "%s" holds no pattern.', $class, $provider, $name));
                    }
                    $sources[$pattern] ??= substr((string) strrchr($class, '\\'), 1).': '.$name;
                    $floors[$pattern] = min($floors[$pattern] ?? $floor, $floor);
                }
            }
        }

        foreach (self::FEATURE_WITNESSES as $feature => $patterns) {
            foreach ($patterns as $pattern) {
                $sources[$pattern] ??= 'PcreFeature::'.$feature;
                $floors[$pattern] ??= version_compare(\constant(PcreFeature::class.'::'.$feature)->release(), self::KNOWN_IN_FULL, '<') ? self::releases()[0] : self::KNOWN_IN_FULL;
            }
        }

        foreach (self::BAD_ESCAPES_UNDER_X as $pattern) {
            $sources[$pattern] ??= 'The "X" modifier';
            $floors[$pattern] ??= self::releases()[0];
        }

        foreach (self::rewrites() as $rewrite) {
            foreach ([$rewrite['pattern'], $rewrite['same_as']] as $pattern) {
                $sources[$pattern] ??= $rewrite['source'];
                $floors[$pattern] = min($floors[$pattern] ?? $rewrite['releases'][0], $rewrite['releases'][0]);
            }
        }

        $claims = [];
        foreach ($sources as $pattern => $source) {
            $pattern = (string) $pattern;
            $compile = [];
            foreach (self::releases() as $release) {
                if (version_compare($release, $floors[$pattern] ?? self::KNOWN_IN_FULL, '<')) {
                    continue;
                }

                $byPhp = [];
                $last = null;
                foreach (PcreTarget::phpVersionBoundaries() as $phpVersionId) {
                    $verdict = self::verdict($pattern, $phpVersionId, $release);
                    if ([] === $byPhp || $verdict !== $last) {
                        $byPhp[(string) $phpVersionId] = $verdict;
                        $last = $verdict;
                    }
                }
                $compile[$release] = 1 === \count($byPhp) ? $last : ['php' => $byPhp];
            }

            $claims[] = ['source' => $source, 'pattern' => $pattern, 'compile' => $compile];
        }

        return $claims;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function matchClaims(): array
    {
        $claims = [];
        foreach (self::MEANING_CHANGES as $name => $witness) {
            $feature = \constant(PcreFeature::class.'::'.$name);
            \assert($feature instanceof PcreFeature);
            if (!self::lintReports($feature, $witness)) {
                throw new \LogicException(\sprintf('The lint no longer reports %s as the change of PcreFeature::%s.', $witness['pattern'], $name));
            }

            $match = [];
            foreach (self::releases() as $release) {
                $match[$release] = version_compare($release, $feature->release(), '<') ? $witness['before'] : $witness['from'];
            }
            $claims[] = ['source' => 'PcreFeature::'.$name, 'pattern' => $witness['pattern'], 'subject' => $witness['subject'], 'group' => $witness['group'], 'match' => $match];
        }

        // What a bad escape PHP 7.3 and 7.4 read as its character finds, on
        // each target the validator accepts it for through that reading, and
        // false where it refuses it. A release on which a later PHP accepts
        // it, as "\214748364" from 10.37, means something else there and
        // makes no claim. An escape the option leaves alone finds the same
        // on every target.
        $witnesses = [];
        foreach ([...BadEscapeIsLiteralTest::provideBadEscapes(), ...BadEscapeIsLiteralTest::provideClassEscapesOnlyPcre2Ten32Reads()] as $name => $witness) {
            $witnesses[] = [$name, $witness, true];
        }
        foreach (BadEscapeIsLiteralTest::provideEscapesTheOptionLeavesAlone() as $name => $witness) {
            $witnesses[] = [$name, $witness, false];
        }

        foreach ($witnesses as [$name, $witness, $readAsCharacter]) {
            $match = [];
            foreach (self::releases() as $release) {
                $byPhp = [];
                $last = null;
                foreach (PcreTarget::phpVersionBoundaries() as $phpVersionId) {
                    $valid = true === self::verdict($witness['pattern'], $phpVersionId, $release);
                    if ($readAsCharacter && $valid && !PhpVersionGates::badEscapeIsLiteral($phpVersionId, '')) {
                        continue 2;
                    }

                    $found = $valid ? $witness['match'] : false;
                    if ([] === $byPhp || $found !== $last) {
                        $byPhp[(string) $phpVersionId] = $found;
                        $last = $found;
                    }
                }
                $match[$release] = 1 === \count($byPhp) ? $last : ['php' => $byPhp];
            }
            $claims[] = ['source' => 'BadEscapeIsLiteralTest: '.$name, 'pattern' => $witness['pattern'], 'subject' => $witness['subject'], 'group' => 0, 'match' => $match];
        }

        // The "{1}+" flips: before 10.34 the pattern finds what its plain
        // spelling finds, which the rewrite finds on every release.
        $atomic = PcreFeature::PossessiveRepeatOnceIsAtomic->release();
        foreach (PossessiveRepeatOnceWitnessTest::provideFlips() as $name => $flip) {
            $before = $rewritten = [];
            foreach (self::releases() as $release) {
                if (version_compare($release, $atomic, '<')) {
                    $before[$release] = $flip['oldMatch'];
                }
                $rewritten[$release] = $flip['oldMatch'];
            }
            $source = 'PossessiveRepeatOnceWitnessTest: '.$name;
            $claims[] = ['source' => $source, 'pattern' => $flip['pattern'], 'subject' => $flip['subject'], 'group' => 0, 'match' => $before];
            $claims[] = ['source' => $source, 'pattern' => $flip['rewritten'], 'subject' => $flip['subject'], 'group' => 0, 'match' => $rewritten];
        }

        return $claims;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function sameClaims(): array
    {
        $claims = [];
        foreach (self::rewrites() as $rewrite) {
            $claims[] = ['source' => $rewrite['source'], 'pattern' => $rewrite['pattern'], 'same_as' => $rewrite['same_as'], 'releases' => $rewrite['releases'], 'subjects' => $rewrite['subjects']];
        }

        return $claims;
    }

    /**
     * The pattern rewrites of the Rector fixtures, each on the releases its
     * test targets, where the library reads the original as compiling.
     *
     * @return list<array{source: string, pattern: string, same_as: string, releases: list<string>, subjects: list<string>}>
     */
    private static function rewrites(): array
    {
        $rewrites = [];
        $tests = glob(self::RECTOR_TESTS.'/*/*Test.php') ?: [];
        sort($tests);
        foreach ($tests as $test) {
            $code = (string) file_get_contents($test);
            if (1 !== preg_match("~glob\\(__DIR__\\.'/(\\w+)/\\*\\.php\\.inc'\\)~", $code, $directory)
                || 1 !== preg_match("~__DIR__\\.'/config/(\\w+)\\.php'~", $code, $config)) {
                continue;
            }

            $phpVersionId = self::configuredPhp(\dirname($test).'/config/'.$config[1].'.php');
            if (null === $phpVersionId) {
                continue;
            }
            $release = PcreTarget::bundledWith($phpVersionId)->pcreVersion;
            if (!\in_array($release, self::releases(), true)) {
                continue;
            }

            $fixtures = glob(\dirname($test).'/'.$directory[1].'/*.php.inc') ?: [];
            sort($fixtures);
            foreach ($fixtures as $fixture) {
                $content = (string) file_get_contents($fixture);
                if (!str_contains($content, self::SPLIT)) {
                    continue;
                }

                [$before, $after] = explode(self::SPLIT, $content, 2);
                $original = ReplaySubjects::literals($before);
                $rewritten = ReplaySubjects::literals($after);
                if (\count($original) !== \count($rewritten)) {
                    continue;
                }

                foreach ($original as $index => $pattern) {
                    $rewrite = $rewritten[$index];
                    if ($pattern === $rewrite || !self::isDelimited($pattern) || !self::isDelimited($rewrite)
                        || true !== self::verdict($pattern, $phpVersionId, $release)) {
                        continue;
                    }

                    $key = $pattern."\0".$rewrite;
                    $rewrites[$key] ??= [
                        'source' => 'Rector fixture '.substr($fixture, \strlen(self::RECTOR_TESTS) + 1),
                        'pattern' => $pattern,
                        'same_as' => $rewrite,
                        'releases' => [],
                        'subjects' => ReplaySubjects::of($content),
                    ];
                    if (!\in_array($release, $rewrites[$key]['releases'], true)) {
                        $rewrites[$key]['releases'][] = $release;
                        sort($rewrites[$key]['releases'], \SORT_NATURAL);
                    }
                }
            }
        }

        return array_values($rewrites);
    }

    /**
     * The PHP version a Rector test configuration targets, as a
     * PHP_VERSION_ID; null when it names none.
     */
    private static function configuredPhp(string $config): ?int
    {
        $code = (string) file_get_contents($config);
        if (1 === preg_match('~withPhpVersion\(PhpVersion::PHP_(\d)(\d)\)~', $code, $named)) {
            return (int) $named[1] * 10000 + (int) $named[2] * 100;
        }

        return 1 === preg_match('~withPhpVersion\((\d{5})\)~', $code, $literal) ? (int) $literal[1] : null;
    }

    /**
     * Whether a string literal reads as a pattern: a delimiter that is no
     * letter, digit, backslash or space, closed again before the modifiers.
     */
    private static function isDelimited(string $literal): bool
    {
        if ('' === $literal || 1 === preg_match('~^[\w\s\\\\]~', $literal)) {
            return false;
        }

        $closing = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$literal[0]] ?? $literal[0];
        $end = strrpos($literal, $closing);

        return false !== $end && $end > 0 && 1 === preg_match('~^[a-zA-Z]*$~', substr($literal, $end + 1));
    }

    /**
     * Every string that is no valid UTF-8 becomes {"hex": "..."}.
     */
    private static function encodeStrings(mixed $value): mixed
    {
        if (\is_string($value)) {
            return 1 === preg_match('//u', $value) ? $value : ['hex' => bin2hex($value)];
        }

        if (\is_array($value)) {
            $encoded = [];
            foreach ($value as $key => $item) {
                $encoded[$key] = self::encodeStrings($item);
            }

            return $encoded;
        }

        return $value;
    }
}
