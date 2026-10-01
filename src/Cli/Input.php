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

namespace PhpRegex\Cli;

final readonly class Input
{
    /**
     * @param array<int, string>   $args
     * @param array<string, mixed> $regexOptions
     */
    public function __construct(
        public string $command,
        public array $args,
        public GlobalOptions $globalOptions,
        public array $regexOptions,
    ) {}
}
