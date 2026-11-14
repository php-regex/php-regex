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

namespace PHPRegex\Parser\Printer;

/**
 * What formatting or compacting a pattern gave.
 */
final class FormatResult
{
    /**
     */
    public string $pattern;
    /**
     */
    public bool $changed;
    /**
     */
    public ?FormatRefusal $refusal = null;

    public function __construct(string $pattern, bool $changed, ?FormatRefusal $refusal = null)
    {
        /**
         * The pattern, delimiters and flags included: laid out, compacted,
         * or the input as it was written.
         */
        $this->pattern = $pattern;
        /**
         * Whether the pattern differs from the input.
         */
        $this->changed = $changed;
        /**
         * Why the input was given back as it was, when it was refused.
         */
        $this->refusal = $refusal;
    }
}
