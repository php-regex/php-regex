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

namespace PHPRegex\Parser\Resolution;

/**
 * A part of a pattern argument nobody wrote: a text known at run time only.
 *
 * A quoted hole is a preg_quote() call, whose text is read as literal
 * characters wherever the pattern reads literals. A hole that is not quoted
 * is raw: its text may be any pattern syntax.
 *
 * @internal
 */
final class Hole
{
    /**
     * @param bool    $quoted    the text is what preg_quote() returns
     * @param ?string $delimiter the second argument of preg_quote() when it is a string literal, null when it is absent or not a literal
     */
    public function __construct(public readonly bool $quoted, public readonly ?string $delimiter) {}
}
