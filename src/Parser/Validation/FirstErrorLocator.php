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

namespace PHPRegex\Parser\Validation;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\PatternParser;
use PHPRegex\Parser\Syntax\PcreVerb;
use PHPRegex\Parser\Syntax\TokenParser;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;

/**
 * Finds, in a pattern that failed to parse, the error PCRE meets first: the
 * library tokenizes, parses and judges in turn, where PCRE reads the pattern
 * once, left to right, so the error the parser stops on may lie after the
 * one PCRE reports.
 *
 * @internal
 */
final class FirstErrorLocator
{
    public function __construct(
        private readonly int $maxRecursionDepth,
        private readonly int $maxLookbehindLength,
        private readonly PcreTarget $target,
    ) {}

    /**
     * PCRE reads the pattern in one pass, left to right. This library
     * tokenizes it whole, then parses it, then judges its escapes, so the
     * error it stops on may lie after one PCRE meets first: an escape PCRE
     * refuses, a class holding an unknown POSIX name or a reversed range,
     * an extended class, a version condition; what the tree read before
     * the error shows, a relative reference to no group, a reversed count,
     * a code point too large, a verb out of place or unknown; or, when
     * tokenizing failed or a name swallows the rest, a syntax error in what
     * was read before. The earliest of those before the error found is
     * PCRE's. What PCRE only finds once it has read the whole pattern, a
     * lookbehind's length or a group a name or number points to that does
     * not exist, is not.
     *
     * A "\p{" that no "}" closes, or a "(*" that no ")" closes, ends what is
     * read: PCRE looks for the closing character to the end of the pattern,
     * so nothing after it is judged, and it is judged last.
     */
    public function earlierError(string $regex, LexerException|ParserException $error): ?RegexException
    {
        $position = $error->position ?? 0;

        try {
            [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags($regex, $this->target);
        } catch (ParserException) {
            return null;
        }

        $lexer = new Lexer($this->target);

        try {
            $lexer->tokenize($pattern, $flags);
        } catch (LexerException) {
            // The tokens read before the error are what is judged.
        }

        $tokens = $lexer->tokensRead();

        // The escapes, classes and conditions of an alphabetic assertion's
        // body are judged where they stand, as PCRE reads them: in one pass
        // reading each body in place, never again for the bodies inside it.
        try {
            $lexer->tokenizeInPlace($pattern, $flags);
        } catch (LexerException) {
            // The tokens read before the error are what is judged.
        }

        $inPlace = $lexer->tokensRead();

        // A "\p{" that no "}" closes, or a "(*" that no ")" closes, read
        // before the error: PCRE reads nothing past it, and refuses it at
        // the end of the pattern or where its name goes wrong. What stands
        // before it is judged, and it last.
        $swallowing = self::readUpToAnUnclosedName($inPlace, $pattern, $position);
        $read = $swallowing ?? $inPlace;
        $limit = null === $swallowing ? $position : \strlen($pattern) + 1;

        $earlier = (new Validator($this->maxLookbehindLength, $pattern, $this->target))
            ->firstEscapeErrorBefore($read, $pattern, $flags, $limit);

        $earlier ??= $this->escapeErrorEndingRange($error, $read, $pattern, $flags);

        $laterErrors = [
            $this->firstClassErrorBefore($read, $pattern, $flags, $delimiter, $limit),
            $error instanceof LexerException && \in_array($error->errorCode, [ErrorCode::CharclassUnclosed, ErrorCode::RangeReversed], true)
                ? $this->firstErrorInClassLeftOpen($read, $pattern, $flags, $delimiter, $position) : null,
            $this->firstExtendedClassErrorBefore($read, $pattern, $flags, $delimiter, $limit),
            $this->firstVersionConditionErrorBefore($read, $pattern, $flags, $delimiter, $limit),
            $this->firstErrorReadBefore($read, $pattern, $flags, $delimiter, $limit),
        ];
        foreach ($laterErrors as $laterError) {
            if (null !== $laterError && (null === $earlier || ($laterError->position ?? $position) < ($earlier->position ?? $position))) {
                $earlier = $laterError;
            }
        }

        if (null !== $swallowing || $error instanceof LexerException) {
            // The pattern read as if it ended where tokenizing stopped: an
            // error that ending causes lies there, and is not taken. A "(?"
            // read last is read with what follows it, which is not read:
            // what is refused past it is refused for want of that. Read up
            // to an unclosed name, the pattern is read whole.
            $syntaxTokens = $swallowing ?? $tokens;
            $stream = new TokenStream([...$syntaxTokens, new Token(TokenType::Eof, '', min($limit, \strlen($pattern)))], $pattern);
            $last = [] === $syntaxTokens ? null : $syntaxTokens[array_key_last($syntaxTokens)];
            $readUpTo = null !== $last && TokenType::GroupModifierOpen === $last->type ? $last->end() : $limit;

            try {
                (new TokenParser($this->maxRecursionDepth, $this->target))->parse($stream, $flags, $delimiter, \strlen($pattern));
            } catch (LexerException|LimitException|ParserException $syntaxError) {
                $at = $syntaxError->position ?? $position;
                // A body that never closes is read to the end: an error met
                // there, other than that ")" missing, comes first. So does
                // one in the item that ends a class never closed, but for a
                // "-" or a quote, which PCRE reads as no item: it then meets
                // the end of the pattern.
                $atTheEnd = $at === $position && $error->errorCode !== $syntaxError->errorCode
                    && (ErrorCode::GroupUnclosed === $error->errorCode
                        || (ErrorCode::CharclassUnclosed === $error->errorCode && null !== $last
                            && !\in_array($last->type, [TokenType::Range, TokenType::QuoteModeStart, TokenType::QuoteModeEnd], true)));
                if (($at < min($limit, $readUpTo) || $atTheEnd) && (null === $earlier || $at < ($earlier->position ?? $position))) {
                    return $this->escapeErrorEndingRange($syntaxError, $syntaxTokens, $pattern, $flags) ?? $syntaxError;
                }
            }
        }

        return $earlier;
    }

    /**
     * From PCRE2 10.45 a range from a type, a POSIX class or a property is
     * read to its end before it is refused past its hyphen: an escape PCRE
     * refuses as it reads that end is refused first, past the hyphen. Null
     * for any other error, or an end PCRE reads.
     *
     * @param list<Token> $tokens
     */
    private function escapeErrorEndingRange(RegexException $error, array $tokens, string $pattern, string $flags): ?SemanticErrorException
    {
        if (ErrorCode::RangeInvalidBounds !== $error->errorCode || !$this->target->supports(PcreFeature::RangeFromTypeReadToItsEnd)) {
            return null;
        }

        $end = self::rangeEndAfterHyphen($tokens, $error->position ?? 0);
        if (null === $end) {
            return null;
        }

        // The escapes read up to that end, which starts before the limit.
        return (new Validator($this->maxLookbehindLength, $pattern, $this->target))
            ->firstEscapeErrorBefore($tokens, $pattern, $flags, $end->position + ($this->target->supports(PcreFeature::ErrorOffsetPastTheFault) ? 2 : 1));
    }

    /**
     * The token that ends the range whose hyphen ends at $position, past the
     * empty quotes PCRE skips there; null when no hyphen ends there.
     *
     * @param list<Token> $tokens
     */
    private static function rangeEndAfterHyphen(array $tokens, int $position): ?Token
    {
        $afterHyphen = false;
        $end = null;
        foreach ($tokens as $token) {
            if ($afterHyphen && TokenType::QuoteModeStart !== $token->type && TokenType::QuoteModeEnd !== $token->type) {
                $end = $token;

                break;
            }

            $afterHyphen = $afterHyphen || (TokenType::Range === $token->type && $token->end() === $position);
        }

        return $end;
    }

    /**
     * The tokens read up to the first "\p{" that no "}" closes, or "(*"
     * that no ")" closes, before $position, where parsing failed, that one
     * included; null when none stands there. PCRE reads nothing after it:
     * it looks for the "}" or ")" to the end of the pattern.
     *
     * @param list<Token> $tokens
     *
     * @return list<Token>|null
     */
    private static function readUpToAnUnclosedName(array $tokens, string $pattern, int $position): ?array
    {
        $lastBrace = strrpos($pattern, '}');

        foreach ($tokens as $index => $token) {
            if ($token->position >= $position) {
                break;
            }

            // The lexer reads "\p{" with no "}" after it as "\p" then "{".
            if (TokenType::LiteralEscaped === $token->type && \in_array($token->value, ['p', 'P'], true)
                && '{' === ($pattern[$token->end()] ?? '') && (false === $lastBrace || $lastBrace < $token->end())) {
                return \array_slice($tokens, 0, $index + 1);
            }

            // And "(*MARK:a" with no ")" after it as "(" then "*".
            $star = $tokens[$index + 1] ?? null;
            if (TokenType::GroupOpen === $token->type && null !== $star && TokenType::Quantifier === $star->type
                && $star->position === $token->end() && '*' === $star->value[0] && $star->position + 1 < \strlen($pattern)) {
                return \array_slice($tokens, 0, $index + 2);
            }
        }

        return null;
    }

    /**
     * The first error PCRE meets before $position, where parsing failed,
     * that the library only finds in the tree: a relative reference to no
     * group, a reversed count, a code point too large, a verb out of place
     * or unknown. The tokens are parsed once to learn how far they are read
     * whole; that much, its groups closed, is parsed and judged once.
     *
     * @param list<Token> $tokens the tokens read in place
     */
    private function firstErrorReadBefore(array $tokens, string $pattern, string $flags, string $delimiter, int $position): ?SemanticErrorException
    {
        if (!self::mayHoldAnErrorOfTheTree($tokens)) {
            return null;
        }

        // PCRE refuses the level of parentheses past its limit as it opens
        // it, at the start of its body, though the groups are never closed.
        $tooDeep = self::openerPastTheNestingLimit($tokens);
        if (null !== $tooDeep) {
            return new SemanticErrorException(\sprintf('Parentheses are nested too deeply: PCRE allows at most %d levels.', Validator::MAX_GROUP_NESTING), ErrorCode::GroupNestedTooDeep, $tooDeep->end(), $pattern, null, 'Flatten the pattern: drop groups that only wrap one item.');
        }

        $length = \strlen($pattern);
        $parser = new TokenParser($this->maxRecursionDepth, $this->target);

        try {
            $ast = $parser->parse(new TokenStream([...$tokens, new Token(TokenType::Eof, '', $length)], $pattern), $flags, $delimiter, $length);
        } catch (LexerException|LimitException|ParserException) {
            $read = \array_slice($tokens, 0, $parser->tokensReadWhole());
            $cutAt = [] === $read ? 0 : $read[array_key_last($read)]->end();

            // Each group still open there is closed where the text read ends.
            $closers = [];
            foreach ($read as $token) {
                if (TokenType::GroupClose === $token->type) {
                    array_pop($closers);
                } elseif (self::opensGroup($token)) {
                    $closers[] = new Token(TokenType::GroupClose, ')', $cutAt, 0);
                }
            }

            try {
                $ast = (new TokenParser($this->maxRecursionDepth, $this->target))
                    ->parse(new TokenStream([...$read, ...$closers, new Token(TokenType::Eof, '', $cutAt)], $pattern), $flags, $delimiter, $length);
            } catch (LexerException|LimitException|ParserException) {
                // Not expected: these are the tokens the first parse read
                // whole, each group they open closed, no deeper than it went.
                // Kept should one throw: this runs while an error is being
                // reported, where nothing may escape.
                return null;
            }
        }

        return (new Validator($this->maxLookbehindLength, $pattern, $this->target))->firstErrorReadBefore($ast, $position);
    }

    /**
     * Whether any of the tokens becomes a node the Validator may refuse as
     * it walks the tree: text, plain groups, lookarounds, classes (judged
     * apart), alternatives, dots, anchors, quotes, comments and repeats
     * without a count never are, and a pattern of them alone is not parsed
     * again.
     *
     * @param list<Token> $tokens
     */
    private static function mayHoldAnErrorOfTheTree(array $tokens): bool
    {
        // Past the nesting limit, plain groups are refused too.
        if (null !== self::openerPastTheNestingLimit($tokens)) {
            return true;
        }

        foreach ($tokens as $token) {
            $plain = match ($token->type) {
                TokenType::Literal, TokenType::GroupOpen, TokenType::GroupClose, TokenType::Alternation,
                TokenType::Dot, TokenType::Anchor, TokenType::CharClassOpen, TokenType::CharClassClose,
                TokenType::Range, TokenType::Negation, TokenType::PosixClass, TokenType::QuoteModeStart,
                TokenType::QuoteModeEnd, TokenType::CommentOpen => true,
                TokenType::Quantifier => '{' !== $token->value[0],
                TokenType::PcreVerb => self::opensGroup($token) && null !== PcreVerb::read($token->value)->assertion,
                default => false,
            };

            if (!$plain) {
                return true;
            }
        }

        return false;
    }

    /**
     * The token that opens the first level of parentheses past PCRE's
     * limit, or null when none does.
     *
     * @param list<Token> $tokens
     */
    private static function openerPastTheNestingLimit(array $tokens): ?Token
    {
        $open = [];
        foreach ($tokens as $token) {
            if (self::opensGroup($token)) {
                $open[] = $token;
                if (\count($open) > Validator::MAX_GROUP_NESTING) {
                    return $token;
                }
            } elseif (TokenType::GroupClose === $token->type) {
                array_pop($open);
            }
        }

        return null;
    }

    /**
     * Whether the token opens a group a ")" closes: "(", "(?", "(?#", or the
     * opener of a body read in place, "(*pla:" or "(?*", whose value is its
     * text without the "(" and the character after it.
     */
    private static function opensGroup(Token $token): bool
    {
        return match ($token->type) {
            TokenType::GroupOpen, TokenType::GroupModifierOpen, TokenType::CommentOpen => true,
            TokenType::PcreVerb => $token->end() - $token->position === \strlen($token->value) + 2,
            default => false,
        };
    }

    /**
     * The first error in a character class the tokens close before $position,
     * where parsing failed: each such class is parsed alone and judged.
     *
     * @param list<Token> $tokens
     */
    private function firstClassErrorBefore(array $tokens, string $pattern, string $flags, string $delimiter, int $position): ?SemanticErrorException
    {
        $depth = 0;
        $opening = 0;

        foreach ($tokens as $index => $token) {
            if (TokenType::CharClassOpen === $token->type && 0 === $depth++) {
                $opening = $index;
            }

            if (TokenType::CharClassClose !== $token->type || 0 !== --$depth) {
                continue;
            }

            $class = \array_slice($tokens, $opening, $index - $opening + 1);

            try {
                $ast = (new TokenParser($this->maxRecursionDepth, $this->target))
                    ->parse(new TokenStream([...$class, new Token(TokenType::Eof, '', $token->end())], $pattern), $flags, $delimiter, \strlen($pattern));
            } catch (LexerException|LimitException|ParserException $classError) {
                // A class read alone opens no group, so no limit is met
                // here today; one would escape the error being reported. A
                // range the parser refuses leaves the items before it.
                $error = $this->firstItemErrorBefore($class, $pattern, $flags, $delimiter, min($position, $classError->position ?? $position));
                if (null !== $error) {
                    return $error;
                }

                continue;
            }

            $error = (new Validator($this->maxLookbehindLength, $pattern, $this->target))
                ->firstErrorInClassBefore($ast, $token->position, $position);
            if (null !== $error) {
                return $error;
            }
        }

        return null;
    }

    /**
     * The first error in the class the tokens leave open at the end of the
     * pattern: PCRE reads it item by item before it meets that end, so an
     * item it refuses comes before the missing "]", or before a reversed
     * range after it, at $position. The class is parsed alone, as if a "]"
     * closed it there, and judged.
     *
     * @param list<Token> $tokens
     */
    private function firstErrorInClassLeftOpen(array $tokens, string $pattern, string $flags, string $delimiter, int $position): ?SemanticErrorException
    {
        // The tokens from the "[" no "]" closes; none should the lexer ever
        // report a class left open it has not read.
        $depth = 0;
        $opening = \count($tokens);
        foreach ($tokens as $index => $token) {
            if (TokenType::CharClassOpen === $token->type && 0 === $depth++) {
                $opening = $index;
            } elseif (TokenType::CharClassClose === $token->type && $depth > 0 && 0 === --$depth) {
                $opening = \count($tokens);
            }
        }

        $end = \strlen($pattern);
        $class = [...\array_slice($tokens, $opening), new Token(TokenType::CharClassClose, ']', $end, 0), new Token(TokenType::Eof, '', $end)];

        try {
            $ast = (new TokenParser($this->maxRecursionDepth, $this->target))->parse(new TokenStream($class, $pattern), $flags, $delimiter, $end);
            $ast->accept(new Validator($this->maxLookbehindLength, $pattern, $this->target));
        } catch (SemanticErrorException $error) {
            return $error;
        } catch (LexerException|LimitException|ParserException $classError) {
            // A range the parser refuses leaves the items before it, and
            // before the reversed range the lexer found, if any.
            return $this->firstItemErrorBefore($class, $pattern, $flags, $delimiter, min($classError->position ?? $end, $position));
        }

        return null;
    }

    /**
     * The first item of a class, its tokens from its "[", that PCRE refuses
     * as it reads it before $limit, where the parser could not read the
     * class whole: a code point or a POSIX class, each judged alone. A
     * quote stops the reading: what it holds is no item.
     *
     * @param list<Token> $class
     */
    private function firstItemErrorBefore(array $class, string $pattern, string $flags, string $delimiter, int $limit): ?SemanticErrorException
    {
        $items = [TokenType::Unicode, TokenType::UnicodeNamed, TokenType::Octal, TokenType::OctalLegacy, TokenType::PosixClass];
        foreach (\array_slice($class, 1) as $token) {
            if ($token->position >= $limit || TokenType::QuoteModeStart === $token->type) {
                break;
            }

            if (!\in_array($token->type, $items, true)) {
                continue;
            }

            try {
                (new TokenParser($this->maxRecursionDepth, $this->target))
                    ->parse(new TokenStream([$class[0], $token, new Token(TokenType::CharClassClose, ']', $token->end(), 0), new Token(TokenType::Eof, '', $token->end())], $pattern), $flags, $delimiter, \strlen($pattern))
                    ->accept(new Validator($this->maxLookbehindLength, $pattern, $this->target));
            } catch (SemanticErrorException $error) {
                if (($error->position ?? $limit) < $limit) {
                    return $error;
                }
            }
        }

        return null;
    }

    /**
     * The first error in an extended class "(?[...])" before $position: each
     * is read alone and judged, as PCRE judges it when it reads it.
     *
     * @param list<Token> $tokens
     */
    private function firstExtendedClassErrorBefore(array $tokens, string $pattern, string $flags, string $delimiter, int $position): ?RegexException
    {
        foreach ($tokens as $token) {
            if (TokenType::ExtendedClass !== $token->type || $token->end() > $position) {
                continue;
            }

            try {
                $ast = (new TokenParser($this->maxRecursionDepth, $this->target))
                    ->parse(new TokenStream([$token, new Token(TokenType::Eof, '', $token->end())], $pattern), $flags, $delimiter, \strlen($pattern));
                $ast->accept(new Validator($this->maxLookbehindLength, $pattern, $this->target));
            } catch (RegexException $error) {
                if (($error->position ?? $position) < $position) {
                    return $error;
                }
            }
        }

        return null;
    }

    /**
     * The first error in a version condition, "(?(VERSION>=10.4)", the tokens
     * close before $position, where parsing failed: PCRE judges the version
     * as it reads it. Each such condition is parsed alone and judged.
     *
     * @param list<Token> $tokens
     */
    private function firstVersionConditionErrorBefore(array $tokens, string $pattern, string $flags, string $delimiter, int $position): ?SemanticErrorException
    {
        foreach ($tokens as $index => $open) {
            $group = $tokens[$index + 1] ?? null;
            if (TokenType::GroupModifierOpen !== $open->type || null === $group || TokenType::GroupOpen !== $group->type
                || $group->position !== $open->end() || !str_starts_with(substr($pattern, $group->end(), 7), 'VERSION')) {
                continue;
            }

            // The condition runs to the first ")", read from where it opens,
            // not from a copy of the rest of the tokens for each condition.
            $condition = [$open, $group];
            for ($at = $index + 2; isset($tokens[$at]); $at++) {
                $condition[] = $tokens[$at];
                if (TokenType::GroupClose === $tokens[$at]->type) {
                    break;
                }
            }

            $close = $condition[\count($condition) - 1];
            if (TokenType::GroupClose !== $close->type || $close->end() > $position) {
                continue;
            }

            try {
                $ast = (new TokenParser($this->maxRecursionDepth, $this->target))->parse(
                    new TokenStream([...$condition, new Token(TokenType::GroupClose, ')', $close->end()), new Token(TokenType::Eof, '', $close->end() + 1)], $pattern),
                    $flags,
                    $delimiter,
                    \strlen($pattern),
                );
                // A condition the parser reads as a name is looked up once the
                // whole pattern is read.
                if (!$ast->pattern instanceof ConditionalNode || !$ast->pattern->condition instanceof VersionConditionNode) {
                    continue;
                }

                $ast->accept(new Validator($this->maxLookbehindLength, $pattern, $this->target));
            } catch (SemanticErrorException $versionError) {
                if (($versionError->position ?? $position) < $position) {
                    return $versionError;
                }
            } catch (LexerException|LimitException|ParserException) {
                continue;
            }
        }

        return null;
    }
}
