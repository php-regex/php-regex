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
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;

/**
 * Reads the quantifiers of a pattern for TokenParser: what a quantifier
 * repeats, the one after what PCRE skips, the lazy or possessive modifier
 * "x" lets stand apart, and the quantifiers PCRE refuses, with nothing to
 * repeat, on an item that cannot repeat, or with a count it cannot take.
 *
 * It shares the parser's ParseState, and reads one text, the pattern or a
 * body read apart.
 *
 * @internal
 */
final class QuantifierParser
{
    private readonly TokenStream $stream;

    private readonly string $pattern;

    private readonly PcreTarget $target;

    public function __construct(private readonly ParseState $state)
    {
        $this->stream = $state->stream;
        $this->pattern = $state->pattern;
        $this->target = $state->target;
    }

    /**
     * A quantifier the sequence meets on its own was not taken by the atom
     * before it: PCRE skipped something on the way, a comment, a "\E", an
     * empty "\Q\E" or /x whitespace. It repeats the last item before them
     * all: "a(?#c)*" and "a\E*" are "a*", and "a*\E+" is "a*+". The
     * quantifier goes on the item itself, which every consumer of the tree
     * expects to find right under it, and the comments follow it. With no
     * item before it, the atom parser reports the quantifier.
     *
     * @param array<NodeInterface> $nodes
     */
    public function quantifyPreviousItem(array &$nodes): bool
    {
        if (!$this->stream->check(TokenType::Quantifier)) {
            return false;
        }

        $comments = [];
        $index = \count($nodes) - 1;
        while ($index >= 0 && $nodes[$index] instanceof CommentNode) {
            array_unshift($comments, $nodes[$index]);
            $index--;
        }

        if ($index < 0) {
            return false;
        }

        $token = $this->stream->current();
        $this->stream->advance();

        $target = $nodes[$index];
        array_splice($nodes, $index);

        $last = $target instanceof SequenceNode ? $target->children[array_key_last($target->children)] ?? null : null;
        if ($target instanceof QuantifierNode) {
            $nodes[] = $this->modifyRepeatedQuantifier($target, $token);
        } elseif ($target instanceof SequenceNode && $last instanceof QuantifierNode) {
            // "é+" without /u, or "\1000*": the text before the character the
            // quantifier took, then that one repeated, which a quantifier
            // after it finds.
            $modified = $this->modifyRepeatedQuantifier($last, $token);
            $nodes[] = new SequenceNode([...\array_slice($target->children, 0, -1), $modified], $target->getStartPosition(), $modified->getEndPosition());
        } elseif ($target instanceof SequenceNode && null !== $last) {
            // "\1000(?#c)+": the octal "\100" then "0", and only the "0"
            // repeats, as it does written right after it.
            $this->assertQuantifierCanApply($last, $token);
            $quantified = $this->quantify($last, $token);
            $nodes[] = new SequenceNode([...\array_slice($target->children, 0, -1), $quantified], $target->getStartPosition(), $quantified->getEndPosition());
        } else {
            // "\Qab\E*" is "ab*": only the last quoted character repeats.
            [$prefix, $target] = $this->splitRepeatedCharacter($target);
            if (null !== $prefix) {
                $nodes[] = $prefix;
            }

            $this->assertQuantifierCanApply($target, $token);
            $nodes[] = $this->quantify($target, $token);
        }

        array_push($nodes, ...$comments);

        return true;
    }

    /**
     * The atom just read, repeated by the quantifier $token read after it:
     * the quantifier takes its last character, the text before it left
     * as it is.
     */
    public function repeat(NodeInterface $node, Token $token): NodeInterface
    {
        [$prefix, $node] = $this->splitRepeatedCharacter($node);
        $this->assertQuantifierCanApply($node, $token);
        $quantified = $this->quantify($node, $token);

        return null === $prefix ? $quantified : new SequenceNode([$prefix, $quantified], $prefix->getStartPosition(), $quantified->getEndPosition());
    }

