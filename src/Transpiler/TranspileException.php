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

namespace PHPRegex\Transpiler;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Exception\VisualContextTrait;

/**
 * Raised when a regex cannot be safely transpiled to a target dialect.
 */
final class TranspileException extends RegexException implements ExceptionInterface
{
    use VisualContextTrait;

    public function __construct(
        string $message,
        ?int $position = null,
        ?string $pattern = null,
        ?\Throwable $previous = null,
        ErrorCode $errorCode = ErrorCode::TranspileUnsupported,
    ) {
        $this->initializeContext($position, $pattern);

        parent::__construct($message, $errorCode, $position, $this->getVisualSnippet(), $previous);
    }
}
