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

/**
 * @internal
 */
final readonly class LintParseResult
{
    /**
     * @param bool $pathsGiven whether the paths come from the command line, not from the configuration
     */
    public function __construct(
        public ?LintArguments $arguments,
        public ?string $error = null,
        public bool $help = false,
        public bool $pathsGiven = false,
    ) {}
}
