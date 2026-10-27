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

use PHPRegex\Parser\Cache\ArrayCache;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Cache\FilesystemCache;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Text\Ascii;

/**
 * Configuration options for Regex parser.
 *
 * Provides a simple, validated way to configure regex parsing behavior
 * including limits, caching, and ReDoS pattern exclusions.
 *
 * The keys are closed and each value has its type; the ranges (a positive
 * length, a version that names a release) are checked where the options
 * are built, an InvalidRegexOptionException saying which one is wrong.
 *
 * @phpstan-type OptionsArray array{
 *     max_pattern_length?: int,
 *     max_lookbehind_length?: int,
 *     cache?: CacheInterface|string|null,
 *     redos_ignored_patterns?: array<string>,
 *     runtime_pcre_validation?: bool,
 *     max_recursion_depth?: int,
 *     php_version?: int|string|null,
 *     pcre_version?: string|null,
 *     locale?: string|null,
 * }
 */
final class ParserOptions
{
    /**
     * Default maximum allowed regex pattern length.
     */
    public const DEFAULT_MAX_PATTERN_LENGTH = 100_000;

    /**
     * Default maximum length of a variable-length lookbehind, PCRE2's own
     * default for max_varlookbehind. A fixed-length lookbehind is only
     * limited by PCRE's ceiling of 65535 characters.
     */
    public const DEFAULT_MAX_LOOKBEHIND_LENGTH = 255;

    /**
     * How deep the parser may nest groups before it stops.
     */
    public const DEFAULT_MAX_RECURSION_DEPTH = 1024;

    /**
     * What "php_version" takes, said when it holds something else.
     */
    public const PHP_VERSION_EXPECTED = '"php_version" must be a version string like "8.2", a PHP_VERSION_ID integer, or "runtime".';

    /**
     * The smallest PHP_VERSION_ID "php_version" takes, PHP 7.0's: a smaller
     * integer is a version written as a number ("8", "82") or no version.
     */
    public const OLDEST_PHP_VERSION_ID = 70000;

    /**
     * The largest PHP_VERSION_ID "php_version" takes, PHP 9.99.99's.
     */
    public const LATEST_PHP_VERSION_ID = 99999;

    /**
     * Allowed option keys for configuration.
     */
    private const VALID_OPTIONS = [
        'max_pattern_length',
        'max_lookbehind_length',
        'cache',
        'redos_ignored_patterns',
        'runtime_pcre_validation',
        'max_recursion_depth',
        'php_version',
        'pcre_version',
        'locale',
    ];

    /**
     * The PHP version, the PCRE2 release and the locale patterns are judged
     * for.
     */
    public readonly PcreTarget $target;

    /**
     * Create new configuration options.
     *
     * @param int             $maxPatternLength      Maximum allowed regex pattern length
     * @param int             $maxLookbehindLength   Maximum length of a variable-length lookbehind; a
     *                                               fixed-length one is only limited by PCRE's 65535
     * @param CacheInterface  $cache                 Cache implementation to use
     * @param array<string>   $redosIgnoredPatterns  Patterns to ignore in ReDoS analysis
     * @param bool            $runtimePcreValidation Whether to validate against the PCRE runtime
     * @param int             $maxRecursionDepth     Maximum recursion depth during parsing
     * @param PcreTarget|null $target                The PHP, PCRE2 and locale judged; the running ones when null
     *
     * @throws InvalidRegexOptionException when a length or a depth is out of its range
     */
    public function __construct(
        public readonly int $maxPatternLength,
        public readonly int $maxLookbehindLength,
        public readonly CacheInterface $cache,
        public readonly array $redosIgnoredPatterns = [],
        public readonly bool $runtimePcreValidation = false,
        public readonly int $maxRecursionDepth = self::DEFAULT_MAX_RECURSION_DEPTH,
        ?PcreTarget $target = null,
    ) {
        if ($maxPatternLength <= 0) {
            throw new InvalidRegexOptionException('"max_pattern_length" must be a positive integer.');
        }

        if ($maxLookbehindLength < 0) {
            throw new InvalidRegexOptionException('"max_lookbehind_length" must be a non-negative integer.');
        }

        if ($maxRecursionDepth <= 0) {
            throw new InvalidRegexOptionException('"max_recursion_depth" must be a positive integer.');
        }

        $this->target = $target ?? PcreTarget::runtime();

        // PHP compiles with the engine that runs, under the runtime's
        // locale: it judges the target only when the target is that engine.
        if ($this->runtimePcreValidation && !$this->target->isRunningEngine()) {
            $running = PcreTarget::runtime();

            throw new InvalidRegexOptionException(\sprintf(
                '"runtime_pcre_validation" compiles with the running PHP, which does not judge the target (%s); drop one of them.',
                $this->target->withLocale($running->locale)->isRunningEngine()
                    ? \sprintf('locale "%s"', $this->target->locale)
                    : \sprintf('PHP %d.%d, PCRE2 %s', intdiv($this->target->phpVersionId, 10000), intdiv($this->target->phpVersionId, 100) % 100, $this->target->pcreVersion),
            ));
        }
    }