    /**
     * Digits left as text after an octal escape: a quantifier after them
     * repeats the last one.
     */
    public function parseQuantifiedText(NodeInterface $text): NodeInterface
    {
        if (!$text instanceof LiteralNode || !$this->stream->check(TokenType::Quantifier)) {
            return $text;
        }

        $token = $this->stream->current();
        $this->stream->advance();
        $lastStart = $text->endPosition - 1;
        $last = $this->quantify(new LiteralNode(substr($text->value, -1), $lastStart, $text->endPosition), $token);
        if (1 === \strlen($text->value)) {
            return $last;
        }

        $head = new LiteralNode(substr($text->value, 0, -1), $text->startPosition, $lastStart);

        return new SequenceNode([$head, $last], $text->startPosition, $last->getEndPosition());
    }

    /**
     * The error of a quantifier where an item is due, the token under the
     * cursor: it repeats nothing, or it is the "*" of a verb no ")" closes.
     *
     * @throws ParserException
     */
    public function refuseQuantifierWithoutTarget(): never
    {
        $position = $this->stream->current()->position;

        $this->guardQuantifierCount($this->stream->current());

        // "(*MARK:a" with no ")" is a verb PCRE reads to the end.
        $unclosedVerb = $this->startsUnclosedVerb($position);
        if ($unclosedVerb && '*' === $this->pattern[$position + 1]) {
            throw PcreVerb::doubleStarError($this->pattern, $position + 1);
        }

        if ($unclosedVerb) {
            $nameError = PcreVerb::unclosedNameError($this->pattern, $position - 1, $this->target);
            if (null !== $nameError) {
                throw $nameError;
            }
        }

        $namePosition = $position + 1;
        $position = $unclosedVerb
            ? PcreVerb::unclosedOffset($this->pattern, $position - 1, $this->target, $this->state->unicodeMode)
            : RepeatCount::nothingToRepeatOffset($this->stream->current(), $this->target);

        // A name that starts with a lowercase letter is an alphabetic
        // assertion PCRE does not know, wherever it ends before PCRE2
        // 10.47, and from 10.47 unless the pattern ends with it.
        if ($unclosedVerb && 1 === LibraryPcre::match('/\G[a-z]/', $this->pattern, $letter, 0, $namePosition)
            && (!$this->supports(PcreFeature::AlphaNameAtPatternEndIsUnclosed) || 1 !== LibraryPcre::match('/\G\w++\z/', $this->pattern, $name, 0, $namePosition))) {
            throw $this->state->error(
                \sprintf('Unknown alphabetic assertion "(*%s" at position %d.', substr($this->pattern, $namePosition, $position - $namePosition), $position),
                ErrorCode::VerbInvalid,
                $position,
            );
        }

        throw $unclosedVerb
            ? $this->state->error(\sprintf('Missing ")" to close the verb at position %d.', $position), ErrorCode::VerbUnclosed, $position)
            : $this->state->error(\sprintf('Quantifier without target at position %d.', $position), ErrorCode::QuantifierNothingToRepeat, $position);
    }

    /**
     * Before PCRE2 10.37, the digits after a "{" read as text are still read
     * as a count, before anything checks that a "}" closes it, and one past
     * 65535 is refused there: "a{65536", "a{65536 }". PCRE2 reads the "{"
     * where the pattern reads an item, so a name or a condition it stands
     * in is refused first.
     *
     * @param Token $token a literal whose text in the pattern starts with "{"
     *
     * @throws ParserException
     */
    public function guardOpenCountBeforeTen37(Token $token): void
    {
        if ($this->state->inQuoteMode || $this->supports(PcreFeature::LargeOpenCountIsText)) {
            return;
        }

        $overflow = RepeatCount::overflowBeforeTen37($this->pattern, $token->position);
        if (null !== $overflow) {
            throw $this->state->error(
                \sprintf('Number too big in "{" at position %d: PCRE2 before 10.37 reads the digits after it as a count, at most 65535.', $overflow),
                ErrorCode::QuantifierTooBig,
                $overflow,
            );
        }
    }

    /**
     * Splits off the text a quantifier does not repeat: the quantifier takes
     * the last character, so "\Qab\E*" repeats "b" and, without /u, "é+"
     * repeats the last byte of "é".
     *
     * @return array{0: ?LiteralNode, 1: NodeInterface}
     */
    private function splitRepeatedCharacter(NodeInterface $node): array
    {
        if (!$node instanceof LiteralNode || '' === $prefix = $this->withoutLastCharacter($node->value)) {
            return [null, $node];
        }

        $split = $node->getStartPosition() + \strlen($prefix);

        return [
            new LiteralNode($prefix, $node->getStartPosition(), $split, $node->isRaw),
            new LiteralNode(substr($node->value, \strlen($prefix)), $split, $node->getEndPosition(), $node->isRaw),
        ];
    }

