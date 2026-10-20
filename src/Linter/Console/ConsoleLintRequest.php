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

namespace PHPRegex\Linter\Console;

use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSetup;
use PHPRegex\Optimizer\OptimizerOptions;

/**
 * A regex:lint run as a framework bridge reads it from its command line and
 * its configuration: everything ConsoleLintRunner needs, already mapped from
 * the framework's names.
 *
 * @phpstan-import-type LintResult from LintReport
 *
 * @internal
 */
final class ConsoleLintRequest
{
    /**
     * @param string                              $format              the format asked for, in any case
     * @param list<string>                        $paths               the paths to lint: as the command line gives them, or as
     *                                                                 the configuration names them, read from the project
     * @param string|null                         $pathsSetting        the setting the paths come from; null when the command
     *                                                                 line gives them
     * @param list<string>                        $exclude
     * @param string|null                         $excludeBase         the directory the excludes are read from; null reads
     *                                                                 them as the files are walked
     * @param string|null                         $jobs                --jobs as given; null when it is not, for one worker per
     *                                                                 CPU the run may use
     * @param list<string>                        $disabledSources     the pattern sources the run skips
     * @param list<string>                        $declarationPaths    where the functions marked #[RegexPattern] are read
     * @param list<string>                        $vendorPaths         the libraries read the same way
     * @param string|null                         $displayBase         the directory the reports name files from; null for the
     *                                                                 working directory
     * @param \Closure(): LintSetup               $setup               the target, the lint judging for it and the warnings of
     *                                                                 the configuration, resolved once the run starts
     * @param string                              $invalidOptionPrefix what a setting the setup cannot read is said after:
     *                                                                 where the settings live
     * @param string                              $version             the version the banner names
     * @param (\Closure(): list<LintResult>)|null $unread              the files the sources could not read, asked once the
     *                                                                 patterns are collected
     */
    public function __construct(
        public readonly string $format,
        public readonly array $paths,
        public readonly ?string $pathsSetting,
        public readonly array $exclude,
        public readonly ?string $excludeBase,
        public readonly int $minSavings,
        public readonly ?string $jobs,
        public readonly array $disabledSources,
        public readonly OptimizerOptions $optimizations,
        public readonly bool $checkRedos,
        public readonly bool $checkComplexity,
        public readonly bool $reportUnmatchedIgnores,
        public readonly array $declarationPaths,
        public readonly array $vendorPaths,
        public readonly ?string $editorUrl,
        public readonly ?string $displayBase,
        public readonly \Closure $setup,
        public readonly string $invalidOptionPrefix,
        public readonly string $version,
        public readonly ?\Closure $unread = null,
    ) {}
}
