<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser\Exception;

/**
 * Indicates that parsing was halted due to excessive recursion depth.
 *
 * @see \PhpRegex\Parser\Syntax\TokenParser
 */
final class RecursionLimitException extends ParserException implements ExceptionInterface {}
