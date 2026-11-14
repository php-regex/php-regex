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
final class SemanticErrorException extends RegexException implements ExceptionInterface, VisualContextInterface
{
    use PatternContextTrait {
        PatternContextTrait::__construct as private buildWithContext;
    }
    /**
     */
    public ?string $hint = null;

    /**
     * @internal the library throws its exceptions; catch them, read them
     *
     * @param int|null    $snippetPosition where the caret stands in $pattern when that is not
     *                                     $position: a fault counted from the body is shown in
     *                                     the whole pattern
     * @param string|null $hint            how to fix the pattern, when one way is clear
     */
    public function __construct(
        string $message,
        ErrorCode $errorCode,
        ?int $position = null,
        ?string $pattern = null,
        ?\Throwable $previous = null,
        ?int $snippetPosition = null,
        ?string $hint = null
    ) {
        $this->hint = $hint;
        $this->buildWithContext($message, $errorCode, $position, $pattern, $previous, $snippetPosition);
    }
}
