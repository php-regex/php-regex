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

namespace PHPRegex\Linter;

use PHPRegex\Parser\Json\JsonDocument;

/**
 * The steps of a lint run every front end takes alike, `regex lint` and
 * the bridges' regex:lint: each step that cannot go on stops the run with
 * a LintRunFailure naming its stage and its exit code.
 *
 * @internal
 */
final class LintRunner
{
    /**
     * Every path the run lints exists: a path that does not is a usage
     * error when the command line names it, a configuration error when a
     * setting does, never a run that finds nothing and passes.
     *
     * @param array<string> $paths
     * @param string|null   $setting what names the paths when they come from the configuration
     *
     * @throws LintRunFailure
     */
    public static function checkPaths(array $paths, ?string $setting = null): void
    {
        foreach ($paths as $path) {
            if ('' === $path || file_exists($path)) {
                continue;
            }

            throw null === $setting
                ? new LintRunFailure('Path not found: '.$path, JsonDocument::STAGE_USAGE, LintRunFailure::EXIT_INVALID)
                : new LintRunFailure(\sprintf('Path not found: %s (from %s)', $path, $setting), JsonDocument::STAGE_CONFIG, LintRunFailure::EXIT_INVALID);
        }
    }
}