    /**
     * The text up to its last character: a code point in UTF-8 mode, a byte
     * otherwise, as PCRE counts them.
     */
    private function withoutLastCharacter(string $text): string
    {
        if ($this->state->unicodeMode && 1 === LibraryPcre::match('/.\z/su', $text, $matches)) {
            return substr($text, 0, -\strlen($matches[0]));
        }

        return substr($text, 0, -1);
    }

    /**
     * After a quantified item, a lone "+" or "?" past what PCRE skips makes a
     * greedy quantifier possessive or lazy, as it would written right after
     * it. Anything else is a second quantifier on the same item.
     */
    private function modifyRepeatedQuantifier(QuantifierNode $target, Token $token): QuantifierNode
    {
        $modifier = $token->value[0];

        if (QuantifierType::Greedy !== $target->type || !\in_array($modifier, ['+', '?'], true)) {
            $this->guardQuantifierCount($token);
            $position = RepeatCount::nothingToRepeatOffset($token, $this->target);

            throw $this->state->error(
                \sprintf('Quantifier without target at position %d.', $position),
                ErrorCode::QuantifierNothingToRepeat,
                $position,
            );
        }

        // "a*(?#c)++": the first "+" is the modifier, the second repeats nothing.
        if (\strlen($token->value) > 1) {
            $position = $this->target->faultOffset($token->position + \strlen($token->value));

            throw $this->state->error(
                \sprintf('Quantifier without target at position %d.', $position),
                ErrorCode::QuantifierNothingToRepeat,
                $position,
            );
        }

        return new QuantifierNode(
            $target->node,
            $target->quantifier,
            '+' === $modifier ? QuantifierType::Possessive : QuantifierType::Lazy,
            $target->getStartPosition(),
            $token->position + 1,
        );
    }

    private function quantify(NodeInterface $node, Token $token): QuantifierNode
    {
        [$quantifier, $type] = $this->parseQuantifierValue($token->value);

        $startPosition = $node->getStartPosition();
        $endPosition = $token->position + \strlen($token->value);

        // In extended (/x) mode, whitespace may separate a quantifier from
        // its lazy/possessive modifier: "a* +" means "a*+" to PCRE. A line
        // comment there is kept by the sequence, which then applies the
        // modifier after it to this quantifier.
        if (QuantifierType::Greedy === $type && $this->state->extendedMode && !$this->state->inQuoteMode) {
            $skippedModifier = $this->state->skipExtendedWhitespace();
            if ($this->stream->check(TokenType::Quantifier) && \in_array($this->stream->current()->value, ['+', '?'], true)) {
                $modifier = $this->stream->current()->value;
                $type = '+' === $modifier ? QuantifierType::Possessive : QuantifierType::Lazy;
                $endPosition = $this->stream->current()->position + 1;
                $this->stream->advance();
            } elseif ($skippedModifier > 0) {
                $this->stream->rewind($skippedModifier);
            }
        }

        return new QuantifierNode($node, $quantifier, $type, $startPosition, $endPosition);
    }

    /**
     * @return array{0: string, 1: QuantifierType}
     */
    private function parseQuantifierValue(string $value): array
    {
        $lastChar = substr($value, -1);
        $baseValue = substr($value, 0, -1);

        if ('?' === $lastChar && \strlen($value) > 1) {
            return [$baseValue, QuantifierType::Lazy];
        }

        if ('+' === $lastChar && \strlen($value) > 1) {
            return [$baseValue, QuantifierType::Possessive];
        }

        return [$value, QuantifierType::Greedy];
    }

