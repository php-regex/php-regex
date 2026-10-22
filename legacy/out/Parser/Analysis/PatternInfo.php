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

namespace PHPRegex\Parser\Analysis;

use PHPRegex\Parser\BsrConvention;
use PHPRegex\Parser\NewlineConvention;

/**
 * Facts about a pattern, read from its tree: what PCRE2 reports on a
 * compiled pattern, and the length and anchoring of what it matches.
 *
 * Exact facts equal PCRE2's: $captureCount, $names, $maxBackreference,
 * $usesBackslashC, the three limits, $newline and $bsr. The others are
 * sound bounds: every match holds within $minMatchLength and
 * $maxMatchLength, and an anchor is true only when proven.
 */
final class PatternInfo
{
    /**
     * @var int
     * @readonly
     */
    public $captureCount;
    /**
     * @var array<string, list<int>>
     * @readonly
     */
    public $names;
    /**
     * @var int
     * @readonly
     */
    public $maxBackreference;
    /**
     * @var int
     * @readonly
     */
    public $minMatchLength;
    /**
     * @var int|null
     * @readonly
     */
    public $maxMatchLength;
    /**
     * @var int
     * @readonly
     */
    public $maxLookbehind;
    /**
     * @var bool
     * @readonly
     */
    public $usesBackslashC;
    /**
     * @var int|null
     * @readonly
     */
    public $matchLimit;
    /**
     * @var int|null
     * @readonly
     */
    public $depthLimit;
    /**
     * @var int|null
     * @readonly
     */
    public $heapLimit;
    /**
     * @var NewlineConvention|null
     * @readonly
     */
    public $newline;
    /**
     * @var BsrConvention|null
     * @readonly
     */
    public $bsr;
    /**
     * @var bool
     * @readonly
     */
    public $anchoredStart;
    /**
     * @var bool
     * @readonly
     */
    public $anchoredEnd;
    /**
     * @internal built by PatternInfoAnalyzer::analyze()
     *
     * @param int                      $captureCount     the number of capturing groups, as a branch reset counts them (PCRE2 CAPTURECOUNT)
     * @param array<string, list<int>> $names            each group name, sorted, with its group numbers, ascending (PCRE2 NAMETABLE)
     * @param int                      $maxBackreference the highest group a back reference, a named one or a condition can name, 0 for none (PCRE2 BACKREFMAX)
     * @param int                      $minMatchLength   the fewest characters (UTF mode) or bytes $matches[0] holds; 0 when the pattern holds "\K"
     * @param int|null                 $maxMatchLength   the most $matches[0] holds, null when unbounded
     * @param int                      $maxLookbehind    the longest lookbehind body, in characters (UTF mode) or bytes, 0 for none
     * @param bool                     $usesBackslashC   whether the pattern holds "\C" (PCRE2 HASBACKSLASHC)
     * @param int|null                 $matchLimit       the "(*LIMIT_MATCH=)" the pattern asks for, the last one
     * @param int|null                 $depthLimit       the "(*LIMIT_DEPTH=)" or "(*LIMIT_RECURSION=)" it asks for, the last one
     * @param int|null                 $heapLimit        the "(*LIMIT_HEAP=)" it asks for, the last one
     * @param NewlineConvention|null   $newline          the newline convention it sets, null when it sets none
     * @param BsrConvention|null       $bsr              what it makes "\R" match, null when it does not say
     * @param bool                     $anchoredStart    proven: every match attempt is tied to where the search starts
     * @param bool                     $anchoredEnd      proven: every match ends at the end of the subject
     * @param ?\PHPRegex\Parser\NewlineConvention::* $newline
     * @param ?\PHPRegex\Parser\BsrConvention::* $bsr
     */
    public function __construct(int $captureCount, array $names, int $maxBackreference, int $minMatchLength, ?int $maxMatchLength, int $maxLookbehind, bool $usesBackslashC, ?int $matchLimit, ?int $depthLimit, ?int $heapLimit, ?PHPRegex\Parser\NewlineConvention $newline, ?PHPRegex\Parser\NewlineConvention $bsr, bool $anchoredStart, bool $anchoredEnd)
    {
        $this->captureCount = $captureCount;
        $this->names = $names;
        $this->maxBackreference = $maxBackreference;
        $this->minMatchLength = $minMatchLength;
        $this->maxMatchLength = $maxMatchLength;
        $this->maxLookbehind = $maxLookbehind;
        $this->usesBackslashC = $usesBackslashC;
        $this->matchLimit = $matchLimit;
        $this->depthLimit = $depthLimit;
        $this->heapLimit = $heapLimit;
        $this->newline = $newline;
        $this->bsr = $bsr;
        $this->anchoredStart = $anchoredStart;
        $this->anchoredEnd = $anchoredEnd;
    }
}
