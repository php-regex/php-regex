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
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ClassSetOperator;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
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
    /**
     */
    private string $pattern;
    /**
     */
    private PcreTarget $target;
    /**
     */
    private ParseState $state;
    /**
     */
    private string $flags;
    /**
     */
    private int $maxRecursionDepth;
    /**
     * @var \Closure(list<Token>, bool): NodeInterface
     *
     */
    private \Closure $readPart;
    /**
     * @var \Closure(int, int): void
     *
     */
    private \Closure $nest;

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
        ParseState $state,
        string $flags,
        int $maxRecursionDepth,
        \Closure $readPart,
        \Closure $nest
    ) {
        $this->state = $state;
        $this->flags = $flags;
        $this->maxRecursionDepth = $maxRecursionDepth;
        $this->readPart = $readPart;
        $this->nest = $nest;
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
                // "\p{" never closed is refused where the pattern ends.
                if (1 === LibraryPcre::match('/^\\\\[pP]\{[^}]*+$/', $escape)) {
                    $end = \strlen($this->pattern);

                    throw $this->state->error(\sprintf('Malformed \\%s sequence: the braced name never closes at position %d.', $escape[1], $end), ErrorCode::UnicodePropertyMalformed(), $end);
                }

                // A backslash ends the pattern: PCRE refuses it there.
                if ('\\' === $escape) {
                    $end = \strlen($this->pattern);

                    throw $this->state->error(\sprintf('A backslash ends the pattern at position %d.', $end), ErrorCode::EscapeTrailingBackslash(), $end);
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
                    throw $this->state->error(\sprintf('Unknown POSIX class "%s" at position %d.', $class[3], $at + 6), ErrorCode::PosixInvalid(), $at + 6);
                }

                return $this->parseOperandAt($class, $at, true);
            },
            function ($reason, int $at, array $operands) use ($token) {
                throw $this->firstOperandError($operands, $token->position) ?? $reason;
            },
            $this->target,
            $this->state->unicodeMode || false !== strpos($this->flags, 'u'),
            $this->maxRecursionDepth,
            // Parentheses nest as groups do, against the same limit.
            $this->nest,
        );

        // The lexer's token ends where the reader does: past the "])".
        [$expression, $end] = $reader->read($token->position);

        return new ExtendedCharClassNode($expression, $token->position, $end, (string) substr($this->pattern, $token->position, $end - $token->position));
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
            $expression = new ClassSetOperationNode(ClassSetOperator::Union(), $expression, $operand, '+', $expression->getStartPosition(), $operand->getEndPosition());
        }

        $flags = $this->state->unicodeMode && false === strpos($this->flags, 'u') ? $this->flags.'u' : $this->flags;
        $end = \strlen($this->pattern);
        $tree = new RegexNode(new ExtendedCharClassNode($expression, $start, $end), $flags, '/', 0, $end, $this->pattern);

        try {
            $tree->accept(new Validator(255, $this->pattern, $this->target));
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

            throw $this->firstMemberErrorBefore($this->movedTokens($lexer->tokensRead(), $at), $moved->position ?? $at) ?? $moved;
        }

        $tokens = $this->movedTokens($read, $at, $class);

        try {
            return ($this->readPart)($tokens, true);
        } catch (ParserException $error) {
            throw ($class ? $this->firstMemberErrorBefore($tokens, $error->position ?? $at) : null) ?? $error;
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
            switch (true) {
                case $token->type === TokenType::QuoteModeStart():
                    $quoted = true;
                    break;
                case $token->type === TokenType::QuoteModeEnd():
                    $quoted = false;
                    break;
                default:
                    $quoted = $quoted;
                    break;
            }
            if ($class && !$quoted && TokenType::Literal() === $token->type && \in_array($token->value, [' ', "\t"], true)) {
                continue;
            }

            $tokens[] = new Token($token->type, $token->value, $token->position + $at, $token->sourceLength);
        }

        return $tokens;
    }

    /**
     * A member of a class PCRE refuses before $position, where reading the
     * class failed: PCRE judges each member as it reads it.
     *
     * @param list<Token> $tokens
     */
    private function firstMemberErrorBefore(array $tokens, int $position): ?ParserException
    {
        foreach ($tokens as $token) {
            if ($token->end() > $position || \in_array($token->type, [TokenType::CharClassOpen(), TokenType::CharClassClose(), TokenType::Range(), TokenType::Eof()], true)) {
                continue;
            }

            $member = [
                new Token(TokenType::CharClassOpen(), '[', $token->position),
                $token,
                new Token(TokenType::CharClassClose(), ']', $token->end()),
                new Token(TokenType::Eof(), '', $token->end()),
            ];

            try {
                $class = ($this->readPart)($member, false);
            } catch (ParserException $exception) {
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
