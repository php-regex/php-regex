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
 * Represents an error that occurred during the parsing phase.
 *
 * @see \PHPRegex\Parser\Syntax\TokenParser
 *
 * @phpstan-consistent-constructor
 */
class ParserException extends RegexException implements ExceptionInterface, VisualContextInterface
{
    use PatternContextTrait;
}
