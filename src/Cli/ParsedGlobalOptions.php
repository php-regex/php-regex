<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Cli;

final readonly class ParsedGlobalOptions
{
    /**
     * @param array<int, string> $args
     */
    public function __construct(public GlobalOptions $options, public array $args) {}
}
