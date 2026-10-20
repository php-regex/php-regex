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
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Validation\RangeDiff;

/**
 * The PHP, the PCRE2 and the locale a project's patterns are judged for,
 * and where each came from.
 *
 * The PHP version is the --php-version option, else regex.json's
 * phpVersion, else composer.json (config.platform.php, then the lowest
 * version require.php allows), else the running PHP. The PCRE2 release is
 * the --pcre-version option, else regex.json's pcreVersion, else the one the
 * PHP version bundles (the running one when the PHP is the running PHP).
 * The LC_CTYPE locale is the --locale option, else regex.json's locale,
 * else the runtime's: it is the same at every version of the range.
 * A project is judged for its lowest PHP because a pattern must compile on
 * every version the project installs on.
 *
 * When require.php gives the PHP, the range also holds every PHP version a
 * rule of the library changes at that the constraint allows above its
 * floor, up to its ceiling: a pattern valid on the floor may be refused by
 * a later PHP the project installs on. Each with the PCRE2 its PHP bundles,
 * or the release named at every point. Every other source names one
 * version, and the range is that version.
 *
 * A PCRE2 release older than 10.39, the oldest whose rules the library
 * knows in full, is judged with the 10.39 rules but for the few older
 * changes it names, and a notice says so. A PHP that ships PCRE1, 7.2 and
 * earlier, is never judged: no verdict of the library holds for it. A
 * version named below 7.3 is refused, and so is a composer.json whose
 * require.php allows no PHP of 7.3 or later. A require.php that also allows
 * one is judged from the lowest it allows, even under a config.platform.php
 * below 7.3, which only pins the PHP Composer resolves the dependencies
 * for, and a notice says the versions below 7.3 are not judged.
 *
 * Reading composer.json never fails: what cannot be read becomes a notice
 * and the running PHP is used.
 *
 * @internal
 *
 * @phpstan-type TargetDescription array{php: string, pcre: string, guard?: string, guard_pcre?: string}
 *
 * @phpstan-import-type LintConfig from LintConfigResult
 */
final class ProjectTarget
{
    /**
     * @param list<string>                    $notices
     * @param list<PcreTarget>                $range      the target first
     * @param list<array{int, int|null}>|null $admitted   the PHP versions the project installs on, from
     *                                                    require.php; the series of the target when null
     * @param string|null                     $pinnedPcre the PCRE2 release named for every version
     */
    private function __construct(
        private readonly PcreTarget $target,
        private readonly string $source,
        private readonly array $notices,
        private readonly array $range,
        private readonly ?array $admitted = null,
        private readonly ?string $pinnedPcre = null,
    ) {}

    /**
     * The target of the CLI: the command-line options, then regex.json.
     *
     * @param string|null           $phpFlag    the --php-version option
     * @param string|null           $pcreFlag   the --pcre-version option
     * @param LintConfig            $config     the loaded regex.json
     * @param string                $projectDir where composer.json is read, never a parent of it
     * @param array<string, string> $env        the environment, for COMPOSER
     * @param string|null           $localeFlag the --locale option
     *
     * @throws InvalidRegexOptionException when an option or regex.json names no version, or a locale the
     *                                     machine cannot set
     * @throws UnsupportedTargetException  when the PHP to judge for ships PCRE1
     */
    public static function resolve(?string $phpFlag, ?string $pcreFlag, array $config, string $projectDir, array $env, ?string $localeFlag = null): self
    {
        $configPhp = $config['phpVersion'] ?? null;
        $configPcre = $config['pcreVersion'] ?? null;
        $configLocale = $config['locale'] ?? null;

        return self::fromSources(
            ['--php-version' => $phpFlag, 'regex.json' => \is_string($configPhp) || \is_int($configPhp) ? $configPhp : null],
            ['--pcre-version' => $pcreFlag, 'regex.json' => \is_string($configPcre) ? $configPcre : null],
            $projectDir,
            $env,
            ['--locale' => $localeFlag, 'regex.json' => \is_string($configLocale) ? $configLocale : null],
        );
    }

