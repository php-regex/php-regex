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

namespace PhpRegex\Automata\Exception;

use PhpRegex\Parser\ErrorCode;
use PhpRegex\Parser\Exception\ExceptionInterface;
use PhpRegex\Parser\Exception\RegexException;
use PhpRegex\Parser\Exception\VisualContextTrait;

/**
 * Raised when a regex exceeds the supported regular subset for automata conversion.
 */
final class ComplexityException extends RegexException implements ExceptionInterface
{
    use VisualContextTrait;

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
        $this->initializeContext($position, $pattern);

        parent::__construct($message, $errorCode, $position, $this->getVisualSnippet(), $previous);
    }

    /**
     * @return array<string, int|string>|null
     */
    public function getDiagnostic(): ?array
    {
        return $this->diagnostic;
    }
}
