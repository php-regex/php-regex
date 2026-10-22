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

class AssertionKind
{
    public const SubjectStart = 'subjectstart';
    public const LineStart = 'linestart';
    public const SubjectEnd = 'subjectend';
    public const EndOrFinalNewline = 'endorfinalnewline';
    public const LineEnd = 'lineend';
    public const MatchStart = 'matchstart';
    public const WordBoundary = 'wordboundary';
    public const NotWordBoundary = 'notwordboundary';
    public const ResetMatchStart = 'resetmatchstart';
}