    /**
     * The target of an entry point that names its own settings: for each
     * field, the first source that is set, in the order given; then, for
     * the PHP version, composer.json and the running PHP, for the PCRE2
     * release, the one the PHP version bundles, and for the locale, the
     * runtime's.
     *
     * @param array<string, string|int|null> $php        source name => version, e.g. "php_regex.php_version" => "8.2"
     * @param array<string, string|null>     $pcre       source name => PCRE2 release
     * @param string|null                    $projectDir where composer.json is read, never a parent of it; null reads none
     * @param array<string, string>          $env        the environment, for COMPOSER
     * @param array<string, string|null>     $locale     source name => LC_CTYPE locale
     *
     * @throws InvalidRegexOptionException when a source that is set names no version, or a locale the machine
     *                                     cannot set
     * @throws UnsupportedTargetException  when the PHP to judge for ships PCRE1
     */
    public static function fromSources(array $php, array $pcre, ?string $projectDir, array $env, array $locale = []): self
    {
        $notices = [];
        $runningPhp = false;
        $phpVersionId = null;
        $phpSource = null;
        $constraint = null;

        foreach ($php as $name => $version) {
            if (null === $version) {
                continue;
            }

            // "runtime", as PHPStan's phpVersion takes it: the PHP running
            // the command, with the PCRE2 it links.
            if (\is_string($version) && 'runtime' === Ascii::lower(trim($version, " \t\n\r\0\x0B"))) {
                [$phpVersionId, $phpSource, $runningPhp] = [\PHP_VERSION_ID, $name.' (running PHP)', true];

                break;
            }

            [$phpVersionId, $phpSource] = [self::onPcre2(self::phpVersionId($version), $name.' names'), (string) $name];

            break;
        }

        if (null === $phpVersionId || null === $phpSource) {
            $fromComposer = null === $projectDir ? null : self::fromComposer($projectDir, $env, $notices);
            if (null === $fromComposer) {
                [$phpVersionId, $phpSource, $runningPhp] = [\PHP_VERSION_ID, 'running PHP', true];
            } else {
                [$phpVersionId, $phpSource, $constraint] = $fromComposer;
            }
        }

        $pcreVersion = null;
        $pcreSource = null;
        foreach ($pcre as $name => $release) {
            if (null !== $release) {
                [$pcreVersion, $pcreSource] = [$release, (string) $name];

                break;
            }
        }

        $localeName = null;
        $localeSource = null;
        foreach ($locale as $name => $value) {
            if (null !== $value) {
                [$localeName, $localeSource] = [$value, (string) $name];

                break;
            }
        }

        $pinnedPcre = $pcreVersion;
        $pcreVersion ??= $runningPhp ? PcreTarget::runtime()->pcreVersion : PcreTarget::bundledWith($phpVersionId)->pcreVersion;

        $source = null === $pcreSource || $pcreSource === $phpSource ? $phpSource : $phpSource.'; '.$pcreSource;
        $source = null === $localeSource || str_contains('; '.$source.';', '; '.$localeSource.';') ? $source : $source.'; '.$localeSource;
        $target = new PcreTarget($phpVersionId, $pcreVersion, $localeName);
        $range = self::rangeFor($target, $constraint, $pinnedPcre);
        $admitted = null === $constraint ? null : self::admittedFrom($phpVersionId, self::intervals($constraint) ?? []);

        // A release older than the library knows in full is judged with
        // the rules of the oldest it does: said once for the whole range,
        // in the words every range verdict uses.
        $older = RangeDiff::notice(RangeDiff::judgedWith($range));
        if (null !== $older) {
            $notices[] = $older;
        }

        return new self($target, $source, $notices, $range, $admitted, $pinnedPcre);
    }

