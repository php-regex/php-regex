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
     * @param int|null $caretOffset where the caret stands in $pattern when that is not
     *                              $offset: a fault counted from the body is shown in
     *                              the whole pattern
     */
    public function __construct(
        string $message,
        ErrorCode $errorCode,
        ?int $offset = null,
        ?string $pattern = null,
        ?\Throwable $previous = null,
        ?int $caretOffset = null
    ) {
        $caretSnippet = $this->initializeContext($caretOffset ?? $offset, $pattern);

        parent::__construct($message, $errorCode, $offset, $caretSnippet, $previous);
    }

    /**
     * @internal the library throws its exceptions; catch them, read them
     *
     * @param int|null $caretOffset where the caret stands in $pattern when that is not $offset
     *
     * @return static
     */
    public static function withContext(
        string $message,
        ErrorCode $errorCode,
        int $offset,
        string $pattern,
        ?\Throwable $previous = null,
        ?int $caretOffset = null
    ) {
        return new static($message, $errorCode, $offset, $pattern, $previous, $caretOffset);
    }
}
