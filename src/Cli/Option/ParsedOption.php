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

namespace PHPRegex\Cli\Option;

/**
 * One option the command line gave, under the name its declaration gives
 * it whichever spelling was used.
 *
 * @internal
 */
final class ParsedOption
{
    /**
     * @param ?string $value    null for a flag
     * @param bool    $attached whether the value came after "=", not after a space
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $value,
        public readonly bool $attached,
    ) {}
}