    /**
     * Create configuration from array of options.
     *
     * Reads options from anywhere, a configuration file or a command line
     * included: each key and value is checked here. Code that writes its
     * options as a literal array passes them to Regex::create() or
     * RegexParser::create(), whose OptionsArray shape PHPStan checks.
     *
     * @param array<string, mixed> $options Configuration options
     *
     * @return self New configuration instance
     */
    public static function fromArray(array $options): self
    {
        if ([] === $options) {
            return self::createDefault();
        }

        self::validateOptionKeys($options);

        $maxLength = self::getPatternLength($options);
        $lookbehindLength = self::getLookbehindLength($options);
        $cache = self::createCache($options);
        $patterns = self::getIgnoredPatterns($options);
        $runtimeValidation = self::getRuntimePcreValidation($options);
        $recursionDepth = self::getRecursionDepth($options);
        $target = self::getTarget($options);

        return new self($maxLength, $lookbehindLength, $cache, $patterns, $runtimeValidation, $recursionDepth, $target);
    }

    /**
     * The PHP_VERSION_ID a "php_version" names: an integer, its digits, or a
     * version, "8.2" or "8.2.1", each part of the version after the major at
     * most 99, as PHP_VERSION_ID counts them, up to PHP 9.99.99; "runtime"
     * names the running PHP. A version names its release however old: "5.6" is PHP 5.6, which a bridge
     * refuses as no PHP on PCRE2 with its own reason. An integer below PHP
     * 7.0's id is refused, and a number of one or two digits that reads as
     * PHP 7, 8 or 9, a major or a major and a minor run together, with the
     * way to write it.
     *
     * @internal
     *
     * @throws InvalidRegexOptionException
     */
    public static function phpVersionIdOf(int|string $version): int
    {
        $written = $version;
        if (\is_string($version)) {
            $version = $written = trim($version, " \t\n\r\0\x0B");
            if ('runtime' === Ascii::lower($version)) {
                return \PHP_VERSION_ID;
            }

            if (1 === LibraryPcre::match('/^([0-9]++)\.([0-9]{1,2})(?:\.([0-9]{1,2}))?$/D', $version, $parts)) {
                $version = (int) $parts[1] * 10000 + (int) $parts[2] * 100 + (int) ($parts[3] ?? 0);
                if (0 === $version) {
                    throw new InvalidRegexOptionException(self::PHP_VERSION_EXPECTED);
                }

                return self::notPastTheLatest($version, $written);
            }

            $version = Ascii::isDigit($version) && \strlen($version) <= 9 ? (int) $version : null;
        }

        if (!\is_int($version)) {
            throw new InvalidRegexOptionException(self::PHP_VERSION_EXPECTED);
        }

        if ($version <= 0) {
            throw new InvalidRegexOptionException(self::PHP_VERSION_EXPECTED);
        }

        if ($version >= self::OLDEST_PHP_VERSION_ID) {
            return self::notPastTheLatest($version, $written);
        }

        // 8 reads as PHP 8.0, 82 as PHP 8.2: a version written as a number.
        [$major, $minor] = $version < 10 ? [$version, 0] : [intdiv($version, 10), $version % 10];
        if ($version < 100 && $major >= 7) {
            throw new InvalidRegexOptionException(\sprintf('"php_version" %d is no PHP_VERSION_ID: write PHP %2$d.%3$d as %4$d or "%2$d.%3$d".', $version, $major, $minor, $major * 10000 + $minor * 100));
        }

        throw new InvalidRegexOptionException(\sprintf('"php_version" %d is below %d, the PHP_VERSION_ID of PHP 7.0: write a version as "8.2", or as its PHP_VERSION_ID, 80200.', $version, self::OLDEST_PHP_VERSION_ID));
    }