    /**
     * The target of a PHP version below the first on PCRE2 that a static
     * analyser names or read from composer.json, judged as lint judges it:
     * read again from the composer.json of $projectDir when the analyser
     * read it from there, refused when it named it.
     *
     * @param string                     $name       what named the version, as "phpVersion"
     * @param string|null                $projectDir where the analyser read composer.json, null when it did not
     * @param array<string, string>      $env        the environment, for COMPOSER
     * @param array<string, string|null> $locale     source name => LC_CTYPE locale
     *
     * @throws InvalidRegexOptionException when a locale is one the machine cannot set
     * @throws UnsupportedTargetException  when the PHP to judge for ships PCRE1
     */
    public static function belowPcre2(int $phpVersionId, string $name, ?string $projectDir, array $env, array $locale = []): self
    {
        if (null !== $projectDir) {
            $target = self::fromSources([], [], $projectDir, $env, $locale);
            if ('running PHP' !== $target->source) {
                return $target;
            }
        }

        throw self::onPcre1($phpVersionId, $name.' names');
    }

    /**
     * The target of a range of PHP versions a static analyser names, judged
     * as lint judges a require.php range: from its lowest PHP on PCRE2, with
     * a note when the range starts below it, refused when it allows none.
     *
     * @param string                     $name   what named the range, as "phpVersion"
     * @param array<string, string|null> $locale source name => LC_CTYPE locale
     *
     * @throws InvalidRegexOptionException when a bound names no version, or a locale the machine cannot set
     * @throws UnsupportedTargetException  when the range allows no PHP on PCRE2
     */
    public static function fromRange(int $min, int $max, string $name, array $locale = []): self
    {
        $firstOnPcre2 = PcreTarget::phpVersionBoundaries()[0];
        if ($max < $firstOnPcre2) {
            throw self::onPcre1($min, $name.' allows');
        }

        if ($min >= $firstOnPcre2) {
            return self::fromSources([$name => $min], [], null, [], $locale);
        }

        $target = self::fromSources([$name => $firstOnPcre2], [], null, [], $locale);
        $note = \sprintf(
            '%s allows PHP %d.%d, and PHP 7.2 and earlier ship PCRE1, which this library does not judge: judging from PHP %s, the lowest version it allows on PCRE2.',
            $name,
            intdiv($min, 10000),
            intdiv($min, 100) % 100,
            self::phpLabel($firstOnPcre2),
        );

        return new self($target->target, $target->source, [$note, ...$target->notices], $target->range, $target->admitted, $target->pinnedPcre);
    }

    public function target(): PcreTarget
    {
        return $this->target;
    }

    /**
     * The targets the patterns are validated at, the floor first: then,
     * when require.php gives the PHP, each PHP version above the floor a
     * rule changes at that the constraint allows, and the lowest version of
     * each OR branch above the floor, in ascending order.
     *
     * @return list<PcreTarget>
     */
    public function range(): array
    {
        return $this->range;
    }

    /**
     * A parser for each target of the range after the floor, with the
     * options of the floor's parser: they share its cache, so the targets
     * that read a pattern the same way parse it once.
     *
     * @param RegexParser $floor the parser of the floor
     *
     * @return list<RegexParser>
     */
    public function rangeParsers(RegexParser $floor): array
    {
        $parsers = [];
        foreach (\array_slice($this->range, 1) as $target) {
            $parsers[] = $floor->withTarget($target);
        }

        return $parsers;
    }

    /**
     * The versions a pattern is judged at, and those the project installs
     * on, for a guard or a "@regex-target" marker to narrow: each version
     * judged gets a parser with the options of the floor's, sharing its
     * cache, as rangeParsers() builds them.
     *
     * @param RegexParser $floor the parser of the floor
     */
    public function judgedRange(RegexParser $floor): JudgedRange
    {
        $admitted = $this->admitted ?? [[$this->target->phpVersionId, self::nextAt($this->target->phpVersionId, 2)]];

        return new JudgedRange($this->range, $admitted, $this->pinnedPcre, $floor);
    }

