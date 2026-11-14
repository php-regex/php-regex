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
     */
    public int $number;
    /**
     */
    public ?string $name;
    /**
     */
    public Participation $participation;
    /**
     */
    public int $minLength;
    /**
     */
    public ?int $maxLength;
    /**
     * @var list<string>|null
     *
     */
    public ?array $values;
    /**
     */
    public bool $nonFalsy = false;
    /**
     */
    public bool $digitsOnly = false;
    /**
     */
    public bool $decimalInt = false;
    /**
     */
    public bool $lowercase = false;
    /**
     */
    public bool $uppercase = false;
    /**
     * @var list<self>
     *
     */
    public array $arms = [];

    /**
     * @internal built by CaptureShapeAnalyzer::analyze(), for CaptureShape::$whole and CaptureShape::$groups
     *
     * @param list<string>|null $values     every string the group can hold, or null when they are not a small finite set. An alternation that mixes enumerable and non-enumerable arms - (a|\d) - reads as null: the digits arm holds infinitely many strings, and the scalar facts below carry only what holds for every arm together; $arms keeps each arm
     * @param bool              $nonFalsy   every value is truthy: neither '' nor '0'
     * @param bool              $digitsOnly every value satisfies ctype_digit(): a non-empty run of ASCII 0-9
     * @param bool              $decimalInt every value satisfies (string) (int) $v === $v, PHPStan's decimal-int-string: '0', or up to 18 digits after an optional '-', the first of them not '0', so no '007', '-0', '+1' or value past PHP_INT_MAX
     * @param bool              $lowercase  every value satisfies strtolower($v) === $v, PHPStan's lowercase-string: no byte A-Z. strtolower() folds ASCII alone, so 'É' is lowercase too
     * @param bool              $uppercase  every value satisfies strtoupper($v) === $v, PHPStan's uppercase-string: no byte a-z. strtoupper() folds ASCII alone, so 'é' is uppercase too
     * @param list<self>        $arms       the shape of each way the group is set - each branch of the alternation it reads, each group of a branch reset bearing its number - when their join loses what they say: (a|\d) holds 'a' or digits. Empty when the facts above say it all. An arm bears the number, the name and the participation of the group
     */
    public function __construct(int $number, ?string $name, Participation $participation, int $minLength, ?int $maxLength, ?array $values, bool $nonFalsy = false, bool $digitsOnly = false, bool $decimalInt = false, bool $lowercase = false, bool $uppercase = false, array $arms = [])
    {
        $this->number = $number;
        $this->name = $name;
        $this->participation = $participation;
        $this->minLength = $minLength;
        $this->maxLength = $maxLength;
        $this->values = $values;
        $this->nonFalsy = $nonFalsy;
        $this->digitsOnly = $digitsOnly;
        $this->decimalInt = $decimalInt;
        $this->lowercase = $lowercase;
        $this->uppercase = $uppercase;
        $this->arms = $arms;
    }
}
