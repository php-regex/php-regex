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

use PHPRegex\Parser\ErrorCode;

/**
 * Thrown when the formatter wrote a pattern that does not read back as the
 * one it was given. The formatter checks its own output and throws rather
 * than return a pattern that could match differently: this is a bug in the
 * library, not a fault of the pattern, worth reporting with the pattern.
 * It is no RegexException: nothing that judges a pattern catches it.
 *
 * @see \PHPRegex\Parser\Printer\PatternFormatter
 */
final class FormattingException extends \RuntimeException implements ExceptionInterface
{
    /**
     * Always ErrorCode::FormattingUnsafe.
     */
    public readonly ErrorCode $errorCode;

    /**
     * @internal built by PatternFormatter
     *
     * @param string $pattern the pattern given to the formatter, as written
     */
    public function __construct(
        string $message,
        public readonly string $pattern,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = ErrorCode::FormattingUnsafe;
    }
}
