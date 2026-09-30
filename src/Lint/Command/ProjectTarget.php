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

namespace RegexParser\Lint\Command;

use RegexParser\Exception\InvalidRegexOptionException;
use RegexParser\PcreTarget;
use RegexParser\RegexOptions;

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
 * Reading composer.json never fails: what cannot be read becomes a notice
 * and the running PHP is used.
 */
final readonly class ProjectTarget
{
    /**
     * The oldest PHP the library runs on; a lower floor is judged as this.
     */
    private const LOWEST_PHP = 80200;

    /**
     * @param list<string> $notices
     */
    private function __construct(
        private PcreTarget $target,
        private string $source,
        private array $notices,
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
     * @param array<string, string|int|null> $php        source name => version, e.g. "regex_parser.php_version" => "8.2"
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

        foreach ($php as $name => $version) {
            if (null !== $version) {
                [$phpVersionId, $phpSource] = [self::phpVersionId($version), (string) $name];

                break;
            }
        }

        if (null === $phpVersionId || null === $phpSource) {
            $fromComposer = null === $projectDir ? null : self::fromComposer($projectDir, $env, $notices);
            if (null === $fromComposer) {
                [$phpVersionId, $phpSource, $runningPhp] = [\PHP_VERSION_ID, 'running PHP', true];
            } else {
                [$phpVersionId, $phpSource] = $fromComposer;
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

        $pcreVersion ??= $runningPhp ? PcreTarget::runtime()->pcreVersion : PcreTarget::bundledWith($phpVersionId)->pcreVersion;

        $source = null === $pcreSource || $pcreSource === $phpSource ? $phpSource : $phpSource.'; '.$pcreSource;

        return new self(new PcreTarget($phpVersionId, $pcreVersion), $source, $notices);
    }

    public function target(): PcreTarget
    {
        return $this->target;
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
     * The PHP version as major.minor.
     */
    public function php(): string
    {
        return \sprintf('%d.%d', intdiv($this->target->phpVersionId, 10000), intdiv($this->target->phpVersionId, 100) % 100);
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
     * @return array{php: string, pcre: string, source: string}
     */
    public function toArray(): array
    {
        return ['php' => $this->php(), 'pcre' => $this->target->pcreVersion, 'source' => $this->source];
    }

    /**
     * @throws InvalidRegexOptionException
     */
    private static function phpVersionId(string|int $version): int
    {
        return RegexOptions::fromArray(['php_version' => $version])->target->phpVersionId;
    }

    /**
     * @param array<string, string> $env
     * @param list<string>          $notices
     *
     * @return array{int, string}|null
     */
    private static function fromComposer(string $projectDir, array $env, array &$notices): ?array
    {
        $name = ($env['COMPOSER'] ?? '') ?: 'composer.json';
        $path = str_starts_with($name, '/') || 1 === preg_match('/^[A-Za-z]:[\\\\\/]/', $name) ? $name : $projectDir.'/'.$name;

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
                return [self::clamp($floor, $name.' config.platform.php', $notices), $name.' config.platform.php'];
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

        return [self::clamp($floor, $name.' require.php', $notices), $name.' require.php'];
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
        if ($floor >= self::LOWEST_PHP) {
            return $floor;
        }

        $notices[] = \sprintf(
            '%s allows PHP %d.%d, older than the PHP 8.2 this library supports: judging for PHP 8.2.',
            $from,
            intdiv($floor, 10000),
            intdiv($floor, 100) % 100,
        );

        return self::LOWEST_PHP;
    }

    /**
     * The lowest version a Composer constraint allows, as a PHP_VERSION_ID,
     * or null when the constraint is not one of the forms read: >=, >, ^, ~,
     * a bare version, X.Y.*, AND (comma or space), OR (| or ||), a hyphen
     * range. A branch without a lower bound makes the whole constraint
     * unreadable.
     */
    private static function floor(string $constraint): ?int
    {
        $lowest = null;
        foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $branch) {
            $floor = self::branchFloor($branch);
            if (null === $floor) {
                return null;
            }
            $lowest = null === $lowest ? $floor : min($lowest, $floor);
        }

        return $lowest;
    }

    private static function branchFloor(string $branch): ?int
    {
        if (1 === preg_match('/^(\S+)\s+-\s+\S+$/', $branch, $range)) {
            return self::versionId($range[1]);
        }

        // An operator may be followed by spaces: ">= 8.3".
        $branch = (string) preg_replace('/(>=|<=|!=|==|[<>=^~])\s+/', '$1', $branch);

        $floor = null;
        foreach (preg_split('/[\s,]+/', trim($branch)) ?: [] as $term) {
            if (1 !== preg_match('/^(>=|<=|!=|==|[<>=^~])?(.+)$/', $term, $parts)) {
                return null;
            }
            $operator = $parts[1];
            $version = self::versionId($parts[2]);
            if (null === $version) {
                return null;
            }
            if (\in_array($operator, ['<', '<=', '!='], true)) {
                continue;
            }
            $floor = null === $floor ? $version : max($floor, $version);
        }

        return $floor;
    }

    /**
     * "8.3", "8.3.4", "8.3.*", "v8.3" as a PHP_VERSION_ID; "*" and branch
     * names as null.
     */
    private static function versionId(string $version): ?int
    {
        if (1 !== preg_match('/^v?(\d+)(?:\.(\d+|\*))?(?:\.(\d+|\*))?(?:\.\d+)?$/', $version, $parts)) {
            return null;
        }

        $minor = isset($parts[2]) && '*' !== $parts[2] ? (int) $parts[2] : 0;
        $patch = isset($parts[3]) && '*' !== $parts[3] ? (int) $parts[3] : 0;

        return (int) $parts[1] * 10000 + $minor * 100 + $patch;
    }
}
