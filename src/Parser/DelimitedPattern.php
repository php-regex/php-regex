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

use PHPRegex\Parser\Internal\PatternParser;

/**
 * Represents a regex pattern with its components.
 */
final readonly class DelimitedPattern implements \Stringable
{
    public function __construct(
        public string $pattern,
        public string $flags = '',
        public string $delimiter = '/',
    ) {}

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Create from a delimited regex string like '/foo/i'.
     */
    public static function fromDelimited(string $regex, ?PcreTarget $target = null): self
    {
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags($regex, $target);

        return new self($pattern, $flags, $delimiter);
    }

    /**
     * Create from raw pattern, flags, and optional delimiter.
     */
    public static function fromRaw(string $pattern, string $flags = '', string $delimiter = '/'): self
    {
        return new self($pattern, $flags, $delimiter);
    }

    /**
     * Get the full delimited regex string.
     */
    public function toString(): string
    {
        $closingDelimiter = PatternParser::closingDelimiter($this->delimiter);

        return $this->delimiter.$this->pattern.$closingDelimiter.$this->flags;
    }
}
