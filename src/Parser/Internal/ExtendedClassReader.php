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

namespace PHPRegex\Parser\Internal;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RecursionLimitException;
use PHPRegex\Parser\Exception\SyntaxErrorException;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ClassSetOperator;
use PHPRegex\Parser\Node\NodeInterface;

/**
 * Reads the expression of a Perl extended class "(?[...])", PCRE2 10.45,
 * from the pattern text: operands (a nested class, a POSIX class, an escape,
 * a parenthesised expression) and set operators, "!" binding tightest, then
 * "&", then "+", "|", "-" and "^" left to right. Spaces and tabs are skipped.
 * PCRE refuses what it cannot take where it reads it; the offsets are
 * pcre2test's.
 *
 * @internal
 */
final class ExtendedClassReader
{
    /**
     * The deepest nesting PCRE2 takes, "(?[" counting as the first level and
     * each "(" or nested "[" as one more.
     */
    private const MAX_DEPTH = 15;

    private const OPERATORS = [
        '+' => ClassSetOperator::Union,
        '|' => ClassSetOperator::Union,
        '-' => ClassSetOperator::Difference,
        '^' => ClassSetOperator::SymmetricDifference,
    ];

    private int $at = 0;

    private int $depth = 1;

    private int $parentheses = 0;

    /**
     * @var list<NodeInterface> the operands read so far, which PCRE judges as it reads them
     */
    private array $operands = [];

    private int $operations = 0;

    private readonly int $length;

    /**
     * @param \Closure(string, int):NodeInterface                                      $escape        reads an escape written at an offset
     * @param \Closure(string, int):NodeInterface                                      $class         reads a nested class written at an offset
     * @param \Closure(LexerException|ParserException, int, list<NodeInterface>):never $fail          refuses
     *                                                                                                the pattern at an offset, or for an error in an
     *                                                                                                operand, given the operands read before
     * @param bool                                                                     $pastTheFault  whether the nesting limit is reported
     *                                                                                                on the character, as PCRE2 10.47 does
     * @param bool                                                                     $utf           whether the pattern is read by UTF-8 character
     * @param int                                                                      $maxOperations the most operations read, so the tree stays
     *                                                                                                one the visitors can walk
     * @param \Closure(int, int): void|null                                            $nest          told where each "(" is read and how
     *                                                                                                many are open with it, for a limit on
     *                                                                                                nesting other than PCRE's
     */
    public function __construct(
        private readonly string $pattern,
        private readonly \Closure $escape,
        private readonly \Closure $class,
        private readonly \Closure $fail,
        private readonly bool $pastTheFault,
        private readonly bool $utf = false,
        private readonly int $maxOperations = 1024,
        private readonly ?\Closure $nest = null,
    ) {
        $this->length = \strlen($pattern);
    }

    /**
     * @param int $start the offset of the "(" of "(?["
     *
     * @return array{0: NodeInterface, 1: int} the expression, and the offset past the "])"
     */
    public function read(int $start): array
    {
        $this->at = $start + 3;
        $this->depth = 1;
        $this->parentheses = 0;
        $this->operands = [];
        $this->operations = 0;
        $expression = $this->readUnion();

        $this->skipBlanks();
        $char = $this->pattern[$this->at] ?? '';
        if (')' === $char) {
            $this->refuse('Unmatched ")" in an extended class', ErrorCode::ExtendedClassUnmatchedClose, $this->at + 1);
        }

        if (']' !== $char) {
            $this->refuse('Missing "]" to close an extended class', ErrorCode::ExtendedClassUnclosed, $this->length);
        }

        $this->at++;
        if (')' !== ($this->pattern[$this->at] ?? '')) {
            $this->refuse('The "]" of an extended class must be followed by ")"', ErrorCode::ExtendedClassBracketWithoutParen, $this->at);
        }

        return [$expression, $this->at + 1];
    }

    /**
     * Past the "]" of the class, or POSIX class, whose "[" is at $start; the
     * end of the pattern when it never closes.
     */
    public static function endOfClass(string $pattern, int $start): int
    {
        return self::closedClassEnd($pattern, $start) ?? \strlen($pattern);
    }

