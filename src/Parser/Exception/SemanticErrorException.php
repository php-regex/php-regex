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
 * Represents a semantic validation error in a regex pattern.
 */
final class SemanticErrorException extends RegexException implements ExceptionInterface
{
    use VisualContextTrait;

    public function __construct(
        string $message,
        ErrorCode $errorCode,
        ?int $position = null,
        ?string $pattern = null,
        ?\Throwable $previous = null,
        public readonly ?string $hint = null,
    ) {
        $this->initializeContext($position, $pattern);

        parent::__construct($message, $errorCode, $position, $this->getVisualSnippet(), $previous);
    }

    public function getHint(): ?string
    {
        return $this->hint;
    }
}
