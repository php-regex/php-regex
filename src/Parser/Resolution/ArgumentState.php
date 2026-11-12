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
 * What is known of a pattern argument before any text reaches it, from the
 * most to the least: every argument is in exactly one of these.
 *
 * @internal
 */
enum ArgumentState: string
{
    /**
     * One pattern, whatever path the code takes.
     */
    case Constant = 'constant';

    /**
     * One of several patterns, each known.
     */
    case FiniteSet = 'finite_set';

    /**
     * A static pattern around texts preg_quote() returns.
     */
    case QuotedShell = 'quoted_shell';

    /**
     * A static pattern around a part built at run time that is not quoted.
     */
    case RawShell = 'raw_shell';

    /**
     * Nothing static: not even the delimiter is known.
     */
    case Opaque = 'opaque';
}
