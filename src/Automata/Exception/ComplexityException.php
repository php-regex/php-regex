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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Exception\VisualContextInterface;
use PHPRegex\Parser\Exception\VisualContextTrait;

/**
 * Raised when a regex exceeds the supported regular subset for automata conversion.
 */
final class ComplexityException extends RegexException implements ExceptionInterface, VisualContextInterface
{
    use VisualContextTrait;

    /**
     * @internal the library throws its exceptions; catch them, read them
     */
    public function __construct(
        string $message,
        ?int $position = null,
        ?string $pattern = null,
        ?\Throwable $previous = null,
        ErrorCode $errorCode = ErrorCode::Complexity,
        /**
         * @var array<string, int|string>|null
         */
        private ?array $diagnostic = null,
    ) {
        $snippet = $this->initializeContext($position, $pattern);

        parent::__construct($message, $errorCode, $position, $snippet, $previous);
    }

    /**
     * @return array<string, int|string>|null
     */
    public function getDiagnostic(): ?array
    {
        return $this->diagnostic;
    }
}