    /**
     * The versions a Composer constraint allows, as intervals, a branch
     * without a lower bound starting at the first version: what a guard or
     * a "@regex-target" marker narrows a project range to, never the range
     * itself. Null when the constraint is not one of the forms
     * composer.json's require.php is read in.
     *
     * @return non-empty-list<array{int, int|null}>|null
     */
    public static function constraintIntervals(string $constraint): ?array
    {
        return self::intervals($constraint, true);
    }

    /**
     * A target as the JSON report names it: the PHP as major.minor, or
     * major.minor.patch when the patch is not 0, and the PCRE2 release.
     *
     * @return TargetDescription
     */
    public static function describe(PcreTarget $target): array
    {
        return ['php' => self::phpLabel($target->phpVersionId), 'pcre' => $target->pcreVersion];
    }

    /**
     * A PHP_VERSION_ID as major.minor, or major.minor.patch when the patch
     * is not 0.
     */
    public static function phpLabel(int $phpVersionId): string
    {
        $label = \sprintf('%d.%d', intdiv($phpVersionId, 10000), intdiv($phpVersionId, 100) % 100);

        return 0 === $phpVersionId % 100 ? $label : $label.'.'.($phpVersionId % 100);
    }

    /**
     * Where the target came from: "--php-version", "regex.json",
     * "composer.json require.php", "running PHP"; the PHP's source first,
     * then the PCRE2's and the locale's when they came from elsewhere.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * What could not be read, or was changed, on the way.
     *
     * @return list<string>
     */
    public function notices(): array
    {
        return $this->notices;
    }

    /**
     * The PHP version as major.minor, or major.minor.patch when the patch is
     * not 0: the same label the range gives it.
     */
    public function php(): string
    {
        return self::phpLabel($this->target->phpVersionId);
    }

    /**
     * The options that make Regex::create() judge for this target.
     *
     * @return array{php_version: int, pcre_version: string, locale: string}
     */
    public function regexOptions(): array
    {
        return ['php_version' => $this->target->phpVersionId, 'pcre_version' => $this->target->pcreVersion, 'locale' => $this->target->locale];
    }

    /**
     * The target as a line of the report names it: "PHP 8.4, PCRE2 10.44",
     * with the locale when it is not "C".
     */
    public function label(): string
    {
        return \sprintf('PHP %s, PCRE2 %s%s', $this->php(), $this->target->pcreVersion, 'C' === $this->target->locale ? '' : ', locale '.$this->target->locale);
    }

    /**
     * @return array{php: string, pcre: string, locale: string, source: string, range: list<TargetDescription>}
     */
    public function toArray(): array
    {
        return [
            'php' => $this->php(),
            'pcre' => $this->target->pcreVersion,
            'locale' => $this->target->locale,
            'source' => $this->source,
            'range' => array_map(self::describe(...), $this->range),
        ];
    }

    /**
     * The intervals require.php allows, from the floor up: the versions on
     * PCRE1 and those below a pinned platform are not installed on.
     *
     * @param list<array{int, int|null}> $intervals
     *
     * @return list<array{int, int|null}>
     */
    private static function admittedFrom(int $floor, array $intervals): array
    {
        $admitted = [];
        foreach ($intervals as [$from, $below]) {
            $from = max($from, $floor);
            if (null === $below || $from < $below) {
                $admitted[] = [$from, $below];
            }
        }

        return $admitted;
    }

    /**
     * The floor, then, in ascending order, each gate point above it the
     * constraint allows and the lowest version of each OR branch above it:
     * "~8.3.0 || >=8.5.3" allows 8.5.3 to 8.5.9, which no gate point stands
     * for.
     *
     * @param string|null $constraint require.php, when the floor was read from it
     * @param string|null $pinnedPcre the PCRE2 release named for every point
     *
     * @return list<PcreTarget>
     */
    private static function rangeFor(PcreTarget $floor, ?string $constraint, ?string $pinnedPcre): array
    {
        $range = [$floor];
        $intervals = null === $constraint ? [] : (self::intervals($constraint) ?? []);
        $points = array_unique([...PcreTarget::phpVersionBoundaries(), ...array_column($intervals, 0)]);
        sort($points);
        foreach ($points as $point) {
            if ($point <= $floor->phpVersionId) {
                continue;
            }
            // A branch that allows nothing, ">=8.4 <8.2", holds no point.
            foreach ($intervals as [$lowest, $below]) {
                if ($point >= $lowest && (null === $below || $point < $below)) {
                    $range[] = new PcreTarget($point, $pinnedPcre ?? PcreTarget::bundledWith($point)->pcreVersion, $floor->locale);

                    continue 2;
                }
            }
        }

        return $range;
    }