    /**
     * The target of a PHP, a PCRE2 release and a locale, each named or
     * not: the one place the options, the lint and the bridges resolve it.
     * A release named is used as is, older or newer than the one the PHP
     * bundles. Without one, the running PHP gets the PCRE2 it links, and a
     * PHP named, the running one included, the PCRE2 its sources bundle
     * (PcreTarget::bundledWith()). The locale is the runtime's unless one
     * is named.
     *
     * @internal
     *
     * @param int|null    $phpVersionId a PHP_VERSION_ID, null for the running PHP
     * @param string|null $pcreVersion  a PCRE2 release, "10.44", as PCRE_VERSION spells it or without its date
     * @param string|null $locale       an LC_CTYPE locale, "tr_TR.UTF-8"
     *
     * @throws InvalidRegexOptionException when $pcreVersion names no release, or $locale one this machine
     *                                     cannot set
     */
    public static function resolveTarget(?int $phpVersionId, ?string $pcreVersion = null, ?string $locale = null): PcreTarget
    {
        $pcreVersion ??= null === $phpVersionId ? \PCRE_VERSION : PcreTarget::bundledWith($phpVersionId)->pcreVersion;

        return new PcreTarget($phpVersionId ?? \PHP_VERSION_ID, $pcreVersion, $locale);
    }

    /**
     * Create default configuration with no custom options.
     *
     * @return self Default configuration instance
     */
    private static function createDefault(): self
    {
        return new self(
            self::DEFAULT_MAX_PATTERN_LENGTH,
            self::DEFAULT_MAX_LOOKBEHIND_LENGTH,
            new ArrayCache(),
            [],
            false,
        );
    }

    /**
     * Validate that all provided option keys are supported.
     *
     * @param array<string, mixed> $options Options to validate
     */
    private static function validateOptionKeys(array $options): void
    {
        $invalidKeys = array_diff(
            array_keys($options),
            self::VALID_OPTIONS,
        );

        if ([] !== $invalidKeys) {
            throw new InvalidRegexOptionException(\sprintf(
                'Unknown option(s): %s. Allowed options are: %s.',
                implode(', ', $invalidKeys),
                implode(', ', self::VALID_OPTIONS),
            ));
        }
    }

    /**
     * Get maximum pattern length from options.
     *
     * @param array<string, mixed> $options Configuration options
     *
     * @return int Maximum pattern length
     */
    private static function getPatternLength(array $options): int
    {
        $length = $options['max_pattern_length'] ?? self::DEFAULT_MAX_PATTERN_LENGTH;

        // The range is the constructor's to check.
        if (!\is_int($length)) {
            throw new InvalidRegexOptionException(
                '"max_pattern_length" must be a positive integer.',
            );
        }

        return $length;
    }

    /**
     * Get maximum lookbehind length from options.
     *
     * @param array<string, mixed> $options Configuration options
     *
     * @return int Maximum lookbehind length
     */
    private static function getLookbehindLength(array $options): int
    {
        $length = $options['max_lookbehind_length'] ?? self::DEFAULT_MAX_LOOKBEHIND_LENGTH;

        if (!\is_int($length)) {
            throw new InvalidRegexOptionException(
                '"max_lookbehind_length" must be a non-negative integer.',
            );
        }

        return $length;
    }

    /**
     * Get runtime PCRE validation flag from options.
     *
     * @param array<string, mixed> $options Configuration options
     */
    private static function getRuntimePcreValidation(array $options): bool
    {
        $runtimeValidation = $options['runtime_pcre_validation'] ?? false;

        if (!\is_bool($runtimeValidation)) {
            throw new InvalidRegexOptionException(
                '"runtime_pcre_validation" must be a boolean.',
            );
        }

        return $runtimeValidation;
    }

    /**
     * Get recursion depth from options.
     *
     * @param array<string, mixed> $options Configuration options
     *
     * @return int Maximum recursion depth
     */
    private static function getRecursionDepth(array $options): int
    {
        $depth = $options['max_recursion_depth'] ?? self::DEFAULT_MAX_RECURSION_DEPTH;

        if (!\is_int($depth)) {
            throw new InvalidRegexOptionException(
                '"max_recursion_depth" must be a positive integer.',
            );
        }

        return $depth;
    }

