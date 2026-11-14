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

namespace PHPRegex\Parser\Exception;

/**
 * The library stopped reading the pattern at one of its own limits (pattern
 * length, nesting depth): a verdict it could not reach, never a grammar
 * error. It sits beside ParserException, not under it, so a handler for
 * invalid patterns does not take an analysis that gave up for one.
 *
 * @phpstan-consistent-constructor
 */
class LimitException extends RegexException implements ExceptionInterface, VisualContextInterface
{
    use PatternContextTrait;
}
