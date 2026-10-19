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
 * How an exception that shows the pattern is built: its snippet drawn
 * around the fault, the caret where the fault is shown.
 *
 * @internal
 */
trait PatternContextTrait
{
    use VisualContextTrait;

    /**
     * @internal the library throws its exceptions; catch them, read them
     *
     * @param int|null $snippetPosition where the caret stands in $pattern when that is not
     *                                  $position: a fault counted from the body is shown in
     *                                  the whole pattern
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
     * @internal the library throws its exceptions; catch them, read them
     *
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
