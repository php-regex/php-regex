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

/**
 * What one capturing group holds after a successful match, or the whole
 * match when its number is 0.
 *
 * Lengths count the characters PCRE reads: code points in UTF mode, bytes
 * otherwise. The lengths, the values and the facts describe the group when
 * it is set; what an unset group reads ('' or null) is the participation's
 * business. A fact is true only when every match proves it, false when the
 * pattern alone does not, and false for a group no match sets.
 */
final readonly class CaptureGroupShape
{
    /**
     * @internal built by CaptureShapeAnalyzer::analyze(), for CaptureShape::$whole and CaptureShape::$groups
     *
     * @param list<string>|null $values     every string the group can hold, or null when they are not a small finite set
     * @param bool              $nonFalsy   every value is truthy: neither '' nor '0'
     * @param bool              $digitsOnly every value satisfies ctype_digit(): a non-empty run of ASCII 0-9
     */
    public function __construct(
        public int $number,
        public ?string $name,
        public Participation $participation,
        public int $minLength,
        public ?int $maxLength,
        public ?array $values,
        public bool $nonFalsy = false,
        public bool $digitsOnly = false,
    ) {}
}