    /**
     * @throws InvalidRegexOptionException
     */
    private static function phpVersionId(string|int $version): int
    {
        return ParserOptions::fromArray(['php_version' => $version])->target->phpVersionId;
    }

    /**
     * @param array<string, string> $env
     * @param list<string>          $notices
     *
     * @throws UnsupportedTargetException when the floor ships PCRE1 and require.php allows no PHP on PCRE2
     *
     * @return array{int, string, string|null}|null the floor, its source, and require.php when it was read
     */
    private static function fromComposer(string $projectDir, array $env, array &$notices): ?array
    {
        $name = ($env['COMPOSER'] ?? '') ?: 'composer.json';
        $path = str_starts_with($name, '/') || 1 === LibraryPcre::match('/^[A-Za-z]:[\\\\\/]/', $name) ? $name : $projectDir.'/'.$name;

        $contents = is_file($path) ? @file_get_contents($path) : false;
        if (false === $contents) {
            $notices[] = \sprintf('No %s in %s: judging for the running PHP.', $name, $projectDir);

            return null;
        }

        $composer = json_decode($contents, true);
        if (!\is_array($composer)) {
            $notices[] = \sprintf('%s is not a JSON object: judging for the running PHP.', $name);

            return null;
        }

        // A platform on PCRE1 pins the PHP Composer resolves the
        // dependencies for, the lowest the project installs on: it gives way
        // to a require.php that also allows a PHP on PCRE2, and is refused
        // when there is none.
        $pinned = null;
        $platform = self::at($composer, 'config', 'platform', 'php');
        if (\is_string($platform)) {
            $floor = self::floor($platform);
            if (null !== $floor && !str_contains($platform, ' ') && !str_contains($platform, '|')) {
                if ($floor >= PcreTarget::phpVersionBoundaries()[0]) {
                    return [$floor, $name.' config.platform.php', null];
                }
                $pinned = $floor;
            } else {
                $notices[] = \sprintf('%s config.platform.php "%s" is not a version: reading require.php instead.', $name, $platform);
            }
        }

        $constraint = self::at($composer, 'require', 'php');
        $constraint = \is_string($constraint) ? $constraint : null;
        $intervals = null === $constraint ? null : self::intervals($constraint);
        $firstOnPcre2 = null === $intervals ? null : self::lowestFrom($intervals, PcreTarget::phpVersionBoundaries()[0]);
        if (null !== $pinned) {
            if (null === $firstOnPcre2) {
                throw self::onPcre1($pinned, $name.' config.platform.php allows');
            }
            $notices[] = \sprintf(
                '%s config.platform.php pins PHP %d.%d, and PHP 7.2 and earlier ship PCRE1, which this library does not judge: judging from PHP %s, the lowest version require.php allows on PCRE2.',
                $name,
                intdiv($pinned, 10000),
                intdiv($pinned, 100) % 100,
                self::phpLabel($firstOnPcre2),
            );

            return [$firstOnPcre2, $name.' require.php', $constraint];
        }

        if (null === $constraint) {
            $notices[] = \sprintf('%s has no require.php constraint: judging for the running PHP.', $name);

            return null;
        }

        if (null === $intervals) {
            $notices[] = \sprintf('%s require.php "%s" names no lowest PHP version: judging for the running PHP.', $name, $constraint);

            return null;
        }

        // A range that also allows a PHP on PCRE2 installs there too: it is
        // judged from the lowest such PHP, and only the versions on PCRE1
        // are left out. A range that allows none is refused.
        $floor = min(array_column($intervals, 0));
        if ($floor < PcreTarget::phpVersionBoundaries()[0] && null !== $firstOnPcre2) {
            $notices[] = \sprintf(
                '%s require.php allows PHP %d.%d, and PHP 7.2 and earlier ship PCRE1, which this library does not judge: judging from PHP %s, the lowest version it allows on PCRE2.',
                $name,
                intdiv($floor, 10000),
                intdiv($floor, 100) % 100,
                self::phpLabel($firstOnPcre2),
            );
            $floor = $firstOnPcre2;
        }

        return [self::onPcre2($floor, $name.' require.php allows'), $name.' require.php', $constraint];
    }