    /**
     * The length of the escape at $start: "\p{L}", "\x{41}", "\x41", "\o{7}",
     * "\cA", "\N{U+41}", "\101", or a backslash and one character.
     */
    public static function escapeLength(string $pattern, int $start, bool $utf = false): int
    {
        preg_match(
            '/\G\\\\(?:x\{[ \t]*+[0-9A-Fa-f]*+[ \t]*+\}?|o\{[ \t]*+[0-7]*+[ \t]*+\}?|[pPN]\{[^}]*+\}?|[pP].|x[0-9A-Fa-f]{0,2}|c.|[0-7]{1,3}|.)/s'.($utf ? 'u' : ''),
            $pattern,
            $escape,
            0,
            $start,
        );

        return \strlen($escape[0] ?? '\\');
    }

    /**
     * Past the blanks, "\E" and "\Q\E" at $at, which a class read under "xx"
     * skips.
     */
    private static function pastQuoteMarks(string $pattern, int $at): int
    {
        while (1 === preg_match('/\G(?:[ \t]++|\\\\(?:Q\\\\E|E))/', $pattern, $mark, 0, $at)) {
            $at += \strlen($mark[0]);
        }

        return $at;
    }

    /**
     * Past a POSIX form at $start, as PCRE finds one: "[:", "[." or "[=", then
     * anything but "]" up to ":]", ".]" or "=]"; null when there is none.
     */
    private static function posixFormEnd(string $pattern, int $start): ?int
    {
        $terminator = $pattern[$start + 1] ?? '';
        if ('[' !== ($pattern[$start] ?? '') || !\in_array($terminator, [':', '.', '='], true)) {
            return null;
        }

        $length = \strlen($pattern);
        for ($at = $start + 2; $at < $length; $at++) {
            $char = $pattern[$at];
            $next = $pattern[$at + 1] ?? '';
            if ('\\' === $char && (']' === $next || '\\' === $next)) {
                $at++;
            } elseif (('[' === $char && $terminator === $next) || ']' === $char) {
                return null;
            } elseif ($terminator === $char && ']' === $next) {
                return $at + 2;
            }
        }

        return null;
    }

    /**
     * Past the "]" of the class, or POSIX class, whose "[" is at $start; null
     * when it never closes.
     */
    private static function closedClassEnd(string $pattern, int $start): ?int
    {
        $length = \strlen($pattern);
        $posixEnd = self::posixFormEnd($pattern, $start);
        if (null !== $posixEnd) {
            return $posixEnd;
        }

        // Blanks, "\E" and "\Q\E" leave a "^" or a "]" first.
        $at = self::pastQuoteMarks($pattern, $start + 1);
        $at = '^' === ($pattern[$at] ?? '') ? self::pastQuoteMarks($pattern, $at + 1) : $at;
        $at += ']' === ($pattern[$at] ?? '') ? 1 : 0;
        while ($at < $length) {
            $char = $pattern[$at];
            if ('\\' === $char && 'Q' === ($pattern[$at + 1] ?? '')) {
                $quoteEnd = strpos($pattern, '\\E', $at + 2);
                if (false === $quoteEnd) {
                    return null;
                }

                $at = $quoteEnd + 2;

                continue;
            }

            if ('\\' === $char) {
                // "\c]" is a control character: its "]" closes nothing.
                $at += 'c' === ($pattern[$at + 1] ?? '') ? 3 : 2;

                continue;
            }

            $posixEnd = '[' === $char ? self::posixFormEnd($pattern, $at) : null;
            if (null !== $posixEnd) {
                $at = $posixEnd;

                continue;
            }

            if (']' === $char) {
                return $at + 1;
            }

            $at++;
        }

        return null;
    }

    private function readUnion(): NodeInterface
    {
        $left = $this->readIntersection();

        while (true) {
            $this->skipBlanks();
            $char = $this->pattern[$this->at] ?? '';
            $operator = self::OPERATORS[$char] ?? null;
            if (null === $operator) {
                $this->refuseOperandAfterOperand();

                return $left;
            }

            $this->countOperation();
            $this->at++;
            $right = $this->readIntersection(true);
            $left = new ClassSetOperationNode($operator, $left, $right, $char, $left->getStartPosition(), $right->getEndPosition());
        }
    }

    /**
     * @param bool $afterOperator whether an operator comes right before
     */
    private function readIntersection(bool $afterOperator = false): NodeInterface
    {
        $left = $this->readUnary($afterOperator);

        while (true) {
            $this->skipBlanks();
            if ('&' !== ($this->pattern[$this->at] ?? '')) {
                return $left;
            }

            $this->countOperation();
            $this->at++;
            $right = $this->readUnary(true);
            $left = new ClassSetOperationNode(ClassSetOperator::Intersection, $left, $right, '&', $left->getStartPosition(), $right->getEndPosition());
        }
    }

