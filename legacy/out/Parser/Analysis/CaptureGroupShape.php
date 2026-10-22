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
final class CaptureGroupShape
{
    /**
     * @readonly
     * @var int
     */
    public $number;
    /**
     * @readonly
     * @var string|null
     */
    public $name;
    /**
     * @readonly
     * @var \PHPRegex\Parser\Analysis\Participation
     */
    public $participation;
    /**
     * @readonly
     * @var int
     */
    public $minLength;
    /**
     * @readonly
     * @var int|null
     */
    public $maxLength;
    /**
     * @var list<string>|null
     * @readonly
     */
    public $values;
    /**
     * @var bool
     * @readonly
     */
    public $nonFalsy = false;
    /**
     * @var bool
     * @readonly
     */
    public $digitsOnly = false;
    /**
     * @internal built by CaptureShapeAnalyzer::analyze(), for CaptureShape::$whole and CaptureShape::$groups
     *
     * @param list<string>|null $values     every string the group can hold, or null when they are not a small finite set
     * @param bool              $nonFalsy   every value is truthy: neither '' nor '0'
     * @param bool              $digitsOnly every value satisfies ctype_digit(): a non-empty run of ASCII 0-9
     * @param mixed $participation
     */
    public function __construct(int $number, ?string $name, $participation, int $minLength, ?int $maxLength, ?array $values, bool $nonFalsy = false, bool $digitsOnly = false)
    {
        $this->number = $number;
        $this->name = $name;
        $this->participation = $participation;
        $this->minLength = $minLength;
        $this->maxLength = $maxLength;
        $this->values = $values;
        $this->nonFalsy = $nonFalsy;
        $this->digitsOnly = $digitsOnly;
    }
}
