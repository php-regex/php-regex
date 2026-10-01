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

namespace PhpRegex\Parser\Exception;

/**
 * Represents a specific type of parsing error related to invalid syntax.
 *
 * @see \PhpRegex\Parser\Syntax\TokenParser
 */
final class SyntaxErrorException extends ParserException implements ExceptionInterface {}