    /**
     * @param bool $afterOperator whether an operator comes right before
     */
    private function readUnary(bool $afterOperator = false): NodeInterface
    {
        // "!!!\d" is read in a loop, not a recursion.
        $complements = [];
        $this->skipBlanks();
        while ('!' === ($this->pattern[$this->at] ?? '')) {
            $this->countOperation();
            $complements[] = $this->at++;
            $this->skipBlanks();
        }

        $operand = $this->readPrimary($afterOperator || [] !== $complements);
        foreach (array_reverse($complements) as $start) {
            $operand = new ClassSetOperationNode(ClassSetOperator::Complement, null, $operand, '!', $start, $operand->getEndPosition());
        }

        return $operand;
    }

    /**
     * An operand, where one is due: a ")" there closes nothing at the top
     * level, and after an operator a "]" or ")" leaves it without one.
     *
     * @param bool $afterOperator whether an operator comes right before
     */
    private function readPrimary(bool $afterOperator): NodeInterface
    {
        $char = $this->pattern[$this->at] ?? '';

        return match (true) {
            '' === $char => $this->refuse('Missing "]" to close an extended class', ErrorCode::ExtendedClassUnclosed, $this->length),
            ']' === $char && $this->depth > 1 => $this->refuse('Missing ")" in an extended class', ErrorCode::ExtendedClassUnclosedParen, $this->at),
            ')' === $char && 1 === $this->depth => $this->refuse('Unmatched ")" in an extended class', ErrorCode::ExtendedClassUnmatchedClose, $this->at + 1),
            $afterOperator && (']' === $char || ')' === $char) => $this->refuse('Operand expected after an operator in an extended class', ErrorCode::ExtendedClassMissingOperand, $this->at + 1),
            ']' === $char, ')' === $char => $this->refuse('Empty expression in an extended class', ErrorCode::ExtendedClassEmptyExpression, $this->at + 1),
            isset(self::OPERATORS[$char]), '&' === $char => $this->refuse(\sprintf('Operator "%s" where an operand is due in an extended class', $char), ErrorCode::ExtendedClassMissingOperand, $this->at + 1),
            default => $this->readOperand(),
        };
    }

    private function readOperand(): NodeInterface
    {
        $start = $this->at;
        $char = $this->pattern[$start];

        if ('(' === $char) {
            $this->enter($start);
            if (null !== $this->nest) {
                ($this->nest)($start + 1, ++$this->parentheses);
            }
            $this->at++;
            if ($this->at >= $this->length) {
                $this->refuse('Missing ")" in an extended class', ErrorCode::ExtendedClassUnclosedParen, $this->length);
            }

            $expression = $this->readUnion();
            $this->skipBlanks();
            $close = $this->pattern[$this->at] ?? '';
            if ('' === $close) {
                $this->refuse('Missing "]" to close an extended class', ErrorCode::ExtendedClassUnclosed, $this->length);
            }

            if (')' !== $close) {
                $this->refuse('Missing ")" in an extended class', ErrorCode::ExtendedClassUnclosedParen, $this->at);
            }

            $this->at++;
            $this->depth--;
            $this->parentheses--;

            return $expression;
        }

        if ('[' === $char) {
            $posixEnd = self::posixFormEnd($this->pattern, $start);
            if (null !== $posixEnd && ':' !== $this->pattern[$start + 1]) {
                $this->refuse('POSIX collating elements are not supported', ErrorCode::PosixCollatingElement, $posixEnd);
            }

            $end = $posixEnd ?? self::closedClassEnd($this->pattern, $start) ?? $this->refuseUnclosedClass($start);
            if (null === $posixEnd) {
                $this->enter($start);
                $this->depth--;
            }

            $this->at = $end;

            return $this->operands[] = $this->readApart($this->class, substr($this->pattern, $start, $end - $start), $start);
        }

        if ('\\' === $char) {
            if ('Q' === ($this->pattern[$start + 1] ?? '')) {
                $this->refuseQuote($start);
            }

            $end = $start + self::escapeLength($this->pattern, $start, $this->utf);
            $this->at = $end;

            return $this->operands[] = $this->readApart($this->escape, substr($this->pattern, $start, $end - $start), $start);
        }

        return $this->refuse('Unexpected character in an extended class', ErrorCode::ExtendedClassUnexpectedCharacter, $this->pastCharacter($start));
    }

