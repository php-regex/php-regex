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
 * A malformed pattern met while reading its tokens: a class left open, a
 * property or an escape cut short. It is a ParserException, so one catch
 * holds every pattern parse() refuses as malformed, whichever part of the
 * parser met the fault.
 *
 * @see \PHPRegex\Parser\Lexer
 */
final class LexerException extends ParserException {}
