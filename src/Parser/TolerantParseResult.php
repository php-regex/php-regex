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

use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Node\RegexNode;

/**
 * Outcome of a tolerant parse attempt.
 */
final readonly class TolerantParseResult
{
    /**
     * @internal built by RegexParser::parseTolerant() and Regex::parseTolerant()
     *
     * @param list<RegexException> $errors what a strict parse would have thrown, the first error PCRE meets
     */
    public function __construct(public RegexNode $ast, public array $errors = []) {}

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}
