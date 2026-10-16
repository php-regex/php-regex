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
final class ParsedGlobalOptions
{
    /**
     * @param array<int, string> $args
     */
    public function __construct(public readonly GlobalOptions $options, public readonly array $args) {}
}
