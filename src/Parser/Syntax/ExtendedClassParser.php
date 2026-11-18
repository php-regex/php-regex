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

namespace PHPRegex\Parser\Syntax;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ClassSetOperator;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Parser\Validation\Validator;

/**
 * Reads a Perl extended class, "(?[...])" from PCRE2 10.45, for the token
 * parser: the expression from the text, each operand, a nested class or an
 * escape, read apart with its tokens moved to where it stands, and the
 * first operand PCRE refuses reported before what is wrong further on.
 *
 * @internal
 */
final class ExtendedClassParser
{
    private readonly string $pattern;

    private readonly PcreTarget $target;

    /**
     * @param string                                     $flags             the modifiers the pattern is read under
     * @param int                                        $maxRecursionDepth the most operations read, as deep as the parser goes
     * @param \Closure(list<Token>, bool): NodeInterface $readPart          reads tokens moved into the whole pattern as a
     *                                                                      pattern of their own, counting the groups opened
     *                                                                      before them or not
     * @param \Closure(int, int): void                   $nest              told where each "(" is read and how many are
     *                                                                      open with it, for the parser's own nesting limit
     */
    public function __construct(
        private readonly ParseState $state,
        private readonly string $flags,
        private readonly int $maxRecursionDepth,
        private readonly \Closure $readPart,
        private readonly \Closure $nest,
    ) {
        $this->pattern = $state->pattern;
        $this->target = $state->target;
    }

    /**
     * "(?[ \p{L} - [aeiou] ])", PCRE2 10.45: the expression is read from the
     * text; each nested class and escape is read apart, a class under "xx"
     * as PCRE reads it there.
     */
    public function parse(Token $token): ExtendedCharClassNode
    {
        $reader = new ExtendedClassReader(
            $this->pattern,
            // An escape is read as a class reads it: "\b" is a backspace, "\1" an octal escape.
            function (string $escape, int $at): NodeInterface {
                // "\p{" never closed is "\p" then text, as the lexer reads
                // it: the escape checks refuse it where PCRE gives up on its
                // name, which reads on to the end of the pattern at most.
                if (1 === LibraryPcre::match('/^\\\\[pP]\{[^}]*+$/', $escape)) {
                    return new LiteralNode($escape[1], $at, $at + 2);
                }

                // A backslash ends the pattern: PCRE refuses it there.
                if ('\\' === $escape) {
                    $end = \strlen($this->pattern);

                    throw $this->state->error(\sprintf('A backslash ends the pattern at position %d.', $end), ErrorCode::EscapeTrailingBackslash, $end);
                }

                // "\c" ends the pattern: a "]" after it would be its character.
                if ('\\c' === $escape) {
                    return $this->parseOperandAt($escape, $at, false);
                }

                $class = $this->parseOperandAt('['.$escape.']', $at - 1, true);

                return $class instanceof CharClassNode ? $class->expression : $class;
            },
            function (string $class, int $at): NodeInterface {
                // A POSIX class stands on its own there, "[:alpha:]", whatever
                // its name; the name is judged as in a class.
                if (1 === LibraryPcre::match('/^\[:(.*):\]$/s', $class, $posix)) {
                    return new PosixClassNode($posix[1], $at, $at + \strlen($class));
                }

                // "[[:<:]]" is no word boundary there, but an unknown name.
                if ('[[:<:]]' === $class || '[[:>:]]' === $class) {
                    throw $this->state->error(\sprintf('Unknown POSIX class "%s" at position %d.', $class[3], $at + 6), ErrorCode::PosixInvalid, $at + 6);
                }

                return $this->parseOperandAt($class, $at, true);
            },
            function (LexerException|LimitException|ParserException $reason, int $at, array $operands) use ($token): never {
                throw $this->firstOperandError($operands, $token->position) ?? $reason;
            },
            $this->target,
            $this->state->unicodeMode || str_contains($this->flags, 'u'),
            $this->maxRecursionDepth,
            // Parentheses nest as groups do, against the same limit.
            $this->nest,
        );

        // The lexer's token ends where the reader does: past the "])".
        [$expression, $end] = $reader->read($token->position);

        return new ExtendedCharClassNode($expression, $token->position, $end, substr($this->pattern, $token->position, $end - $token->position));
    }

    /**
     * The first operand of an extended class PCRE refuses: it reads and judges
     * each one before it meets what is wrong further on.
     *
     * @param list<NodeInterface> $operands
     */
    private function firstOperandError(array $operands, int $start): ?ParserException
    {
        $expression = array_shift($operands);
        if (null === $expression) {
            return null;
        }

        foreach ($operands as $operand) {
            $expression = new ClassSetOperationNode(ClassSetOperator::Union, $expression, $operand, '+', $expression->getStartPosition(), $operand->getEndPosition());
        }

        $flags = $this->state->unicodeMode && !str_contains($this->flags, 'u') ? $this->flags.'u' : $this->flags;
        $end = \strlen($this->pattern);
        $tree = new RegexNode(new ExtendedCharClassNode($expression, $start, $end), $flags, '/', 0, $end, $this->pattern);

        try {
            $tree->accept(new Validator(pattern: $this->pattern, target: $this->target));
        } catch (RegexException $error) {
            // A parse error, which keeps the judgement it wraps.
            return ParserException::withContext($error->getMessage(), $error->errorCode, $error->position ?? $start, $this->pattern, $error);
        }

        return null;
    }

