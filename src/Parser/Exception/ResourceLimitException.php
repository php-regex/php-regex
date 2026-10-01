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
 * Indicates that parsing was halted because a resource limit was exceeded.
 *
 * @see \PHPRegex\Parser\Syntax\TokenParser
 */
final class ResourceLimitException extends ParserException implements ExceptionInterface {}
