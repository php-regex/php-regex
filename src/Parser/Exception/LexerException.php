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
 * Represents an error that occurred during the lexical analysis phase.
 *
 * @see \PHPRegex\Parser\Lexer
 */
final class LexerException extends RegexException implements ExceptionInterface
{
    use VisualContextTrait;

    public function __construct(
        string $message,
        ErrorCode $errorCode,
        ?int $position = null,
        ?string $pattern = null,
        ?\Throwable $previous = null,
    ) {
        $this->initializeContext($position, $pattern);

        parent::__construct($message, $errorCode, $position, $this->getVisualSnippet(), $previous);
    }

    public static function withContext(
        string $message,
        ErrorCode $errorCode,
        int $position,
        string $pattern,
        ?\Throwable $previous = null,
    ): self {
        return new self($message, $errorCode, $position, $pattern, $previous);
    }
}
