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
 * Raised when a pattern is outside what the automata read, or when a budget
 * ran out on the way to an answer: isLimitReached() tells the two apart, and
 * $phase and $limit name the budget.
 */
final class ComplexityException extends RegexException implements ExceptionInterface, VisualContextInterface
{
    use VisualContextTrait;

    /**
     * The step the solver was at when it gave up, as getDiagnostic() names
     * it ("determinization", "comparison", "match exploration"…); null when
     * the diagnostic names none, as for most constructs the automata do
     * not read.
     */
    public readonly ?string $phase;

    /**
     * The budget that ran out, in the unit of its phase (states, pairs of
     * states, units of work); null when the pattern is outside what the
     * automata read, which no larger budget answers.
     */
    public readonly ?int $limit;

    /**
     * @internal the library throws its exceptions; catch them, read them
     */
    public function __construct(
        string $message,
        ?int $offset = null,
        ?string $pattern = null,
        ?\Throwable $previous = null,
        ErrorCode $errorCode = ErrorCode::Complexity,
        /**
         * @var array<string, int|string>|null
         */
        private ?array $diagnostic = null,
    ) {
        $caretSnippet = $this->initializeContext($offset, $pattern);
        $phase = $diagnostic['phase'] ?? null;
        $this->phase = \is_string($phase) ? $phase : null;
        $limit = $diagnostic['limit'] ?? null;
        $this->limit = \is_int($limit) ? $limit : null;

        parent::__construct($message, $errorCode, $offset, $caretSnippet, $previous);
    }

    /**
     * Whether a budget ran out: a larger one in SolverOptions may answer,
     * where a pattern outside what the automata read is refused whatever
     * the budget.
     */
    public function isLimitReached(): bool
    {
        return null !== $this->limit;
    }

    /**
     * @return array<string, int|string>|null
     */
    public function getDiagnostic(): ?array
    {
        return $this->diagnostic;
    }
}
