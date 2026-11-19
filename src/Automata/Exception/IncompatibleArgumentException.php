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

namespace PHPRegex\Automata\Exception;

use PHPRegex\Parser\Exception\ExceptionInterface;

/**
 * Raised when arguments cannot be read together: a language read as UTF-8
 * and one read as bytes, tuples of different lengths, a separator a
 * pattern may match. It is an \InvalidArgumentException, and one
 * ExceptionInterface catch holds it with the library's other exceptions.
 */
final class IncompatibleArgumentException extends \InvalidArgumentException implements ExceptionInterface {}