    private function assertQuantifierCanApply(NodeInterface $node, Token $token): void
    {
        $position = RepeatCount::nothingToRepeatOffset($token, $this->target);

        // A callout matches nothing, and PCRE does not let it be repeated.
        if ($node instanceof CalloutNode) {
            $this->guardQuantifierCount($token);

            throw $this->state->error(
                \sprintf('Quantifier does not follow a repeatable item at position %d: a callout cannot be repeated.', $position),
                ErrorCode::QuantifierNothingToRepeat,
                $position,
            );
        }

        if ($this->isEmptyNode($node)) {
            $this->guardQuantifierCount($token);

            throw $this->state->error(
                \sprintf('Quantifier without target at position %d.', $position),
                ErrorCode::QuantifierNothingToRepeat,
                $position,
            );
        }

        // From PCRE2 10.34 PCRE lets "(*ACCEPT)" be repeated, "(*ACCEPT)??"
        // included: it wraps it in a group. No other verb takes a
        // quantifier. An alphabetic name PCRE does not know, as "(*scs:"
        // before PCRE2 10.45, is refused where it ends, before its
        // quantifier is read: the validator reports it.
        $isAccept = $node instanceof PcreVerbNode && 1 === LibraryPcre::match('/^ACCEPT(?::|$)/', $node->verb);
        $isUnknownName = $node instanceof PcreVerbNode && 1 === LibraryPcre::match('/^[a-z]/', $node->verb);

        if (!($isAccept && $this->supports(PcreFeature::QuantifiedAccept)) && !$isUnknownName && $this->isAssertionNode($node)) {
            $this->guardQuantifierCount($token);
            $nodeName = $this->getAssertionNodeName($node);

            throw $this->state->error(
                \sprintf('Quantifier "%s" cannot be applied to assertion or verb "%s" at position %d%s.',
                    $token->value, $nodeName, $position, $isAccept ? ': PCRE2 10.34 lets "(*ACCEPT)" be repeated; write "(?:(*ACCEPT))'.$token->value.'", which every release reads' : ''),
                ErrorCode::QuantifierNothingToRepeat,
                $position,
            );
        }
    }

    private function getAssertionNodeName(NodeInterface $node): string
    {
        $backslash = '\\';

        return match (true) {
            $node instanceof AnchorNode => $node->value,
            $node instanceof AssertionNode => $backslash.$node->value,
            $node instanceof PcreVerbNode => '(*'.$node->verb.')',
            $node instanceof LimitMatchNode => '(*LIMIT_MATCH='.$node->limit.')',
            default => $backslash.'K',
        };
    }

    /**
     * PCRE reads the numbers of a braced count before it asks whether the
     * item before it can repeat: "{2,1}" is out of order and "{65536}" too
     * big wherever they stand.
     *
     * @throws ParserException
     */
    private function guardQuantifierCount(Token $token): void
    {
        $countError = RepeatCount::faultOffset($token, $this->target);
        if (null !== $countError) {
            throw $this->state->error(
                \sprintf('Invalid quantifier "%s" at position %d: its numbers are out of order or past 65535.', $token->value, $countError),
                RepeatCount::faultCode($token->value),
                $countError,
            );
        }
    }

    /**
     * Whether the "*" at $position follows the "(" that opens the group it
     * is in, as in "(*MARK:a" the lexer read as no verb for want of ")". A
     * "(*" that ends the pattern names no verb: its "*" repeats nothing.
     */
    private function startsUnclosedVerb(int $position): bool
    {
        $previous = $this->stream->previous();

        return '*' === ($this->pattern[$position] ?? '')
            && $position + 1 < \strlen($this->pattern)
            && TokenType::GroupOpen === $previous->type
            && $previous->end() === $position;
    }

    /**
     * Checks if a node represents an empty value (empty literal or empty sequence/group).
     */
    private function isEmptyNode(NodeInterface $node): bool
    {
        return ($node instanceof LiteralNode && '' === $node->value)
            || ($node instanceof GroupNode && $this->isOptionSetting($node))
            || ($node instanceof SequenceNode && empty($node->children));
    }

    /**
     * "(?i)" changes options and matches nothing, so it cannot be repeated.
     * Any other group can, even an empty one: "(){3}" and "(?i:)*" are
     * valid PCRE.
     */
    private function isOptionSetting(GroupNode $node): bool
    {
        return GroupType::InlineFlags === $node->type
            && ')' === ($this->pattern[$node->getStartPosition() + 2 + \strlen((string) $node->flags)] ?? ')');
    }

    /**
     * Checks if a node is an assertion type that cannot have quantifiers.
     */
    private function isAssertionNode(NodeInterface $node): bool
    {
        return $node instanceof AnchorNode
            || $node instanceof AssertionNode
            || $node instanceof PcreVerbNode
            || $node instanceof LimitMatchNode
            || $node instanceof KeepNode;
    }

    private function supports(PcreFeature $feature): bool
    {
        return $this->target->supports($feature);
    }
}