    /**
     * An operand of "(?[...])" written at $at, read with offsets in the whole
     * pattern: its tokens are moved there before they are parsed. A nested
     * class is read under "xx", where spaces and tabs are no members.
     */
    private function parseOperandAt(string $text, int $at, bool $class): NodeInterface
    {
        $lexer = new Lexer($this->target);

        try {
            $read = $lexer->tokenize($text, $this->flags, $class)->getTokens();
        } catch (LexerException $error) {
            $moved = $this->state->movedError($error, $at);
            [$tokens, $position] = $this->readUpToAPropertyLeftOpen($this->movedTokens($lexer->tokensRead(), $at), $moved->position ?? $at, $lexer->literalEscapesRead(), $at);

            throw $this->firstMemberErrorBefore($tokens, $position) ?? $moved;
        }

        $tokens = $this->movedTokens($read, $at, $class);

        try {
            return ($this->readPart)($tokens, true);
        } catch (ParserException $error) {
            // A range is refused once its end is read, whatever that end
            // holds past the refusal.
            if ($class && ErrorCode::RangeInvalidBounds === $error->errorCode) {
                $error = $this->refusalAsRead($error, $tokens, \PHP_INT_MAX) ?? $error;
            }

            if ($class) {
                [$read, $position] = $this->readUpToAPropertyLeftOpen($tokens, $error->position ?? $at, $lexer->literalEscapesRead(), $at);
                $error = $this->firstMemberErrorBefore($read, $position) ?? $error;
            }

            throw $error;
        }
    }

    /**
     * Tokens read apart, moved to where they stand in the whole pattern.
     * Under "xx", a class skips its blanks, but not those "\Q...\E" quotes.
     *
     * @param array<Token> $read
     *
     * @return list<Token>
     */
    private function movedTokens(array $read, int $at, bool $class = true): array
    {
        $tokens = [];
        $quoted = false;
        foreach ($read as $token) {
            $quoted = match ($token->type) {
                TokenType::QuoteModeStart => true,
                TokenType::QuoteModeEnd => false,
                default => $quoted,
            };
            if ($class && !$quoted && TokenType::Literal === $token->type && \in_array($token->value, [' ', "\t"], true)) {
                continue;
            }

            $tokens[] = new Token($token->type, $token->value, $token->position + $at, $token->sourceLength);
        }

        return $tokens;
    }

    /**
     * The tokens read up to the first "\p{" or "\P{" that no "}" closes,
     * that one included, and the end of the pattern past which faults are
     * looked for, when one starts before $position, where reading failed;
     * the tokens and $position as they are otherwise. The lexer reads
     * "\p" then text there, where PCRE reads the rest as the name: a fault
     * the lexer finds past it is none of PCRE's, which gives up on the
     * name first.
     *
     * @param list<Token>      $tokens         moved into the whole pattern
     * @param array<int, true> $literalEscapes where the escapes PHP 7.3 and 7.4 read as their character stand, in the text read
     * @param int              $at             where the text read starts in the whole pattern
     *
     * @return array{list<Token>, int}
     */
    private function readUpToAPropertyLeftOpen(array $tokens, int $position, array $literalEscapes, int $at): array
    {
        foreach ($tokens as $index => $token) {
            if ($token->position >= $position) {
                break;
            }

            if (TokenType::LiteralEscaped === $token->type && \in_array($token->value, ['p', 'P'], true) && !isset($literalEscapes[$token->position - $at])
                && '{' === ($this->pattern[$token->end()] ?? '') && false === strpos($this->pattern, '}', $token->end())) {
                return [\array_slice($tokens, 0, $index + 1), \strlen($this->pattern) + 1];
            }
        }

        return [$tokens, $position];
    }

    /**
     * A member or a range of a class PCRE refuses before $position, where
     * reading the class failed: PCRE judges each as it reads it, whichever
     * comes first.
     *
     * @param list<Token> $tokens
     */
    private function firstMemberErrorBefore(array $tokens, int $position): ?ParserException
    {
        $member = $this->firstLoneMemberErrorBefore($tokens, $position);
        // An escape read alone, or what the lexer read before it failed on
        // one, holds no range.
        $range = TokenType::CharClassOpen === ($tokens[0] ?? null)?->type ? $this->firstRangeErrorBefore($tokens, $position) : null;

        return null === $range || (null !== $member && ($member->position ?? $position) <= ($range->position ?? $position)) ? $member : $range;
    }

