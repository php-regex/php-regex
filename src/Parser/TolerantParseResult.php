<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser;

use PhpRegex\Parser\Node\RegexNode;

/**
 * Outcome of a tolerant parse attempt.
 */
final readonly class TolerantParseResult
{
    /**
     * @param array<\Throwable> $errors
     */
    public function __construct(public RegexNode $ast, public array $errors = []) {}

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}