    /**
     * The PHP, the PCRE2 and the locale judged: the running ones by
     * default, a PHP version alone with the PCRE2 it bundles, a PCRE2
     * release alone with the running PHP, either under the runtime's locale
     * unless one is named.
     *
     * @param array<string, mixed> $options Configuration options
     */
    private static function getTarget(array $options): PcreTarget
    {
        $locale = $options['locale'] ?? null;
        if (null !== $locale && !\is_string($locale)) {
            throw new InvalidRegexOptionException(\sprintf('"locale" must be a locale name like "tr_TR.UTF-8", not a %s.', get_debug_type($locale)));
        }

        $pcreVersion = $options['pcre_version'] ?? null;

        if (null !== $pcreVersion && !\is_string($pcreVersion)) {
            throw new InvalidRegexOptionException(\sprintf('"pcre_version" must be a PCRE2 release like "10.44", not a %s.', get_debug_type($pcreVersion)));
        }

        // "runtime", as PHPStan's phpVersion takes it: the running PHP, with
        // the PCRE2 it links unless a release is given beside it.
        $phpVersion = $options['php_version'] ?? null;
        if (\is_string($phpVersion) && 'runtime' === Ascii::lower(trim($phpVersion, " \t\n\r\0\x0B"))) {
            return self::resolveTarget(null, $pcreVersion, $locale);
        }

        return self::resolveTarget(self::getPhpVersionId($options), $pcreVersion, $locale);
    }

    /**
     * The PHP_VERSION_ID a "php_version" names, from PHP 7.0 to PHP 9.99.
     *
     * @param array<string, mixed> $options Configuration options
     */
    private static function getPhpVersionId(array $options): ?int
    {
        $version = $options['php_version'] ?? null;

        if (null === $version) {
            return null;
        }

        if (!\is_int($version) && !\is_string($version)) {
            throw new InvalidRegexOptionException(self::PHP_VERSION_EXPECTED);
        }

        $phpVersionId = self::phpVersionIdOf($version);
        if ($phpVersionId < self::OLDEST_PHP_VERSION_ID) {
            throw new InvalidRegexOptionException(\sprintf('"php_version" %s is below 7.0: write a version as "8.2", or as its PHP_VERSION_ID, 80200.', trim((string) $version, " \t\n\r\0\x0B")));
        }

        return $phpVersionId;
    }

    private static function notPastTheLatest(int $phpVersionId, int|string $written): int
    {
        if ($phpVersionId > self::LATEST_PHP_VERSION_ID) {
            throw new InvalidRegexOptionException(\sprintf('"php_version" %s is past PHP 9.99: write a version as "8.2", or as its PHP_VERSION_ID, 80200.', $written));
        }

        return $phpVersionId;
    }

    /**
     * Create cache instance from options.
     *
     * @param array<string, mixed> $options Configuration options
     *
     * @return CacheInterface Cache implementation
     */
    private static function createCache(array $options): CacheInterface
    {
        // Nothing goes to disk unless a directory is named.
        if (!\array_key_exists('cache', $options)) {
            return new ArrayCache();
        }

        $cacheOption = $options['cache'];

        if (null === $cacheOption) {
            return new NullCache();
        }

        if (\is_string($cacheOption)) {
            return self::createFilesystemCache($cacheOption);
        }

        if ($cacheOption instanceof CacheInterface) {
            return $cacheOption;
        }

        throw new InvalidRegexOptionException(
            'The "cache" option must be null, a cache path, or a CacheInterface implementation.',
        );
    }

    /**
     * Create filesystem cache from path.
     *
     * @param string $path Cache directory path
     *
     * @return FilesystemCache Filesystem cache instance
     */
    private static function createFilesystemCache(string $path): FilesystemCache
    {
        $trimmedPath = trim($path, " \t\n\r\0\x0B");

        if ('' === $trimmedPath) {
            throw new InvalidRegexOptionException(
                'The "cache" option cannot be an empty string.',
            );
        }

        return new FilesystemCache($trimmedPath);
    }

    /**
     * Get ignored ReDoS patterns from options.
     *
     * @param array<string, mixed> $options Configuration options
     *
     * @return array<string> List of ignored patterns
     */
    private static function getIgnoredPatterns(array $options): array
    {
        $patterns = $options['redos_ignored_patterns'] ?? [];

        if (!\is_array($patterns)) {
            throw new InvalidRegexOptionException(
                '"redos_ignored_patterns" must be a list of strings.',
            );
        }

        if ([] === $patterns) {
            return [];
        }

        self::validatePatternStrings($patterns);

        /** @var array<string> $result */
        $result = array_values(array_unique($patterns));

        return $result;
    }

    /**
     * Validate that all patterns are strings.
     *
     * @param array<mixed> $patterns Patterns to validate
     *
     * @phpstan-assert array<string> $patterns
     */
    private static function validatePatternStrings(array $patterns): void
    {
        foreach ($patterns as $pattern) {
            if (!\is_string($pattern)) {
                throw new InvalidRegexOptionException(
                    '"redos_ignored_patterns" must contain only strings.',
                );
            }
        }
    }
}
