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

namespace PHPRegex\Cli\Command;

use PHPRegex\Linter\Config\LintArguments;

/**
 * @internal
 */
final class LintParseResult
{
    /**
     * @param bool         $pathsGiven whether the paths come from the command line, not from the configuration
     * @param list<string> $warnings   what the command line holds that is ignored, the run going on
     */
    public function __construct(
        public readonly ?LintArguments $arguments,
        public readonly ?string $error = null,
        public readonly bool $help = false,
        public readonly bool $pathsGiven = false,
        public readonly array $warnings = [],
    ) {}
}
