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

namespace PHPRegex\Parser;

use PHPRegex\Parser\Syntax\Delimiters;
use PHPRegex\Parser\Syntax\PatternParser;

/**
 * Represents a regex pattern with its components.
 */
final class DelimitedPattern
{
    /**
     */
    public string $pattern;
    /**
     */
    public string $flags = '';
    /**
     */
    public string $delimiter = '/';

    public function __construct(string $pattern, string $flags = '', string $delimiter = '/')
    {
        $this->pattern = $pattern;
        $this->flags = $flags;
        $this->delimiter = $delimiter;
    }

    /**
     * The full delimited regex, as "/foo/i".
     */
    public function __toString(): string
    {
        $closingDelimiter = Delimiters::closing($this->delimiter);

        return $this->delimiter.$this->pattern.$closingDelimiter.$this->flags;
    }

    /**
     * Create from a delimited regex string like '/foo/i'.
     */
    public static function fromDelimited(string $regex, ?PcreTarget $target = null): self
    {
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags($regex, $target);

        return new self($pattern, $flags, $delimiter);
    }
}
