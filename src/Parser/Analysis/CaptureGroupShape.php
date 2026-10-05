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
 * otherwise.
 */
final readonly class CaptureGroupShape
{
    /**
     * @internal built by CaptureShapeAnalyzer::analyze(), for CaptureShape::$whole and CaptureShape::$groups
     *
     * @param list<string>|null $values every string the group can hold, or null when they are not a small finite set
     */
    public function __construct(
        public int $number,
        public ?string $name,
        public Participation $participation,
        public int $minLength,
        public ?int $maxLength,
        public ?array $values,
    ) {}
}