    /**
     * An operand right after another one: PCRE refuses it past an escape or a
     * POSIX class it reads whole, and past the first character of the rest.
     */
    private function refuseOperandAfterOperand(): void
    {
        $char = $this->pattern[$this->at] ?? '';
        if ('' === $char || ']' === $char || ')' === $char) {
            return;
        }

        if ('\\' === $char && 'Q' === ($this->pattern[$this->at + 1] ?? '')) {
            $this->refuseQuote($this->at);
        }

        $end = match (true) {
            '\\' === $char => $this->at + self::escapeLength($this->pattern, $this->at, $this->utf),
            '[' === $char && null !== self::posixFormEnd($this->pattern, $this->at) => (int) self::posixFormEnd($this->pattern, $this->at),
            default => $this->pastCharacter($this->at),
        };

        // An escape is read, and judged, before it is found out of place.
        if ('\\' === $char) {
            $this->operands[] = $this->readApart($this->escape, substr($this->pattern, $this->at, $end - $this->at), $this->at);
        }

        $this->refuse('Two operands with no operator between them in an extended class', ErrorCode::ExtendedClassMissingOperator, $end);
    }

    /**
     * "\Q\E" and "\E" were skipped as blanks; "\Q" before a character leaves
     * that character, which is no operand.
     */
    private function refuseQuote(int $start): never
    {
        if ($start + 2 >= $this->length) {
            $this->refuse('Missing "]" to close an extended class', ErrorCode::ExtendedClassUnclosed, $this->length);
        }

        $this->refuse('Unexpected character in an extended class', ErrorCode::ExtendedClassUnexpectedCharacter, $this->pastCharacter($start + 2));
    }

    /**
     * Past the character at $at: a byte, or a UTF-8 character in UTF mode.
     */
    private function pastCharacter(int $at): int
    {
        if (!$this->utf || 1 !== preg_match('/\G./su', $this->pattern, $character, 0, $at)) {
            return $at + 1;
        }

        return $at + \strlen($character[0]);
    }

    /**
     * Each operation deepens the tree: past the parser's own limit, the
     * class is refused as too complex, as a nesting too deep would be.
     */
    private function countOperation(): void
    {
        if (++$this->operations > $this->maxOperations) {
            ($this->fail)(
                RecursionLimitException::withContext(\sprintf('Recursion limit of %d exceeded: the extended class holds more operations than that.', $this->maxOperations), ErrorCode::ExtendedClassTooComplex, $this->at, $this->pattern),
                $this->at,
                $this->operands,
            );
        }
    }

    /**
     * A class that never closes: what PCRE refuses in it comes first, as it
     * reads it before it meets the end.
     */
    private function refuseUnclosedClass(int $start): never
    {
        try {
            $this->operands[] = ($this->class)(substr($this->pattern, $start).']', $start);
        } catch (LexerException|ParserException $error) {
            // A fault in what the pattern writes comes first; one at its end
            // is the "]" added to read it.
            if (($error->getPosition() ?? $this->length) < $this->length) {
                ($this->fail)($error, $start, $this->operands);
            }
        }

        $this->refuse('Missing "]" to close a character class', ErrorCode::CharclassUnclosed, $this->length);
    }

    /**
     * An operand read by the parser; what it refuses comes after what PCRE
     * refuses in the operands before.
     *
     * @param \Closure(string, int):NodeInterface $read
     */
    private function readApart(\Closure $read, string $text, int $at): NodeInterface
    {
        try {
            return $read($text, $at);
        } catch (LexerException|ParserException $error) {
            ($this->fail)($error, $at, $this->operands);
        }
    }

    private function enter(int $position): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            $this->refuse('Extended class nested too deeply', ErrorCode::ExtendedClassNestedTooDeep, $this->pastTheFault ? $position : $position + 1);
        }
    }

    /**
     * Spaces, tabs, and an empty "\Q\E" or a lone "\E", which PCRE skips.
     */
    private function skipBlanks(): void
    {
        do {
            $before = $this->at;
            $this->at += strspn($this->pattern, " \t", $this->at);
            if (1 === preg_match('/\G\\\\(?:Q\\\\E|E)/', $this->pattern, $quote, 0, $this->at)) {
                $this->at += \strlen($quote[0]);
            }
        } while ($this->at > $before);
    }

    private function refuse(string $message, ErrorCode $code, int $position): never
    {
        ($this->fail)(
            SyntaxErrorException::withContext(\sprintf('%s at position %d.', $message, $position), $code, $position, $this->pattern),
            $position,
            $this->operands,
        );
    }
}
