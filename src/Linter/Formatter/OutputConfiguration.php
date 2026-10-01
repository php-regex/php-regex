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

namespace PHPRegex\Linter\Formatter;

/**
 * Configuration for output formatting options.
 */
final class OutputConfiguration
{
    public const VERBOSITY_QUIET = 'quiet';
    public const VERBOSITY_NORMAL = 'normal';
    public const VERBOSITY_VERBOSE = 'verbose';
    public const VERBOSITY_DEBUG = 'debug';

    /**
     * @param self::VERBOSITY_* $verbosity
     */
    public function __construct(
        public string $verbosity = self::VERBOSITY_NORMAL,
        public bool $ansi = true,
        public bool $showProgress = true,
        public bool $showOptimizations = true,
        public bool $showHints = true,
        public bool $groupByFile = true,
        public bool $sortBySeverity = true,
    ) {}

    /**
     * Create a configuration for quiet mode.
     */
    public static function quiet(): self
    {
        return new self(
            verbosity: self::VERBOSITY_QUIET,
            ansi: false,
            showProgress: false,
            showOptimizations: false,
            showHints: false,
        );
    }

    /**
     * Create a configuration for verbose mode.
     */
    public static function verbose(): self
    {
        return new self(
            verbosity: self::VERBOSITY_VERBOSE,
            showHints: true,
            showOptimizations: true,
        );
    }

    /**
     * Create a configuration for debug mode.
     */
    public static function debug(): self
    {
        return new self(
            verbosity: self::VERBOSITY_DEBUG,
            showHints: true,
            showOptimizations: true,
        );
    }

    /**
     * Check if hints should be shown based on verbosity level.
     */
    public function shouldShowHints(): bool
    {
        return $this->showHints && \in_array($this->verbosity, [
            self::VERBOSITY_NORMAL,
            self::VERBOSITY_VERBOSE,
            self::VERBOSITY_DEBUG,
        ], true);
    }

    /**
     * Check if detailed ReDoS analysis should be shown.
     */
    public function shouldShowDetailedReDoS(): bool
    {
        return \in_array($this->verbosity, [
            self::VERBOSITY_VERBOSE,
            self::VERBOSITY_DEBUG,
        ], true);
    }

    /**
     * Check if optimization suggestions should be shown.
     */
    public function shouldShowOptimizations(): bool
    {
        return $this->showOptimizations && \in_array($this->verbosity, [
            self::VERBOSITY_NORMAL,
            self::VERBOSITY_VERBOSE,
            self::VERBOSITY_DEBUG,
        ], true);
    }

    /**
     * Check if progress indicators should be shown.
     */
    public function shouldShowProgress(): bool
    {
        return $this->showProgress && self::VERBOSITY_QUIET !== $this->verbosity;
    }
}
