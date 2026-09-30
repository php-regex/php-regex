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

use RegexParser\Cache\ArrayCache;
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
        'pcre_version',
    ];

    /**
     * The PHP version and the PCRE2 release patterns are judged for.
     */
    public PcreTarget $target;

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
     * @param PcreTarget|null $target                The PHP and PCRE2 judged; the running ones when null
     */
    public function __construct(
        public int $maxPatternLength,
        public int $maxLookbehindLength,
        public CacheInterface $cache,
        public array $redosIgnoredPatterns = [],
        public bool $runtimePcreValidation = false,
        public int $maxRecursionDepth = RegexParser::DEFAULT_MAX_RECURSION_DEPTH,
        ?PcreTarget $target = null,
    ) {
        $this->target = $target ?? PcreTarget::runtime();

        // PHP compiles with the engine that runs: it judges the target only
        // when the target is that engine.
        if ($this->runtimePcreValidation && !$this->target->isRunningEngine()) {
            throw new InvalidRegexOptionException(\sprintf(
                '"runtime_pcre_validation" compiles with the running PHP, which does not judge the target (PHP %d.%d, PCRE2 %s); drop one of them.',
                intdiv($this->target->phpVersionId, 10000),
                intdiv($this->target->phpVersionId, 100) % 100,
                $this->target->pcreVersion,
            ));
        }
    }

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
     * Create default configuration with no custom options.
     *
     * @return self Default configuration instance
     */
    private static function createDefault(): self
    {
        return new self(
            RegexParser::DEFAULT_MAX_PATTERN_LENGTH,
            RegexParser::DEFAULT_MAX_LOOKBEHIND_LENGTH,
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
        $length = $options['max_pattern_length'] ?? RegexParser::DEFAULT_MAX_PATTERN_LENGTH;

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
        $length = $options['max_lookbehind_length'] ?? RegexParser::DEFAULT_MAX_LOOKBEHIND_LENGTH;

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
        $depth = $options['max_recursion_depth'] ?? RegexParser::DEFAULT_MAX_RECURSION_DEPTH;

        if (!\is_int($depth) || $depth <= 0) {
            throw new InvalidRegexOptionException(
                '"max_recursion_depth" must be a positive integer.',
            );
        }

        return $depth;
    }

    /**
     * The PHP and the PCRE2 judged: the running ones by default, a PHP
     * version alone with the PCRE2 it bundles, a PCRE2 release alone with
     * the running PHP.
     *
     * @param array<string, mixed> $options Configuration options
     */
    private static function getTarget(array $options): PcreTarget
    {
        $phpVersionId = self::getPhpVersionId($options);
        $pcreVersion = $options['pcre_version'] ?? null;

        if (null !== $pcreVersion && !\is_string($pcreVersion)) {
            throw new InvalidRegexOptionException(\sprintf('"pcre_version" must be a PCRE2 release like "10.44", not a %s.', get_debug_type($pcreVersion)));
        }

        return match (true) {
            null === $phpVersionId && null === $pcreVersion => PcreTarget::runtime(),
            null === $pcreVersion => PcreTarget::bundledWith((int) $phpVersionId),
            default => new PcreTarget($phpVersionId ?? PcreTarget::runtime()->phpVersionId, $pcreVersion),
        };
    }

    /**
     * Get target PHP version ID from options.
     *
     * @param array<string, mixed> $options Configuration options
     */
    private static function getPhpVersionId(array $options): ?int
    {
        $version = $options['php_version'] ?? null;

        if (null === $version) {
            return null;
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
