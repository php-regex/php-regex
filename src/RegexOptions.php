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

use RegexParser\Cache\CacheInterface;
use RegexParser\Cache\FilesystemCache;
use RegexParser\Cache\NullCache;
use RegexParser\Exception\InvalidRegexOptionException;

/**
 * Configuration options for Regex parser.
 *
 * Provides a simple, validated way to configure regex parsing behavior
 * including limits, caching, and ReDoS pattern exclusions.
 */
final readonly class RegexOptions
{
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
    ];

    /**
     * Create new configuration options.
     *
     * @param int            $maxPatternLength      Maximum allowed regex pattern length
     * @param int            $maxLookbehindLength   Maximum length of a variable-length lookbehind; a
     *                                              fixed-length one is only limited by PCRE's 65535
     * @param CacheInterface $cache                 Cache implementation to use
     * @param array<string>  $redosIgnoredPatterns  Patterns to ignore in ReDoS analysis
     * @param bool           $runtimePcreValidation Whether to validate against the PCRE runtime
     * @param int            $maxRecursionDepth     Maximum recursion depth during parsing
     * @param int            $phpVersionId          Target PHP_VERSION_ID for feature validation
     * @param bool           $phpVersionExplicit    Whether php_version was explicitly provided
     */
    public function __construct(
        public int $maxPatternLength,
        public int $maxLookbehindLength,
        public CacheInterface $cache,
        public array $redosIgnoredPatterns = [],
        public bool $runtimePcreValidation = false,
        public int $maxRecursionDepth = 1024,
        public int $phpVersionId = \PHP_VERSION_ID,
        public bool $phpVersionExplicit = false,
    ) {}

    /**
     * Create configuration from array of options.
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

        $phpVersionExplicit = \array_key_exists('php_version', $options);
        $maxLength = self::getPatternLength($options);
        $lookbehindLength = self::getLookbehindLength($options);
        $cache = self::createCache($options);
        $patterns = self::getIgnoredPatterns($options);
        $runtimeValidation = self::getRuntimePcreValidation($options);
        $recursionDepth = self::getRecursionDepth($options);
        $phpVersionId = self::getPhpVersionId($options);

        return new self($maxLength, $lookbehindLength, $cache, $patterns, $runtimeValidation, $recursionDepth, $phpVersionId, $phpVersionExplicit);
    }

    /**
     * Create default configuration with no custom options.
     *
     * @return self Default configuration instance
     */
    private static function createDefault(): self
    {
        return new self(
            Regex::DEFAULT_MAX_PATTERN_LENGTH,
            Regex::DEFAULT_MAX_LOOKBEHIND_LENGTH,
            new FilesystemCache(FilesystemCache::defaultDirectory()),
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
        $length = $options['max_pattern_length'] ?? Regex::DEFAULT_MAX_PATTERN_LENGTH;

        if (!\is_int($length) || $length <= 0) {
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
        $length = $options['max_lookbehind_length'] ?? Regex::DEFAULT_MAX_LOOKBEHIND_LENGTH;

        if (!\is_int($length) || $length < 0) {
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
        $depth = $options['max_recursion_depth'] ?? 1024;

        if (!\is_int($depth) || $depth <= 0) {
            throw new InvalidRegexOptionException(
                '"max_recursion_depth" must be a positive integer.',
            );
        }

        return $depth;
    }

    /**
     * Get target PHP version ID from options.
     *
     * @param array<string, mixed> $options Configuration options
     */
    private static function getPhpVersionId(array $options): int
    {
        $version = $options['php_version'] ?? null;

        if (null === $version) {
            return \PHP_VERSION_ID;
        }

        if (\is_int($version)) {
            if ($version <= 0) {
                throw new InvalidRegexOptionException(
                    '"php_version" must be a version string like "8.2" or a PHP_VERSION_ID integer.',
                );
            }

            return $version;
        }

        if (\is_string($version)) {
            $trimmed = trim($version);
            if ('' === $trimmed) {
                throw new InvalidRegexOptionException(
                    '"php_version" must be a version string like "8.2" or a PHP_VERSION_ID integer.',
                );
            }

            if (ctype_digit($trimmed)) {
                $asInt = (int) $trimmed;
                if ($asInt < 10000) {
                    throw new InvalidRegexOptionException(
                        '"php_version" must be a version string like "8.2" or a PHP_VERSION_ID integer.',
                    );
                }

                return $asInt;
            }

            if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $trimmed, $matches)) {
                $major = (int) $matches[1];
                $minor = isset($matches[2]) && \is_string($matches[2]) ? (int) $matches[2] : 0;
                $patch = isset($matches[3]) && \is_string($matches[3]) ? (int) $matches[3] : 0;

                return ($major * 10000) + ($minor * 100) + $patch;
            }
        }

        throw new InvalidRegexOptionException(
            '"php_version" must be a version string like "8.2" or a PHP_VERSION_ID integer.',
        );
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
        if (!\array_key_exists('cache', $options)) {
            $defaultPath = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'regex-parser'.\DIRECTORY_SEPARATOR.'cache';

            return new FilesystemCache($defaultPath);
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
        $trimmedPath = trim($path);

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
