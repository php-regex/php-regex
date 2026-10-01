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

use PhpRegex\Parser\ErrorCode;

/**
 * Represents an error that occurred during the parsing phase.
 *
 * @see \PhpRegex\Parser\Syntax\TokenParser
 *
 * @phpstan-consistent-constructor
 */
class ParserException extends RegexException implements ExceptionInterface
{
    use VisualContextTrait;

    /**
     * @param int|null $snippetPosition where the caret stands in $pattern when that is not
     *                                  $position: a fault outside the body is counted from the
     *                                  body but shown in the whole pattern
     */
    public function __construct(
        string $message,
        ErrorCode $errorCode,
        ?int $position = null,
        ?string $pattern = null,
        ?\Throwable $previous = null,
        ?int $snippetPosition = null,
    ) {
        $this->initializeContext($snippetPosition ?? $position, $pattern);

        parent::__construct($message, $errorCode, $position, $this->getVisualSnippet(), $previous);
    }

    /**
     * @param int|null $snippetPosition where the caret stands in $pattern when that is not $position
     */
    public static function withContext(
        string $message,
        ErrorCode $errorCode,
        int $position,
        string $pattern,
        ?\Throwable $previous = null,
        ?int $snippetPosition = null,
    ): static {
        return new static($message, $errorCode, $position, $pattern, $previous, $snippetPosition);
    }
}
