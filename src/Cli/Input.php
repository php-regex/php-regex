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

namespace PHPRegex\Cli;

/**
 * @internal
 */
final class Input
{
    /**
     * @param array<int, string>                                 $args
     * @param array{php_version?: string, pcre_version?: string} $regexOptions the versions the command line names
     */
    public function __construct(
        public readonly string $command,
        public readonly array $args,
        public readonly GlobalOptions $globalOptions,
        public readonly array $regexOptions,
    ) {}
}
