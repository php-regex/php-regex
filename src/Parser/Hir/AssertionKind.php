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

namespace PHPRegex\Parser\Hir;

/**
 * A zero-width condition, with the options that change its meaning already
 * applied: "^" is SubjectStart, or LineStart under /m; "$" is
 * EndOrFinalNewline, LineEnd under /m, or SubjectEnd under /D.
 *
 * @internal
 */
enum AssertionKind
{
    /**
     * "\A", or "^" without /m.
     */
    case SubjectStart;

    /**
     * "^" under /m.
     */
    case LineStart;

    /**
     * "\z", or "$" under /D.
     */
    case SubjectEnd;

    /**
     * "\Z", or "$" without /m nor /D: the end, or a newline that ends the subject.
     */
    case EndOrFinalNewline;

    /**
     * "$" under /m.
     */
    case LineEnd;

    /**
     * "\G": where the previous match ended.
     */
    case MatchStart;

    case WordBoundary;

    case NotWordBoundary;

    /**
     * "\K": the reported match starts here; the text read before stays read.
     */
    case ResetMatchStart;
}
