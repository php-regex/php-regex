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

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;

/**
 * The PHP and PCRE2 a project's patterns are judged for, and where each
 * came from.
 *
 * The PHP version is the --php-version option, else regex.json's
 * phpVersion, else composer.json (config.platform.php, then the lowest
 * version require.php allows), else the running PHP. The PCRE2 release is
 * the --pcre-version option, else regex.json's pcreVersion, else the one the
 * PHP version bundles (the running one when the PHP is the running PHP).
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
 * Reading composer.json never fails: what cannot be read becomes a notice
 * and the running PHP is used.
 *
 * @internal
 */
final readonly class ProjectTarget
{
    /**
     * @param list<string>     $notices
     * @param list<PcreTarget> $range   the target first
     */
    private function __construct(
        private PcreTarget $target,
        private string $source,
        private array $notices,
        private array $range,
    ) {}

    /**
     * The target of the CLI: the command-line options, then regex.json.
     *
     * @param string|null           $phpFlag    the --php-version option
     * @param string|null           $pcreFlag   the --pcre-version option
     * @param array<string, mixed>  $config     the loaded regex.json
     * @param string                $projectDir where composer.json is read, never a parent of it
     * @param array<string, string> $env        the environment, for COMPOSER
     *
     * @throws InvalidRegexOptionException when an option or regex.json names no version
     */
    public static function resolve(?string $phpFlag, ?string $pcreFlag, array $config, string $projectDir, array $env): self
    {
        $configPhp = $config['phpVersion'] ?? null;
        $configPcre = $config['pcreVersion'] ?? null;

        return self::fromSources(
            ['--php-version' => $phpFlag, 'regex.json' => \is_string($configPhp) || \is_int($configPhp) ? $configPhp : null],
            ['--pcre-version' => $pcreFlag, 'regex.json' => \is_string($configPcre) ? $configPcre : null],
            $projectDir,
            $env,
        );
    }

    /**
     * The target of an entry point that names its own settings: for each
     * field, the first source that is set, in the order given; then, for
     * the PHP version, composer.json and the running PHP, and for the PCRE2
     * release, the one the PHP version bundles.
     *
     * @param array<string, string|int|null> $php        source name => version, e.g. "php_regex.php_version" => "8.2"
     * @param array<string, string|null>     $pcre       source name => PCRE2 release
     * @param string|null                    $projectDir where composer.json is read, never a parent of it; null reads none
     * @param array<string, string>          $env        the environment, for COMPOSER
     *
     * @throws InvalidRegexOptionException when a source that is set names no version
     */
    public static function fromSources(array $php, array $pcre, ?string $projectDir, array $env): self
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
            if (\is_string($version) && 'runtime' === strtolower(trim($version))) {
                [$phpVersionId, $phpSource, $runningPhp] = [\PHP_VERSION_ID, $name.' (running PHP)', true];

                break;
            }

            [$phpVersionId, $phpSource] = [self::phpVersionId($version), (string) $name];

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

        $pinnedPcre = $pcreVersion;
        $pcreVersion ??= $runningPhp ? PcreTarget::runtime()->pcreVersion : PcreTarget::bundledWith($phpVersionId)->pcreVersion;

        $source = null === $pcreSource || $pcreSource === $phpSource ? $phpSource : $phpSource.'; '.$pcreSource;
        $target = new PcreTarget($phpVersionId, $pcreVersion);

        return new self($target, $source, $notices, self::rangeFor($target, $constraint, $pinnedPcre));
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
     * @param RegexParser          $floor        the parser of the floor
     * @param array<string, mixed> $regexOptions what the floor's parser was created with, its target aside
     *
     * @throws InvalidRegexOptionException when an option names no setting
     *
     * @return list<RegexParser>
     */
    public function rangeParsers(RegexParser $floor, array $regexOptions = []): array
    {
        $parsers = [];
        foreach (\array_slice($this->range, 1) as $target) {
            // Compiling with the running PHP judges one version: the floor's.
            $parsers[] = RegexParser::create([
                'php_version' => $target->phpVersionId,
                'pcre_version' => $target->pcreVersion,
                'cache' => $floor->getCache(),
                'runtime_pcre_validation' => false,
            ] + $regexOptions);
        }

        return $parsers;
    }

    /**
     * A target as the JSON report names it: the PHP as major.minor, or
     * major.minor.patch when the patch is not 0, and the PCRE2 release.
     *
     * @return array{php: string, pcre: string}
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
     * then the PCRE2's when it came from elsewhere.
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
     * @return array{php_version: int, pcre_version: string}
     */
    public function regexOptions(): array
    {
        return ['php_version' => $this->target->phpVersionId, 'pcre_version' => $this->target->pcreVersion];
    }

    /**
     * @return array{php: string, pcre: string, source: string, range: list<array{php: string, pcre: string}>}
     */
    public function toArray(): array
    {
        return [
            'php' => $this->php(),
            'pcre' => $this->target->pcreVersion,
            'source' => $this->source,
            'range' => array_map(self::describe(...), $this->range),
        ];
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
                    $range[] = new PcreTarget($point, $pinnedPcre ?? PcreTarget::bundledWith($point)->pcreVersion);

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

        $platform = self::at($composer, 'config', 'platform', 'php');
        if (\is_string($platform)) {
            $floor = self::floor($platform);
            if (null !== $floor && !str_contains($platform, ' ') && !str_contains($platform, '|')) {
                return [self::clamp($floor, $name.' config.platform.php', $notices), $name.' config.platform.php', null];
            }
            $notices[] = \sprintf('%s config.platform.php "%s" is not a version: reading require.php instead.', $name, $platform);
        }

        $constraint = self::at($composer, 'require', 'php');
        if (!\is_string($constraint)) {
            $notices[] = \sprintf('%s has no require.php constraint: judging for the running PHP.', $name);

            return null;
        }

        $floor = self::floor($constraint);
        if (null === $floor) {
            $notices[] = \sprintf('%s require.php "%s" names no lowest PHP version: judging for the running PHP.', $name, $constraint);

            return null;
        }

        return [self::clamp($floor, $name.' require.php', $notices), $name.' require.php', $constraint];
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
     * @param list<string> $notices
     */
    private static function clamp(int $floor, string $from, array &$notices): int
    {
        // The oldest PHP the library judges; a lower floor is judged as this.
        $lowest = PcreTarget::phpVersionBoundaries()[0];
        if ($floor >= $lowest) {
            return $floor;
        }

        $notices[] = \sprintf(
            '%s allows PHP %d.%d, older than the PHP %4$s this library supports: judging for PHP %4$s.',
            $from,
            intdiv($floor, 10000),
            intdiv($floor, 100) % 100,
            self::phpLabel($lowest),
        );

        return $lowest;
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
    private static function intervals(string $constraint): ?array
    {
        $intervals = [];
        foreach (LibraryPcre::split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $branch) {
            $interval = self::branchInterval($branch);
            if (null === $interval) {
                return null;
            }
            $intervals[] = $interval;
        }

        return [] === $intervals ? null : $intervals;
    }

    /**
     * @return array{int, int|null}|null
     */
    private static function branchInterval(string $branch): ?array
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
        foreach (LibraryPcre::split('/[\s,]+/', trim($branch)) ?: [] as $term) {
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