    /**
     * The lowest version of $from or later the intervals allow, or null
     * when they allow none.
     *
     * @param non-empty-list<array{int, int|null}> $intervals
     */
    private static function lowestFrom(array $intervals, int $from): ?int
    {
        $lowest = null;
        foreach ($intervals as [$first, $below]) {
            $candidate = max($first, $from);
            if ((null === $below || $candidate < $below) && (null === $lowest || $candidate < $lowest)) {
                $lowest = $candidate;
            }
        }

        return $lowest;
    }

    /**
     * The value at a path of decoded JSON, or null.
     *
     * @param array<array-key, mixed> $data
     */
    private static function at(array $data, string ...$path): mixed
    {
        $value = $data;
        foreach ($path as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * The PHP version, once it is one on PCRE2.
     *
     * @param string $from what named it, and how: "--php-version names", "composer.json require.php allows"
     *
     * @throws UnsupportedTargetException when it ships PCRE1
     */
    private static function onPcre2(int $phpVersionId, string $from): int
    {
        // PCRE1 is another engine, not an older PCRE2: judging it as the
        // oldest PCRE2 would be a verdict about an engine it does not run.
        // The oldest PHP the library judges is the first on PCRE2, 7.3.
        if ($phpVersionId < PcreTarget::phpVersionBoundaries()[0]) {
            throw self::onPcre1($phpVersionId, $from);
        }

        return $phpVersionId;
    }

    /**
     * The refusal of a PHP version that ships PCRE1.
     *
     * @param string $from what named it, and how: "--php-version names", "composer.json require.php allows"
     */
    private static function onPcre1(int $phpVersionId, string $from): UnsupportedTargetException
    {
        return new UnsupportedTargetException(\sprintf(
            '%s PHP %d.%d: PHP 7.2 and earlier ship PCRE1, which this library does not judge. Name a PHP version of 7.3 or later to judge for.',
            $from,
            intdiv($phpVersionId, 10000),
            intdiv($phpVersionId, 100) % 100,
        ));
    }

    /**
     * The lowest version a Composer constraint allows, as a PHP_VERSION_ID,
     * or null when the constraint is not one of the forms read.
     */
    private static function floor(string $constraint): ?int
    {
        $intervals = self::intervals($constraint);

        return null === $intervals ? null : min(array_column($intervals, 0));
    }

    /**
     * The versions a Composer constraint allows, as PHP_VERSION_ID
     * intervals: the lowest allowed, and the first above it no longer
     * allowed (null when none is). Null when the constraint is not one of
     * the forms read: >=, >, <, <=, ^, ~, a bare version, X.Y.*, AND (comma
     * or space), OR (| or ||), a hyphen range. A branch without a lower
     * bound makes the whole constraint unreadable.
     *
     * @return non-empty-list<array{int, int|null}>|null
     */
    private static function intervals(string $constraint, bool $openBelow = false): ?array
    {
        $intervals = [];
        foreach (LibraryPcre::split('/\s*\|\|?\s*/', trim($constraint, " \t\n\r\0\x0B")) ?: [] as $branch) {
            $interval = self::branchInterval($branch, $openBelow);
            if (null === $interval) {
                return null;
            }
            $intervals[] = $interval;
        }

        return [] === $intervals ? null : $intervals;
    }

    /**
     * @param bool $openBelow whether a branch without a lower bound starts at the first version, where
     *                        it makes the constraint unreadable
     *
     * @return array{int, int|null}|null
     */
    private static function branchInterval(string $branch, bool $openBelow = false): ?array
    {
        // A partial upper version of a hyphen range allows its whole
        // branch: "8.2 - 8.4" is below 8.5; a full one is itself the last
        // allowed, "8.2 - 8.4.25" is below 8.4.26.
        if (1 === LibraryPcre::match('/^(\S+)\s+-\s+(\S+)$/', $branch, $range)) {
            $lowest = self::version($range[1]);
            $upper = self::version($range[2]);
            if (null === $lowest || null === $upper) {
                return null;
            }

            return [$lowest[0], self::nextAt($upper[0], $upper[1])];
        }

        // An operator may be followed by spaces: ">= 8.3".
        $branch = (string) LibraryPcre::replace('/(>=|<=|!=|==|[<>=^~])\s+/', '$1', $branch);

        $lowest = null;
        $below = null;
        foreach (LibraryPcre::split('/[\s,]+/', trim($branch, " \t\n\r\0\x0B")) ?: [] as $term) {
            if (1 !== LibraryPcre::match('/^(>=|<=|!=|==|[<>=^~])?(.+)$/', $term, $parts)) {
                return null;
            }
            $version = self::version($parts[2]);
            if (null === $version) {
                return null;
            }
            [$id, $precision, $wildcard] = $version;

            [$from, $until] = match ($parts[1]) {
                '!=' => [null, null],
                '<' => [null, $id],
                '<=' => [null, $id + 1],
                '>=', '>' => [$id, null],
                '^' => [$id, self::nextAt($id, 1)],
                '~' => [$id, self::nextAt($id, max(1, $precision - 1))],
                default => [$id, $wildcard ? self::nextAt($id, $precision) : $id + 1],
            };
            if (null !== $from) {
                $lowest = null === $lowest ? $from : max($lowest, $from);
            }
            if (null !== $until) {
                $below = null === $below ? $until : min($below, $until);
            }
        }

        if (null === $lowest && $openBelow && null !== $below) {
            $lowest = 0;
        }

        return null === $lowest ? null : [$lowest, $below];
    }

    /**
     * The first version past every version sharing the first $precision
     * parts of $id: 8.2.x past 8.2 is 8.3, past 8 is 9.0.
     */
    private static function nextAt(int $id, int $precision): int
    {
        return match ($precision) {
            1 => (intdiv($id, 10000) + 1) * 10000,
            2 => (intdiv($id, 100) + 1) * 100,
            default => $id + 1,
        };
    }

    /**
     * "8.3", "8.3.4", "8.3.*", "v8.3" as a PHP_VERSION_ID, the number of
     * parts given before any wildcard, and whether a wildcard ends it; "*"
     * and branch names as null.
     *
     * @return array{int, int, bool}|null
     */
    private static function version(string $version): ?array
    {
        if (1 !== LibraryPcre::match('/^v?(\d+)(?:\.(\d+|\*))?(?:\.(\d+|\*))?(?:\.\d+)?$/', $version, $parts)) {
            return null;
        }

        $minor = isset($parts[2]) && '*' !== $parts[2] ? (int) $parts[2] : 0;
        $patch = isset($parts[3]) && '*' !== $parts[3] ? (int) $parts[3] : 0;

        $precision = 1;
        $wildcard = false;
        foreach ([2, 3] as $index) {
            if (!isset($parts[$index]) || '' === $parts[$index]) {
                break;
            }
            if ('*' === $parts[$index]) {
                $wildcard = true;

                break;
            }
            $precision++;
        }

        return [(int) $parts[1] * 10000 + $minor * 100 + $patch, $precision, $wildcard];
    }
}