    /**
     * The class read up to $position, closed there, judged whole: a range
     * PCRE refuses once it has read it, "]--" reversed before a range no
     * class takes. Should the parser refuse a range there too, PCRE refuses
     * it once it has read its end: the class read up to that range's hyphen
     * is judged too, and the earlier of the two faults is the one.
     *
     * @param list<Token> $tokens the class, from its "["
     */
    private function firstRangeErrorBefore(array $tokens, int $position): ?ParserException
    {
        $read = [$tokens[0]];
        $lastHyphen = null;
        foreach (\array_slice($tokens, 1) as $token) {
            if ($token->end() > $position || \in_array($token->type, [TokenType::CharClassClose, TokenType::Eof], true)) {
                break;
            }

            if (TokenType::Range === $token->type) {
                $lastHyphen = \count($read);
            }

            $read[] = $token;
        }

        $class = null;
        $refused = null;
        foreach (null === $lastHyphen ? [$read] : [$read, \array_slice($read, 0, $lastHyphen)] as $prefix) {
            $end = $prefix[array_key_last($prefix)]->end();

            try {
                $class = ($this->readPart)([...$prefix, new Token(TokenType::CharClassClose, ']', $end, 0), new Token(TokenType::Eof, '', $end)], false);

                break;
            } catch (LimitException) {
                // Read up to the hyphen of the last range.
            } catch (ParserException $error) {
                // The range refused there: read up to its hyphen.
                $refused ??= $this->refusalAsRead($error, $read, $position);
            }
        }

        $error = null === $class ? null : $this->firstOperandError([$class], $read[0]->position);
        if (null === $error || (null !== $refused && ($refused->position ?? $position) < ($error->position ?? $position))) {
            $error = $refused;
        }

        return null !== $error && ($error->position ?? $position) < $position ? $error : null;
    }

    /**
     * What PCRE refuses first in a class whose range the parser refused:
     * PCRE judges a range once it has read its end, so a member it refuses
     * up to that end, refused before $position, comes first; a POSIX item
     * ending the range is refused as the range before its name is read.
     * Null when PCRE never reads that end: the fault cuts the class after
     * the hyphen, or the pattern ends there.
     *
     * @param list<Token> $read the class read, from its "["
     */
    private function refusalAsRead(ParserException $refusal, array $read, int $position): ?ParserException
    {
        $at = $refusal->position ?? $position;
        $hyphen = null;
        foreach ($read as $index => $token) {
            if (TokenType::Range === $token->type && $token->position < $at) {
                $hyphen = $index;
            }
        }

        $end = null;
        foreach (null === $hyphen ? [] : \array_slice($read, $hyphen + 1, null, true) as $index => $token) {
            if (!\in_array($token->type, [TokenType::QuoteModeStart, TokenType::QuoteModeEnd], true)) {
                $end = $index;

                break;
            }
        }

        // The "]" that closes a class the pattern leaves open is read past
        // its end.
        if (null === $end || $read[$end]->end() > \strlen($this->pattern)) {
            return null;
        }

        $posix = TokenType::PosixClass === $read[$end]->type || ('[' === $read[$end]->value && null !== PosixItem::terminator($this->pattern, $read[$end]->position));

        return $this->firstLoneMemberErrorBefore(\array_slice($read, 0, $posix ? $hyphen : $end + 1), $position) ?? $refusal;
    }

    /**
     * A member of a class PCRE refuses before $position, read alone: PCRE
     * judges each member as it reads it.
     *
     * @param list<Token> $tokens
     */
    private function firstLoneMemberErrorBefore(array $tokens, int $position): ?ParserException
    {
        $quoted = false;
        foreach ($tokens as $token) {
            // What "\Q" quotes is text: a "[" there opens no POSIX item.
            if (\in_array($token->type, [TokenType::QuoteModeStart, TokenType::QuoteModeEnd], true)) {
                $quoted = TokenType::QuoteModeStart === $token->type;

                continue;
            }

            if ($quoted || $token->end() > $position || \in_array($token->type, [TokenType::CharClassOpen, TokenType::CharClassClose, TokenType::Range, TokenType::Eof], true)) {
                continue;
            }

            $member = [
                new Token(TokenType::CharClassOpen, '[', $token->position),
                $token,
                new Token(TokenType::CharClassClose, ']', $token->end()),
                new Token(TokenType::Eof, '', $token->end()),
            ];

            try {
                $class = ($this->readPart)($member, false);
            } catch (ParserException) {
                continue;
            }

            // A member refused where the fault is was read first.
            $error = $this->firstOperandError([$class], $token->position);
            if (null !== $error && ($error->position ?? $position) <= $position) {
                return $error;
            }
        }

        return null;
    }
}
