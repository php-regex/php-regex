<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser;

use RegexParser\Exception\LexerException;
use RegexParser\Exception\ParserException;
use RegexParser\Exception\RecursionLimitException;
use RegexParser\Exception\SyntaxErrorException;
use RegexParser\Internal\CodePointReader;
use RegexParser\Internal\GroupNameReader;
use RegexParser\Internal\InlineFlags;
use RegexParser\Internal\PcreVerb;
use RegexParser\Internal\VersionCondition;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharLiteralType;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\CommentNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\KeepNode;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\NodeInterface;
use RegexParser\Node\PcreVerbNode;
use RegexParser\Node\PosixClassNode;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\QuantifierType;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\ScriptRunNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Node\SubroutineNode;
use RegexParser\Node\UnicodePropNode;
use RegexParser\Node\VersionConditionNode;

/**
 * Recursive descent parser for regex patterns.
 *
 * This parser uses intelligent caching, reduced method calls, and
 * streamlined parsing logic for efficiency while maintaining full
 * compatibility with PCRE syntax.
 */
final class Parser
{
    private const INLINE_FLAG_LETTERS = InlineFlags::LETTERS;
    private const MAX_RECURSION_DEPTH = 1024;

    // Token length constants for calculating positions
    private const PCRE_VERB_WRAPPER_LENGTH = 3; // (*...)
    private const CALLOUT_WRAPPER_LENGTH = 4; // (?C...)

    /**
     * The two lookaheads, by the character that introduces them.
     */
    private const LOOKAHEADS = [
        '=' => GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
        '!' => GroupType::T_GROUP_LOOKAHEAD_NEGATIVE,
    ];

    /**
     * The two lookbehinds, which follow a "<".
     */
    private const LOOKBEHINDS = [
        '=' => GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
        '!' => GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
    ];

    /**
     * Token types that describe a character the same way wherever they appear.
     */
    private const ATOM_TYPES = [
        TokenType::T_LITERAL,
        TokenType::T_LITERAL_ESCAPED,
        TokenType::T_CHAR_TYPE,
        TokenType::T_UNICODE_PROP,
        TokenType::T_CONTROL_CHAR,
        TokenType::T_UNICODE,
        TokenType::T_UNICODE_NAMED,
        TokenType::T_OCTAL,
        TokenType::T_OCTAL_LEGACY,
    ];

    /**
     * Atoms a character class cannot hold: inside "[...]" a "^" is a literal
     * or a negation, and "\1" is an octal escape, so a class that receives
     * one of these has been built by hand rather than by the lexer.
     */
    /**
     * The delimiters PCRE2 takes around the string of a callout, each with
     * the one that closes it.
     */
    private const CALLOUT_STRING_DELIMITERS = [
        '`' => '`', "'" => "'", '"' => '"', '^' => '^', '%' => '%', '#' => '#', '$' => '$', '{' => '}',
    ];

    /**
     * The marker a lookaround carries in its flags when it is non-atomic,
     * "(?*...)" or "(*napla:...)": PCRE may backtrack into it. It is the
     * character that spells it in the short form.
     */
    private const NON_ATOMIC_FLAG = '*';

    private const OUTSIDE_ATOM_TYPES = [
        TokenType::T_ANCHOR,
        TokenType::T_ASSERTION,
        TokenType::T_BACKREF,
    ];

    private TokenStream $stream;

    private GroupNameReader $groupNames;

    private string $pattern = '';

    private string $flags = '';

    private bool $extendedMode = false;

    /**
     * Whether "n" (NO_AUTO_CAPTURE) is in force: a plain "(...)" group then
     * captures nothing and takes no number; named groups still capture.
     */
    private bool $noAutoCapture = false;

    private bool $inQuoteMode = false;

    /**
     * Whether the pattern is read as UTF-8: "u", or "(*UTF)" at the start.
     */
    private bool $unicodeMode = false;

    private int $recursionDepth = 0;

    /**
     * The number the last capturing group was given, counted the way PCRE
     * counts them, branch resets included: a name is tied to a number.
     */
    private int $captureCount = 0;

    /**
     * @var array<int|string, bool>
     */
    private static array $supportsPcre1043Modifiers = [];

    private readonly int $maxRecursionDepth;

    private readonly int $phpVersionId;

    private readonly bool $useRuntimePcreDetection;

    public function __construct(?int $maxRecursionDepth = null, ?int $phpVersionId = null)
    {
        $this->maxRecursionDepth = $maxRecursionDepth ?? self::MAX_RECURSION_DEPTH;
        $this->phpVersionId = $phpVersionId ?? \PHP_VERSION_ID;
        $this->useRuntimePcreDetection = null === $phpVersionId;
    }

    public function parse(TokenStream $stream, string $flags = '', string $delimiter = '/', int $patternLength = 0): RegexNode
    {
        $this->stream = $stream;
        $this->pattern = $stream->getPattern();
        $this->flags = $flags;
        $this->groupNames = new GroupNameReader($stream);
        $this->groupNames->allowDuplicates(str_contains($flags, 'J'));
        // Group names take any letter in Unicode mode: "u", or "(*UTF)" at
        // the start.
        $this->unicodeMode = str_contains($flags, 'u')
            || 1 === preg_match('/\A(?:\(\*[A-Z_]++(?:=\d++)?\))*?\(\*UTF8?\)/', $this->pattern);
        $this->groupNames->readUnicodeNames($this->unicodeMode);
        $this->extendedMode = str_contains($flags, 'x');
        $this->noAutoCapture = str_contains($flags, 'n');
        $this->inQuoteMode = false;
        $this->recursionDepth = 0;
        $this->captureCount = 0;

        $patternNode = $this->parseAlternation();

        // A ")" no group opened. pcre2test 10.45 still reports it on the
        // ")", PCRE2 10.48 past it; the releases between are taken as the
        // newer one.
        if ($this->stream->check(TokenType::T_GROUP_CLOSE)) {
            $position = $this->stream->current()->position + ($this->runningPcreAtLeast('10.46') ? 1 : 0);

            throw $this->parserException(\sprintf('Unmatched closing parenthesis at position %d.', $position), $position);
        }

        $this->stream->consume(TokenType::T_EOF, 'Unexpected content at end of pattern');

        return new RegexNode($patternNode, $flags, $delimiter, 0, $patternLength, $this->pattern);
    }

    /**
     * Parse the body of a group. A "(?x)" setting holds until the end of the
     * enclosing group — crossing "|" — so the mode is restored here and not
     * per alternation branch, the way PCRE scopes it.
     */
    private function parseScopedAlternation(): NodeInterface
    {
        $extendedMode = $this->extendedMode;
        $noAutoCapture = $this->noAutoCapture;
        $duplicateNames = $this->groupNames->duplicatesAllowed();

        try {
            return $this->parseAlternation();
        } finally {
            $this->extendedMode = $extendedMode;
            $this->noAutoCapture = $noAutoCapture;
            $this->groupNames->allowDuplicates($duplicateNames);
        }
    }

    private function parseAlternation(): NodeInterface
    {
        $this->guardRecursionDepth($this->stream->current()->position);
        $this->recursionDepth++;

        try {
            $startPosition = $this->stream->current()->position;
            $nodes = [$this->parseSequence()];

            while ($this->stream->match(TokenType::T_ALTERNATION)) {
                $nodes[] = $this->parseSequence();
            }

            if (1 === \count($nodes)) {
                return $nodes[0];
            }

            $endPosition = end($nodes)->getEndPosition();

            return new AlternationNode($nodes, $startPosition, $endPosition);
        } finally {
            $this->recursionDepth--;
        }
    }

    private function parseSequence(): NodeInterface
    {
        $nodes = [];
        $quotedRun = null;
        $startPosition = $this->stream->current()->position;

        while (!$this->stream->isAtEnd() && !$this->stream->check(TokenType::T_GROUP_CLOSE) && !$this->stream->check(TokenType::T_ALTERNATION)) {
            if ($this->stream->match(TokenType::T_QUOTE_MODE_START)) {
                $this->inQuoteMode = true;

                continue;
            }
            if ($this->stream->match(TokenType::T_QUOTE_MODE_END)) {
                $this->inQuoteMode = false;

                continue;
            }

            // In extended (/x) mode, consume whitespace and line comments as
            // explicit nodes where appropriate so we can preserve them when
            // reconstructing the pattern.
            if ($this->consumeExtendedModeContent($nodes)) {
                continue;
            }

            if ($this->quantifyPreviousItem($nodes, $quotedRun)) {
                continue;
            }

            $inQuoteMode = $this->inQuoteMode;
            $nodes[] = $node = $this->parseQuantifiedAtom();

            if ($inQuoteMode && $node instanceof LiteralNode) {
                $quotedRun = $node;
            }
        }

        if (empty($nodes)) {
            return $this->createEmptyLiteralNodeAt($startPosition);
        }

        if (1 === \count($nodes)) {
            return $nodes[0];
        }

        $endPosition = end($nodes)->getEndPosition();

        return new SequenceNode($nodes, $startPosition, $endPosition);
    }

    /**
     * Consume extended-mode (/x) whitespace and comments at the current
     * position, adding any comments as CommentNode instances into the
     * provided node list. This is used at the sequence level so that /x
     * comments are preserved in the AST with accurate positions.
     *
     * @param array<Node\NodeInterface> $nodes
     */
    private function consumeExtendedModeContent(array &$nodes): bool
    {
        if (!$this->extendedMode || $this->inQuoteMode) {
            return false;
        }

        $skipped = false;
        while (!$this->stream->isAtEnd() && !$this->stream->check(TokenType::T_GROUP_CLOSE) && !$this->stream->check(TokenType::T_ALTERNATION)) {
            $token = $this->stream->current();
            if (TokenType::T_LITERAL !== $token->type) {
                break;
            }

            // Skip pure whitespace silently; comments will be explicit nodes.
            if ($this->isExtendedWhitespace($token->value)) {
                $this->stream->advance();
                $skipped = true;

                continue;
            }

            // Line comment starting with # until end-of-line.
            if ('#' === $token->value) {
                $nodes[] = $this->parseExtendedComment();
                $skipped = true;

                continue;
            }

            break;
        }

        return $skipped;
    }

    /**
     * Parse an extended-mode line comment (starting at '#') into a CommentNode,
     * preserving the exact text and byte offsets.
     */
    private function parseExtendedComment(): CommentNode
    {
        $startToken = $this->stream->current(); // '#'
        $startPosition = $startToken->position;

        $comment = $this->sourceTextOf($startToken);
        $this->stream->advance();

        while (!$this->stream->isAtEnd()) {
            $token = $this->stream->current();

            // Comment ends at newline (included) or at end of pattern.
            if (TokenType::T_LITERAL === $token->type && "\n" === $token->value) {
                $comment .= $this->sourceTextOf($token);
                $this->stream->advance();

                break;
            }

            $comment .= $this->sourceTextOf($token);
            $this->stream->advance();
        }

        $endPosition = $startPosition + \strlen($comment);

        return new CommentNode($comment, $startPosition, $endPosition, true);
    }

    /**
     * Skip extended-mode (/x) whitespace and comments *without* producing
     * nodes. This is used where the parser needs to see through trivia,
     * for example between an atom and its following quantifier.
     */
    private function skipExtendedModeContent(): int
    {
        if (!$this->extendedMode || $this->inQuoteMode) {
            return 0;
        }

        $skipped = 0;
        while (!$this->stream->isAtEnd() && !$this->stream->check(TokenType::T_GROUP_CLOSE) && !$this->stream->check(TokenType::T_ALTERNATION)) {
            $token = $this->stream->current();
            if (TokenType::T_LITERAL !== $token->type) {
                break;
            }

            if ($this->isExtendedWhitespace($token->value)) {
                $this->stream->advance();
                $skipped++;

                continue;
            }

            if ('#' === $token->value) {
                $this->stream->advance();
                $skipped++;
                while (!$this->stream->isAtEnd() && "\n" !== $this->stream->current()->value) {
                    $this->stream->advance();
                    $skipped++;
                }
                if (!$this->stream->isAtEnd() && "\n" === $this->stream->current()->value) {
                    $this->stream->advance();
                    $skipped++;
                }

                continue;
            }

            break;
        }

        return $skipped;
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
     * @param LiteralNode|null     $quotedRun the last run of text read between \Q and \E
     */
    private function quantifyPreviousItem(array &$nodes, ?LiteralNode $quotedRun): bool
    {
        if (!$this->stream->check(TokenType::T_QUANTIFIER)) {
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

        if ($target instanceof QuantifierNode) {
            $nodes[] = $this->modifyRepeatedQuantifier($target, $token);
        } else {
            // "\Qab\E*" is "ab*": only the last quoted character repeats.
            if ($target === $quotedRun && '' !== $prefix = $this->withoutLastCharacter($quotedRun->value)) {
                $split = $quotedRun->getStartPosition() + \strlen($prefix);
                $nodes[] = new LiteralNode($prefix, $quotedRun->getStartPosition(), $split, $quotedRun->isRaw);
                $target = new LiteralNode(substr($quotedRun->value, \strlen($prefix)), $split, $quotedRun->getEndPosition(), $quotedRun->isRaw);
            }

            $this->assertQuantifierCanApply($target, $token);
            $nodes[] = $this->quantify($target, $token);
        }

        array_push($nodes, ...$comments);

        return true;
    }

    /**
     * The text up to its last character: a code point in UTF-8 mode, a byte
     * otherwise, as PCRE counts them.
     */
    private function withoutLastCharacter(string $text): string
    {
        if ($this->unicodeMode && 1 === preg_match('/.\z/su', $text, $matches)) {
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

        if (QuantifierType::T_GREEDY !== $target->type || !\in_array($modifier, ['+', '?'], true)) {
            $position = $this->quantifierErrorOffset($token);

            throw $this->parserException(
                \sprintf('Quantifier without target at position %d', $position),
                $position,
            );
        }

        // "a*(?#c)++": the first "+" is the modifier, the second repeats nothing.
        if (\strlen($token->value) > 1) {
            $position = $token->position + \strlen($token->value);

            throw $this->parserException(
                \sprintf('Quantifier without target at position %d', $position),
                $position,
            );
        }

        return new QuantifierNode(
            $target->node,
            $target->quantifier,
            '+' === $modifier ? QuantifierType::T_POSSESSIVE : QuantifierType::T_LAZY,
            $target->getStartPosition(),
            $token->position + 1,
        );
    }

    private function parseQuantifiedAtom(): NodeInterface
    {
        $node = $this->parseAtom();

        // A quantifier after a comment repeats the item before the comment.
        if ($node instanceof CommentNode) {
            return $node;
        }

        $skipped = $this->skipExtendedModeContent();

        if ($this->stream->match(TokenType::T_QUANTIFIER)) {
            $token = $this->stream->previous();

            $this->assertQuantifierCanApply($node, $token);

            return $this->quantify($node, $token);
        }

        if ($skipped > 0) {
            $this->stream->rewind($skipped);
        }

        return $node;
    }

    private function quantify(NodeInterface $node, Token $token): QuantifierNode
    {
        [$quantifier, $type] = $this->parseQuantifierValue($token->value);

        $startPosition = $node->getStartPosition();
        $endPosition = $token->position + \strlen($token->value);

        // In extended (/x) mode, whitespace may separate a quantifier from
        // its lazy/possessive modifier: "a* +" means "a*+" to PCRE.
        if (QuantifierType::T_GREEDY === $type && $this->extendedMode && !$this->inQuoteMode) {
            $skippedModifier = $this->skipExtendedModeContent();
            if ($this->stream->check(TokenType::T_QUANTIFIER) && \in_array($this->stream->current()->value, ['+', '?'], true)) {
                $modifier = $this->stream->current()->value;
                $type = '+' === $modifier ? QuantifierType::T_POSSESSIVE : QuantifierType::T_LAZY;
                $endPosition = $this->stream->current()->position + 1;
                $this->stream->advance();
            } elseif ($skippedModifier > 0) {
                $this->stream->rewind($skippedModifier);
            }
        }

        return new QuantifierNode($node, $quantifier, $type, $startPosition, $endPosition);
    }

    /**
     * @return array{0: string, 1: Node\QuantifierType}
     */
    private function parseQuantifierValue(string $value): array
    {
        $lastChar = substr($value, -1);
        $baseValue = substr($value, 0, -1);

        if ('?' === $lastChar && \strlen($value) > 1) {
            return [$baseValue, QuantifierType::T_LAZY];
        }

        if ('+' === $lastChar && \strlen($value) > 1) {
            return [$baseValue, QuantifierType::T_POSSESSIVE];
        }

        return [$value, QuantifierType::T_GREEDY];
    }

    private function assertQuantifierCanApply(NodeInterface $node, Token $token): void
    {
        $position = $this->quantifierErrorOffset($token);

        // A callout matches nothing, and PCRE does not let it be repeated.
        if ($node instanceof CalloutNode) {
            throw $this->parserException(
                \sprintf('Quantifier does not follow a repeatable item at position %d: a callout cannot be repeated.', $position),
                $position,
            );
        }

        if ($this->isEmptyNode($node)) {
            throw $this->parserException(
                \sprintf('Quantifier without target at position %d', $position),
                $position,
            );
        }

        // PCRE lets "(*ACCEPT)" be repeated, "(*ACCEPT)??" included: it wraps
        // it in a group. No other verb takes a quantifier.
        $isAccept = $node instanceof PcreVerbNode && 1 === preg_match('/^ACCEPT(?::|$)/', $node->verb);

        if (!$isAccept && $this->isAssertionNode($node)) {
            $nodeName = $this->getAssertionNodeName($node);

            throw $this->parserException(
                \sprintf('Quantifier "%s" cannot be applied to assertion or verb "%s" at position %d',
                    $token->value, $nodeName, $position),
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

    private function parseAtom(): NodeInterface
    {
        $token = $this->stream->current();
        $startPosition = $token->position;

        if ($this->stream->match(TokenType::T_COMMENT_OPEN)) {
            return $this->parseComment();
        }

        if ($this->stream->match(TokenType::T_CALLOUT)) {
            return $this->parseCallout();
        }

        if ($this->stream->match(TokenType::T_QUOTE_MODE_START)) {
            $this->inQuoteMode = true;

            return $this->parseAtom();
        }
        if ($this->stream->match(TokenType::T_QUOTE_MODE_END)) {
            $this->inQuoteMode = false;

            return $this->parseAtom();
        }

        if (null !== $node = $this->parseSimpleAtom($startPosition)) {
            return $node;
        }

        if (null !== $node = $this->parseGroupOrCharClassAtom()) {
            return $node;
        }

        if (null !== $node = $this->parseVerbAtom($startPosition)) {
            return $node;
        }

        if ($this->stream->check(TokenType::T_QUANTIFIER)) {
            $position = $this->stream->current()->position;

            // "(*MARK:a" with no ")" is a verb PCRE reads to the end.
            $position = $this->startsUnclosedVerb($position)
                ? $this->unclosedVerbOffset($position - 1)
                : $this->quantifierErrorOffset($this->stream->current());

            throw $this->parserException(
                \sprintf('Quantifier without target at position %d', $position),
                $position,
            );
        }

        $val = $this->stream->current()->value;
        $type = $this->stream->current()->type->value;

        throw $this->parserException(
            \sprintf('Unexpected token "%s" (%s) at position %d.', $val, $type, $startPosition),
            $startPosition,
        );
    }

    private function parseSimpleAtom(int $startPosition): ?NodeInterface
    {
        $this->guardNamedReferenceEscape();

        if (null !== $atom = $this->matchAtom($startPosition, self::OUTSIDE_ATOM_TYPES)) {
            return $atom;
        }

        if ($this->stream->match(TokenType::T_DOT)) {
            return new DotNode($startPosition, $this->stream->previous()->end());
        }

        if ($this->stream->match(TokenType::T_G_REFERENCE)) {
            return $this->parseGReference($startPosition);
        }

        if ($this->stream->match(TokenType::T_KEEP)) {
            return new KeepNode($startPosition, $this->stream->previous()->end());
        }

        return null;
    }

    /**
     * "\k" names a group, and the lexer leaves it a plain escaped letter when
     * no well-formed name follows. PCRE refuses every such shape, and says
     * where it stopped reading: on the opener's absence, where a name should
     * start, past a leading digit, or where the closer should be.
     *
     * @throws ParserException
     */
    private function guardNamedReferenceEscape(): void
    {
        $token = $this->stream->current();
        if (TokenType::T_LITERAL_ESCAPED !== $token->type || 'k' !== $token->value || $this->inQuoteMode) {
            return;
        }

        $opener = $this->pattern[$token->position + 2] ?? '';
        $closer = ['<' => '>', '{' => '}', "'" => "'"][$opener] ?? null;

        if (null === $closer) {
            throw $this->parserException(
                \sprintf('\k is not followed by a braced, angle-bracketed or quoted name at position %d.', $token->position + 2),
                $token->position + 2,
            );
        }

        $nameStart = $token->position + 3;
        $nameEnd = $this->groupNames->invalidNameOffset($nameStart);
        $digit = $this->unicodeMode ? '/\G\p{Nd}/u' : '/\G[0-9]/';

        if (1 === preg_match($digit, $this->pattern, $matches, 0, $nameStart)) {
            throw $this->parserException(
                \sprintf('Group name after \k%s must not start with a digit at position %d.', $opener, $nameEnd),
                $nameEnd,
            );
        }

        if ($nameEnd === $nameStart) {
            throw $this->parserException(
                \sprintf('Group name expected after \k%s at position %d.', $opener, $nameStart),
                $nameStart,
            );
        }

        if ($closer !== ($this->pattern[$nameEnd] ?? '')) {
            throw $this->parserException(
                \sprintf('Missing "%s" to close the group name after \k%s at position %d.', $closer, $opener, $nameEnd),
                $nameEnd,
            );
        }
    }

    /**
     * Read the next token if it describes a character, and turn it into a node.
     *
     * These are the atoms whose meaning does not depend on where they are
     * written: "\d" is the same inside a class and outside it. What differs
     * between the two contexts is the rest — a dot, a subroutine call, a
     * range — and that is handled by the callers.
     */
    /**
     * @param list<TokenType> $extraTypes atoms the calling context also accepts
     */
    private function matchAtom(int $startPosition, array $extraTypes = []): ?NodeInterface
    {
        foreach ([...self::ATOM_TYPES, ...$extraTypes] as $type) {
            if ($this->stream->match($type)) {
                return $this->atomFromToken($this->stream->previous(), $type, $startPosition);
            }
        }

        return null;
    }

    private function atomFromToken(Token $token, TokenType $type, int $startPosition): NodeInterface
    {
        return match ($type) {
            TokenType::T_LITERAL,
            TokenType::T_LITERAL_ESCAPED => new LiteralNode($token->value, $startPosition, $token->end()),
            TokenType::T_CHAR_TYPE => new CharTypeNode($token->value, $startPosition, $token->end()),
            TokenType::T_ANCHOR => new AnchorNode($token->value, $startPosition, $token->end()),
            TokenType::T_ASSERTION => new AssertionNode($token->value, $startPosition, $token->end()),
            TokenType::T_BACKREF => new BackrefNode(self::withoutBracePadding($token->value), $startPosition, $token->end()),
            TokenType::T_CONTROL_CHAR => new ControlCharNode(
                $token->value,
                CodePointReader::fromControlChar($token->value),
                $startPosition,
                $token->end(),
            ),
            TokenType::T_UNICODE_PROP => new UnicodePropNode(
                $token->value,
                str_starts_with($token->value, '{'),
                $startPosition,
                $token->end(),
                $this->isNegatedPropertySyntax($startPosition),
            ),
            default => $this->createCharLiteralNodeFromToken($token, $type, $startPosition),
        };
    }

    /**
     * Transforms a stream of Tokens into an Abstract Syntax Tree (AST).
     * Implements a Recursive Descent Parser based on PCRE grammar.
     */
    private function parseGroupOrCharClassAtom(): ?NodeInterface
    {
        if ($this->stream->match(TokenType::T_GROUP_OPEN)) {
            $startToken = $this->stream->previous();

            // Under "n" a plain group groups and nothing more, as "(?:...)"
            // does; the compiler gives back the "(" it was written with.
            $captures = !$this->noAutoCapture;
            if ($captures) {
                $this->captureCount++;
            }

            $expr = $this->parseScopedAlternation();
            $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected )');

            return $this->createGroupNode(
                $expr,
                $captures ? GroupType::T_GROUP_CAPTURING : GroupType::T_GROUP_NON_CAPTURING,
                $startToken->position,
                $endToken,
            );
        }

        if ($this->stream->match(TokenType::T_GROUP_MODIFIER_OPEN)) {
            return $this->parseGroupModifier();
        }

        if ($this->stream->match(TokenType::T_CHAR_CLASS_OPEN)) {
            return $this->parseCharClass();
        }

        return null;
    }

    private function parseVerbAtom(int $startPosition): ?NodeInterface
    {
        if (!$this->stream->match(TokenType::T_PCRE_VERB)) {
            return null;
        }

        $token = $this->stream->previous();
        $endPosition = $startPosition + \strlen($token->value) + self::PCRE_VERB_WRAPPER_LENGTH;

        return $this->createPcreVerbNode($token->value, $startPosition, $endPosition);
    }

    /**
     * parses callouts like (?C), (?C1), (?C"name"), (?C"string"), and (?Cname)
     */
    private function parseCallout(): CalloutNode
    {
        $token = $this->stream->previous();
        $startPosition = $token->position;
        $value = $token->value;
        $endPosition = $startPosition + \strlen($token->value) + self::CALLOUT_WRAPPER_LENGTH;

        if ('' === $value) {
            return new CalloutNode(null, false, $startPosition, $endPosition);
        }

        if (ctype_digit($value)) {
            return new CalloutNode((int) $value, false, $startPosition, $endPosition);
        }

        $closing = self::CALLOUT_STRING_DELIMITERS[$value[0]] ?? null;
        if (null === $closing) {
            // "(?Cab)": a callout takes a number or a delimited string.
            $position = $this->calloutErrorOffset($startPosition) ?? $startPosition + 4;

            throw $this->parserException(
                \sprintf('Invalid callout argument: %s at position %d', $value, $position),
                $position,
            );
        }

        // A doubled closing delimiter stands for itself: "(?C{a}}b})".
        $quoted = preg_quote($closing, '/');
        if (1 !== preg_match('/^.((?:[^'.$quoted.']|'.$quoted.$quoted.')*+)'.$quoted.'$/s', $value, $matches)) {
            $position = $this->calloutErrorOffset($startPosition) ?? $startPosition;

            throw $this->parserException(
                \sprintf('Invalid callout argument: %s at position %d', $value, $position),
                $position,
            );
        }

        return new CalloutNode(str_replace($closing.$closing, $closing, $matches[1]), true, $startPosition, $endPosition);
    }

    /**
     * parses \g references (backreferences and subroutines)
     */
    private function parseGReference(int $startPosition): NodeInterface
    {
        $token = $this->stream->previous();
        $endPosition = $startPosition + \strlen($token->value);
        $value = self::withoutBracePadding($token->value);

        // \g{N} or \gN (numeric, incl. relative) -> Backreference; \g'N',
        // like \g<N>, calls the group instead.
        if (preg_match('/^\\\\g(?:\{([0-9+-]++)\}|([0-9+-]++))$/', $value, $m)) {
            return new BackrefNode($value, $startPosition, $endPosition);
        }

        // \g{name} is a back reference, like \k{name}; it is recorded that
        // way, and the compiler gives back the spelling the pattern used.
        if (preg_match('/^\\\\g\{([\p{L}\p{Nd}_]++)\}$/u', $value, $m)) {
            return new BackrefNode('\\k{'.$m[1].'}', $startPosition, $endPosition);
        }

        // \g<name> and \g'name' (non-numeric) call the group -> Subroutine
        if (preg_match('/^\\\\g<([+-]?[\p{L}\p{Nd}_]++)>$/u', $value, $m)) {
            return new SubroutineNode($m[1], 'g', $startPosition, $endPosition);
        }

        if (preg_match('/^\\\\g\'([+-]?[\p{L}\p{Nd}_]++)\'$/u', $value, $m)) {
            return new SubroutineNode($m[1], 'g', $startPosition, $endPosition);
        }

        $position = $this->gReferenceErrorOffset($token->position);

        throw $this->parserException(
            \sprintf('Invalid \\g reference syntax: %s at position %d', $value, $position),
            $position,
        );
    }

    /**
     * "\g{ 1 }" and "\k{ name }" refer to what "\g{1}" and "\k{name}" do:
     * PCRE2 10.43 lets spaces and tabs follow the "{" and precede the "}".
     * Whether the target PCRE2 takes them is the validator's to say.
     */
    private static function withoutBracePadding(string $reference): string
    {
        return preg_replace('/^(\\\\[gk]\{)[ \t]*+(.*?)[ \t]*+\}$/', '$1$2}', $reference) ?? $reference;
    }

    /**
     * Where PCRE stops reading a "\g" it cannot make sense of: right after
     * the "\g" when nothing it knows follows, after a number that nothing
     * closes, or where the characters of a name end.
     *
     * @param int $start the offset of the backslash
     */
    private function gReferenceErrorOffset(int $start): int
    {
        $position = $start + 2;
        $closing = ['<' => '>', "'" => "'", '{' => '}'][$this->pattern[$position] ?? ''] ?? null;

        if (null === $closing) {
            return $position;
        }

        // Only "\g{...}" takes spaces around what it holds.
        $blanks = '}' === $closing ? " \t" : '';
        $position++;
        $position += strspn($this->pattern, $blanks, $position);

        if (1 === preg_match('/\G[+-]?\d++/', $this->pattern, $matches, 0, $position)) {
            $position += \strlen($matches[0]);
        } else {
            $position = $this->groupNames->invalidNameOffset($position);
        }

        return $position + strspn($this->pattern, $blanks, $position);
    }

    /**
     * parses comments like (?# this is a comment )
     */
    private function parseComment(): CommentNode
    {
        $startToken = $this->stream->previous(); // (?#
        $startPosition = $startToken->position;

        $comment = '';
        while (
            !$this->stream->isAtEnd()
            && !$this->stream->check(TokenType::T_GROUP_CLOSE)
        ) {
            $token = $this->stream->current();
            $comment .= $this->sourceTextOf($token);
            $this->stream->advance();
        }

        $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected ) to close comment');
        $endPosition = $endToken->position + 1;

        return new CommentNode($comment, $startPosition, $endPosition);
    }

    /**
     * The text a token was cut from, exactly as the pattern spelled it.
     *
     * The lexer rewrites what it reads — "\d" comes back as "d",
     * "\P{Greek}" as "{^Greek}" — so the value cannot be turned back into
     * source. The token knows its span instead.
     */
    /**
     * Whether a unicode property was written "\P{...}" rather than "\p{...}".
     *
     * The lexer folds the negation into the value — "\P{Greek}" and
     * "\p{^Greek}" arrive as the same token — so which of the two was written
     * can only be read from the pattern.
     */
    private function isNegatedPropertySyntax(int $startPosition): bool
    {
        return 'P' === ($this->pattern[$startPosition + 1] ?? 'p');
    }

    private function sourceTextOf(Token $token): string
    {
        return substr($this->pattern, $token->position, $token->sourceLength);
    }

    private function createCharLiteralNodeFromToken(Token $token, TokenType $type, int $startPosition): CharLiteralNode
    {
        [$representation, $charType] = match ($type) {
            TokenType::T_UNICODE => [$token->value, CharLiteralType::UNICODE],
            TokenType::T_UNICODE_NAMED => ['\\N{'.$token->value.'}', CharLiteralType::UNICODE_NAMED],
            TokenType::T_OCTAL => [$token->value, CharLiteralType::OCTAL],
            TokenType::T_OCTAL_LEGACY => ['\\'.$token->value, CharLiteralType::OCTAL_LEGACY],
            default => throw new \InvalidArgumentException('Unsupported character literal token type.'),
        };

        return new CharLiteralNode(
            $representation,
            CodePointReader::fromLiteral($representation, $charType),
            $charType,
            $startPosition,
            $token->end(),
        );
    }

    /**
     * parses group modifiers like (?=...), (?!...), (?<=...), (?<!...), (?P<name>...), (?P'name'...), (?'name'...),
     * (?P=name), (?:...), (?(...)), (?&name), (?R), (?1), (?-1), (?0), and inline flags.
     */
    private function parseGroupModifier(): NodeInterface
    {
        $startToken = $this->stream->previous();
        $startPosition = $startToken->position;

        // 1. Check for Python-style 'P' groups
        $pPos = $this->stream->current()->position;
        if ($this->stream->matchLiteral('P')) {
            return $this->parsePythonGroup($startPosition, $pPos);
        }

        // 2. Check for PCRE verbs: (*...)
        if ($this->stream->matchLiteral('*')) {
            return $this->parsePcreVerbInGroup($startPosition);
        }

        // 2.0 "(?(?C1)(?=a)yes|no)": a callout may run before the assertion
        // that is the condition.
        if ($this->stream->match(TokenType::T_CALLOUT)) {
            return $this->parseCalloutConditional($startPosition);
        }

        // 2.1 "(?(" followed by "(*...)" is a conditional whose condition is
        // spelled as a verb: "(?(*pla:a)yes|no)".
        if ($this->stream->match(TokenType::T_PCRE_VERB)) {
            return $this->parseVerbConditional($startPosition, $this->stream->previous());
        }

        // 3. PCRE-style quoted named groups (?'name'...)
        if ($this->stream->checkLiteral("'")) {
            return $this->parseNamedGroup($startPosition, false);
        }

        // 4. Check for standard lookarounds and named groups
        if ($this->stream->matchLiteral('<')) {
            return $this->parseStandardGroup($startPosition);
        }

        // 5. Check for conditional (?(...)
        $isConditionalWithModifier = null;
        if ($this->stream->match(TokenType::T_GROUP_MODIFIER_OPEN)) {
            $isConditionalWithModifier = true;
        } elseif ($this->stream->match(TokenType::T_GROUP_OPEN)) {
            $isConditionalWithModifier = false;
        }

        if (null !== $isConditionalWithModifier) {
            return $this->parseConditional($startPosition, $isConditionalWithModifier);
        }

        // 6. Check for Subroutines
        $subroutineModifier = $this->parseSubroutineModifier($startPosition);
        if (null !== $subroutineModifier) {
            return $subroutineModifier;
        }

        $numericSubroutineModifier = $this->parseNumericSubroutineModifier($startPosition);
        if (null !== $numericSubroutineModifier) {
            return $numericSubroutineModifier;
        }

        // 7. Check for simple non-capturing, lookaheads, atomic, branch reset
        $simpleGroupModifier = $this->parseSimpleGroupModifier($startPosition);
        if (null !== $simpleGroupModifier) {
            return $simpleGroupModifier;
        }

        // 8. Inline flags
        return $this->parseInlineFlags($startPosition);
    }

    /**
     * Parses PCRE verbs in group context: (?(*VERB)...)
     */
    private function parsePcreVerbInGroup(int $startPosition): NodeInterface
    {
        $verbStartPosition = $this->stream->current()->position;

        $verb = $this->consumeWhile(static fn (string $c): bool => ':' !== $c);

        // A verb may carry an argument, as "(*MARK:name)" does.
        $argument = $this->stream->matchLiteral(':')
            ? $this->consumeWhile(static fn (): bool => true)
            : '';

        $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected ) to close PCRE verb');
        $endPosition = $endToken->position + 1;

        // Parse the rest of the pattern after the verb group
        $expr = null;
        if (!$this->stream->isAtEnd()) {
            $expr = $this->parseScopedAlternation();
        } else {
            $expr = $this->createEmptyLiteralNodeAt($endPosition);
        }

        // Create a group node containing the verb and the following expression
        $verbNode = $this->createPcreVerbNode(
            '' !== $argument ? $verb.':'.$argument : $verb,
            $verbStartPosition,
            $endPosition,
        );

        // Create a sequence with the verb and the expression
        return new SequenceNode(
            [$verbNode, $expr],
            $startPosition,
            $expr->getEndPosition(),
        );
    }

    /**
     * Parses "(?(?C1)(?=a)yes|no)": the condition is the callout followed by
     * the assertion, which PCRE requires there. Both are kept, in order, as
     * the condition.
     */
    private function parseCalloutConditional(int $startPosition): NodeInterface
    {
        $callout = $this->parseCallout();
        $this->skipEmptyQuotes();

        if (!$this->stream->match(TokenType::T_GROUP_MODIFIER_OPEN)) {
            $position = $this->stream->current()->position;

            throw $this->parserException(
                \sprintf('Invalid conditional condition at position %d: a callout in a condition must be followed by an assertion.', $position),
                $position,
            );
        }

        $assertion = $this->parseLookaroundCondition($this->stream->previous()->position);
        $condition = new SequenceNode([$callout, $assertion], $callout->getStartPosition(), $assertion->getEndPosition());

        return $this->parseConditionalBranches($startPosition, $condition);
    }

    /**
     * Parses a conditional whose condition is written as a verb:
     * "(?(*pla:a)yes|no)". PCRE only takes a lookaround there; a verb, an
     * atomic group or a script run is refused.
     */
    private function parseVerbConditional(int $startPosition, Token $verbToken): NodeInterface
    {
        $verbStartPosition = $verbToken->position;
        $verbEndPosition = $verbStartPosition + \strlen($verbToken->value) + 3; // +3 for "(*)"

        $read = PcreVerb::read($verbToken->value);
        if (null === $read->assertion || GroupType::T_GROUP_ATOMIC === $read->assertion || $read->nonAtomic) {
            // PCRE stops at the colon of a named group, or at the "*".
            $position = 1 === preg_match('/^[a-z_]++(?=:)/', $verbToken->value, $name)
                ? $verbStartPosition + 2 + \strlen($name[0])
                : $verbStartPosition + 1;

            throw $this->parserException(
                \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after "(?(".', $position),
                $position,
            );
        }

        return $this->parseConditionalBranches(
            $startPosition,
            $this->createPcreVerbNode($verbToken->value, $verbStartPosition, $verbEndPosition),
        );
    }

    /**
     * Parses a raw sub-pattern string (e.g. the payload of an alphabetic
     * assertion verb) into an AST. Node positions inside the sub-pattern are
     * relative to the payload, not to the enclosing pattern.
     */
    private function parseSubPattern(string $payload, int $absoluteOffset): NodeInterface
    {
        if ('' === $payload) {
            return $this->createEmptyLiteralNodeAt($absoluteOffset);
        }

        // An inline "(?n)" or "(?x)" before the payload still holds inside
        // it, and a "(?-x)" still turns "x" off there.
        $flags = str_replace('x', '', $this->flags).($this->extendedMode ? 'x' : '');
        $flags = $this->noAutoCapture && !str_contains($flags, 'n') ? $flags.'n' : $flags;
        // The payload is read for the same PHP version, or the same running
        // PCRE2, as the pattern around it.
        $target = $this->useRuntimePcreDetection ? null : $this->phpVersionId;

        try {
            $stream = (new Lexer(Lexer::readsWideRepeatCounts($target)))->tokenize($payload, $flags);
            $pattern = (new Parser($this->maxRecursionDepth, $target))->parse($stream, $flags, '/', \strlen($payload));
        } catch (LexerException|ParserException $error) {
            // Read apart, the payload counts positions from its own start:
            // the error is reported where it stands in the whole pattern.
            $position = (int) $error->getPosition() + $absoluteOffset;
            $message = preg_replace_callback(
                '/at position (\d++)/',
                static fn (array $matches): string => 'at position '.((int) $matches[1] + $absoluteOffset),
                $error->getMessage(),
            ) ?? $error->getMessage();

            throw $error::withContext($message, $position, $this->pattern, $error);
        }

        // The groups it holds take numbers in the enclosing pattern.
        $this->captureCount += (new GroupNumberingCollector())->collect($pattern)->maxGroupNumber;

        return $pattern->pattern;
    }

    private function createPcreVerbNode(string $verb, int $startPosition, int $endPosition): NodeInterface
    {
        $read = PcreVerb::read($verb);

        if (null !== $read->assertion) {
            // "(*pla:...)" and its friends are the alphabetic spelling of a
            // lookaround, so they parse into the group they stand for.
            return new GroupNode(
                $this->parseSubPattern((string) $read->payload, $startPosition + 2 + $read->payloadOffset),
                $read->assertion,
                null,
                $read->nonAtomic ? self::NON_ATOMIC_FLAG : null,
                $startPosition,
                $endPosition,
            );
        }

        if (null !== $read->matchLimit) {
            return new LimitMatchNode($read->matchLimit, $startPosition, $endPosition);
        }

        if ($read->isScriptRun()) {
            $payload = (string) $read->payload;

            return new ScriptRunNode(
                $payload,
                $startPosition,
                $endPosition,
                $this->parseSubPattern($payload, $startPosition + 2 + $read->payloadOffset),
                $read->atomicScriptRun,
            );
        }

        return new PcreVerbNode($read->name, $startPosition, $endPosition);
    }

    /**
     * Parses Python-style named groups and subroutines like (?P<name>...),
     * (?P>name), and (?P=name). PCRE takes nothing else after "(?P": not
     * "(?P'name'...)", which is "(?'name'...)" without the "P".
     */
    private function parsePythonGroup(int $startPos, int $pPos): NodeInterface
    {
        if ($this->stream->matchLiteral('<')) { // (?P<name>...)
            return $this->parseNamedGroup($startPos, true, true);
        }

        if ($this->stream->matchLiteral('>')) { // (?P>name) subroutine
            $name = $this->parseSubroutineName();
            $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected ) to close subroutine call');

            return new SubroutineNode($name, 'P>', $startPos, $endToken->position + 1);
        }

        if ($this->stream->matchLiteral('=')) {
            $name = $this->groupNames->read(false);
            $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected )');

            return new BackrefNode('\\k<'.$name.'>', $startPos, $endToken->position + 1);
        }

        // PCRE reports it past the character it could not read.
        throw $this->parserException(
            \sprintf('Invalid syntax after (?P at position %d: "<", ">" or "=" is expected.', $pPos + 2),
            $pPos + 2,
        );
    }

    /**
     * Parses standard groups like (?<=...), (?<!...), and (?<name>...).
     */
    private function parseStandardGroup(int $startPos): NodeInterface
    {
        // "(?<*...)" is the non-atomic lookbehind; the "*" arrives as a
        // quantifier token.
        if ($this->stream->check(TokenType::T_QUANTIFIER) && '*' === $this->stream->current()->value) {
            $this->stream->advance();
            $expr = $this->parseScopedAlternation();
            $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected )');

            return $this->createGroupNode($expr, GroupType::T_GROUP_LOOKBEHIND_POSITIVE, $startPos, $endToken, null, self::NON_ATOMIC_FLAG);
        }

        // "(?<=...)" and "(?<!...)" are lookbehinds; anything else after the
        // "<" is the name of a group.
        return $this->matchLookaround($startPos, self::LOOKBEHINDS)
            ?? $this->parseNamedGroup($startPos, true);
    }

    /**
     * Parses numeric subroutine calls like (?1), (?-1), (?0).
     */
    private function parseNumericSubroutine(int $startPos): ?SubroutineNode
    {
        $tokensConsumed = 0;
        $num = '';

        if ($this->stream->matchLiteral('-')) {
            $num = '-';
            $tokensConsumed++;
        } elseif ($this->stream->check(TokenType::T_QUANTIFIER) && '+' === $this->stream->current()->value) {
            // "+" after "(?" is lexed as a quantifier token; here it is the
            // sign of a relative subroutine call like (?+1).
            $this->stream->advance();
            $num = '+';
            $tokensConsumed++;
        }

        if ($this->isLiteralDigitToken()) {
            $num .= $this->stream->current()->value;
            $this->stream->advance();
            $tokensConsumed++;

            // Consume additional digits
            while ($this->stream->check(TokenType::T_LITERAL) && ctype_digit($this->stream->current()->value)) {
                $num .= $this->stream->current()->value;
                $this->stream->advance();
                $tokensConsumed++;
            }

            if ($this->stream->check(TokenType::T_GROUP_CLOSE)) {
                $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected )');

                return new SubroutineNode($num, '', $startPos, $endToken->position + 1);
            }

            // Not a valid subroutine, rewind all consumed tokens
            $this->stream->rewind($tokensConsumed);
        } elseif ('-' === $num || '+' === $num) {
            // Only consumed the sign, rewind it
            $this->stream->rewind(1);
        }

        return null;
    }

    /**
     * Parses a subroutine group modifier like (?&name).
     */
    private function parseSubroutineModifier(int $startPosition): ?SubroutineNode
    {
        if (!$this->stream->matchLiteral('&')) {
            return null;
        }

        $name = $this->parseSubroutineName();
        $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected ) to close subroutine call');

        return new SubroutineNode($name, '&', $startPosition, $endToken->position + 1);
    }

    /**
     * Parses a numeric or R subroutine group modifier like (?R), (?1), (?-1).
     */
    private function parseNumericSubroutineModifier(int $startPosition): ?SubroutineNode
    {
        if ($this->stream->matchLiteral('R')) {
            if ($this->stream->check(TokenType::T_GROUP_CLOSE)) {
                $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected )');

                return new SubroutineNode('R', '', $startPosition, $endToken->position + 1);
            }
            $this->stream->rewind(1);
        }

        $subroutine = $this->parseNumericSubroutine($startPosition);
        if (null !== $subroutine) {
            return $subroutine;
        }

        return null;
    }

    /**
     * Parses simple group modifiers like (:...), (=...), (!...), (>...), (?|...).
     */
    private function parseSimpleGroupModifier(int $startPosition): ?GroupNode
    {
        if ($this->stream->matchLiteral(':')) {
            return $this->parseSimpleGroup($startPosition, GroupType::T_GROUP_NON_CAPTURING);
        }

        if ($this->stream->matchLiteral('=')) {
            return $this->parseSimpleGroup($startPosition, GroupType::T_GROUP_LOOKAHEAD_POSITIVE);
        }

        if ($this->stream->matchLiteral('!')) {
            return $this->parseSimpleGroup($startPosition, GroupType::T_GROUP_LOOKAHEAD_NEGATIVE);
        }

        if ($this->stream->matchLiteral('>')) {
            return $this->parseSimpleGroup($startPosition, GroupType::T_GROUP_ATOMIC);
        }

        if ($this->stream->match(TokenType::T_ALTERNATION)) {
            return $this->parseBranchReset($startPosition);
        }

        return null;
    }

    /**
     * Parses "(?|...)", where every branch numbers its groups from the same
     * start, and the group count after it is that of the longest branch.
     */
    private function parseBranchReset(int $startPosition): GroupNode
    {
        $extendedMode = $this->extendedMode;
        $noAutoCapture = $this->noAutoCapture;
        $duplicateNames = $this->groupNames->duplicatesAllowed();
        $base = $this->captureCount;
        $highest = $base;

        $this->guardRecursionDepth($this->stream->current()->position);
        $this->recursionDepth++;

        try {
            $branchStart = $this->stream->current()->position;
            $branches = [];

            do {
                $this->captureCount = $base;
                $branches[] = $this->parseSequence();
                $highest = max($highest, $this->captureCount);
            } while ($this->stream->match(TokenType::T_ALTERNATION));
        } finally {
            $this->recursionDepth--;
            $this->extendedMode = $extendedMode;
            $this->noAutoCapture = $noAutoCapture;
            $this->groupNames->allowDuplicates($duplicateNames);
        }

        $this->captureCount = $highest;

        $expr = 1 === \count($branches)
            ? $branches[0]
            : new AlternationNode($branches, $branchStart, end($branches)->getEndPosition());

        $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected )');

        return $this->createGroupNode($expr, GroupType::T_GROUP_BRANCH_RESET, $startPosition, $endToken);
    }

    /**
     * Parses inline flags and optional sub-expressions (?(?flags:...)).
     */
    private function parseInlineFlags(int $startPosition): NodeInterface
    {
        $flags = $this->readModifierLetters();

        // "(?^" turns every other modifier off already, so PCRE refuses a
        // "-" after it, and reports it past the hyphen.
        if (str_starts_with($flags, '^') && str_contains($flags, '-')) {
            $position = $startPosition + 2 + (int) strpos($flags, '-') + 1;

            throw $this->parserException(
                \sprintf('Invalid hyphen in option setting at position %d: "(?^" cannot turn modifiers off.', $position),
                $position,
            );
        }

        $letters = self::INLINE_FLAG_LETTERS.($this->supportsPcre1043Modifiers() ? 'r' : '');
        // The ASCII options were only read where PCRE2 takes them.
        $tracked = InlineFlags::withoutAsciiOptions($flags);
        $modifiers = InlineFlags::read($tracked, $letters);

        // "(?)", "(?-)" and "(?-:...)" set nothing, and PCRE takes them as
        // such, as "(?a)" or "(?-aD)" set nothing this library tracks; "(?A:"
        // or "(?{" is no option setting at all.
        $setsNothing = ('' === $flags && $this->stream->check(TokenType::T_GROUP_CLOSE))
            || ('-' === $flags && ($this->stream->check(TokenType::T_GROUP_CLOSE) || $this->stream->checkLiteral(':')))
            || ($tracked !== $flags && \in_array($tracked, ['', '-'], true));

        if (null === $modifiers && !$setsNothing) {
            $position = $this->unreadableGroupOffset($startPosition);

            throw $this->parserException(
                \sprintf('Invalid group modifier syntax at position %d', $position),
                $position,
            );
        }

        $wasExtended = $this->extendedMode;
        $wasNoAutoCapture = $this->noAutoCapture;
        $wasAllowingDuplicates = $this->groupNames->duplicatesAllowed();

        if ($modifiers?->turnsOn('J')) {
            $this->groupNames->allowDuplicates(true);
        }
        if ($modifiers?->turnsOff('J')) {
            $this->groupNames->allowDuplicates(false);
        }

        $this->extendedMode = $modifiers?->inForce('x', $this->extendedMode) ?? $this->extendedMode;
        $this->noAutoCapture = $modifiers?->inForce('n', $this->noAutoCapture) ?? $this->noAutoCapture;

        // "(?iz)": the letters are read as far as PCRE knows them.
        if (!$this->stream->check(TokenType::T_GROUP_CLOSE) && !$this->stream->checkLiteral(':')) {
            $position = $this->unreadableGroupOffset($startPosition);

            throw $this->parserException(
                \sprintf('Invalid group modifier syntax at position %d', $position),
                $position,
            );
        }

        $expr = null;
        if ($this->stream->matchLiteral(':')) {
            $expr = $this->parseScopedAlternation();
            // "(?x:...)" only covers its own group; "(?x)" keeps going.
            $this->extendedMode = $wasExtended;
            $this->noAutoCapture = $wasNoAutoCapture;
            $this->groupNames->allowDuplicates($wasAllowingDuplicates);
        }

        $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected )');
        $expr ??= $this->createEmptyLiteralNodeAt($this->stream->previous()->position);

        return $this->createGroupNode(
            $expr,
            GroupType::T_GROUP_INLINE_FLAGS,
            $startPosition,
            $endToken,
            null,
            $flags,
        );
    }

    /**
     * The letters of a "(?...)" modifier group, as the pattern spelled them.
     *
     * The leading "^" of "(?^im)" arrives as an anchor token, since the lexer
     * has no way to know it is not one.
     */
    private function readModifierLetters(): string
    {
        $letters = '';

        if ($this->stream->check(TokenType::T_ANCHOR) && '^' === $this->stream->current()->value) {
            $letters = '^';
            $this->stream->advance();
        }

        $accepted = self::INLINE_FLAG_LETTERS.($this->supportsPcre1043Modifiers() ? 'r' : '');

        // From PCRE2 10.43, "a" may take one of "D", "S", "W", "P", "T".
        $ascii = $this->supportsPcre1043Modifiers();
        $afterA = false;

        return $letters.$this->consumeWhile(
            static function (string $c) use ($accepted, $ascii, &$afterA): bool {
                if ($afterA && str_contains('DSWPT', $c)) {
                    $afterA = false;

                    return true;
                }

                $afterA = $ascii && 'a' === $c;

                return $afterA || '-' === $c || str_contains($accepted, $c);
            },
        );
    }

    /**
     * Whether no PHP version was targeted and the PCRE2 this PHP links is at
     * least the given release.
     */
    private function runningPcreAtLeast(string $release): bool
    {
        return $this->useRuntimePcreDetection
            && version_compare(explode(' ', \PCRE_VERSION)[0], $release, '>=');
    }

    /**
     * Whether "x" skips the character: PCRE skips Pattern_White_Space, which
     * in UTF mode also holds U+0085, U+200E, U+200F, U+2028 and U+2029, and
     * without it the byte 0x85.
     */
    private function isExtendedWhitespace(string $character): bool
    {
        if (ctype_space($character)) {
            return true;
        }

        return $this->unicodeMode
            ? \in_array($character, ["\u{85}", "\u{200e}", "\u{200f}", "\u{2028}", "\u{2029}"], true)
            : "\x85" === $character;
    }

    /**
     * Whether the modifiers PCRE2 10.43 added to "(?...)" are read: "r", and
     * the ASCII options "a", "aD", "aS", "aW", "aP", "aT". PHP bundles that
     * release from 8.4; without a target, the PCRE2 this PHP links decides.
     */
    private function supportsPcre1043Modifiers(): bool
    {
        $cacheKey = $this->useRuntimePcreDetection ? 'runtime' : $this->phpVersionId;
        if (\array_key_exists($cacheKey, self::$supportsPcre1043Modifiers)) {
            return self::$supportsPcre1043Modifiers[$cacheKey];
        }

        // Without a target, the PCRE2 this PHP links decides, whatever PHP
        // bundles: the PHP 8.4 packages of a distribution may link an older
        // one.
        $supports = $this->useRuntimePcreDetection
            ? $this->runningPcreAtLeast('10.43')
            : $this->phpVersionId >= 80400;

        self::$supportsPcre1043Modifiers[$cacheKey] = $supports;

        return $supports;
    }

    /**
     * Parses conditional constructs (?(condition)...).
     */
    private function parseConditional(int $startPosition, bool $isModifier): ConditionalNode|DefineNode
    {
        if ($isModifier) {
            // Inline Lookaround condition
            $conditionStartPos = $this->stream->previous()->position;
            $condition = $this->parseLookaroundCondition($conditionStartPos);
        } else {
            $condition = $this->parseConditionalCondition();
            $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected ) after condition');
        }

        return $this->parseConditionalBranches($startPosition, $condition);
    }

    /**
     * Parses what follows the condition of a conditional: the branches, and
     * the closing parenthesis.
     */
    private function parseConditionalBranches(int $startPosition, NodeInterface $condition): ConditionalNode|DefineNode
    {
        $yes = $this->parseScopedAlternation();

        // Special case: (?(DEFINE)...) creates a DefineNode instead of ConditionalNode
        if ($condition instanceof AssertionNode && 'DEFINE' === $condition->value) {
            $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected )');
            $endPosition = $endToken->position + 1;

            return new DefineNode($yes, $startPosition, $endPosition);
        }

        $no = null;
        $yesBranch = $yes;
        if ($yes instanceof AlternationNode && \count($yes->alternatives) > 1) {
            $yesBranch = $yes->alternatives[0];
            $noAlternatives = \array_slice($yes->alternatives, 1);
            if (1 === \count($noAlternatives)) {
                $no = $noAlternatives[0];
            } else {
                $lastAlt = $noAlternatives[\count($noAlternatives) - 1];
                $no = new AlternationNode(
                    $noAlternatives,
                    $noAlternatives[0]->getStartPosition(),
                    $lastAlt->getEndPosition(),
                );
            }
        }

        $no ??= $this->createEmptyLiteralNodeAt($this->stream->current()->position);

        $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected )');
        $endPosition = $endToken->position + 1;

        return new ConditionalNode($condition, $yesBranch, $no, $startPosition, $endPosition);
    }

    /**
     * Parses lookaround conditions inside conditional constructs (?(?=...)...).
     */
    private function parseLookaroundCondition(int $startPosition): NodeInterface
    {
        $lookaround = $this->matchLookaround($startPosition, self::LOOKAHEADS);
        if (null !== $lookaround) {
            return $lookaround;
        }

        if ($this->stream->matchLiteral('<')) {
            $lookbehind = $this->matchLookaround($startPosition, self::LOOKBEHINDS);
            if (null !== $lookbehind) {
                return $lookbehind;
            }
        }

        throw $this->parserException(
            'Invalid conditional condition at position '.$startPosition,
            $startPosition,
        );
    }

    /**
     * Parses a DEFINE condition in a conditional construct.
     */
    private function parseDefineCondition(int $startPosition): ?AssertionNode
    {
        $savedPos = $this->stream->getPosition();
        $word = '';
        while ($this->isLiteralAlphaToken()) {
            $word .= $this->stream->current()->value;
            $this->stream->advance();
        }

        if ('DEFINE' === $word && $this->stream->check(TokenType::T_GROUP_CLOSE)) {
            return new AssertionNode('DEFINE', $startPosition, $this->stream->current()->position);
        }

        // Not DEFINE, restore position
        $this->stream->setPosition($savedPos);

        return null;
    }

    /**
     * Parses a VERSION condition in a conditional construct.
     */
    private function parseVersionCondition(int $startPosition): ?VersionConditionNode
    {
        $savedPos = $this->stream->getPosition();
        $word = '';
        while (
            !$this->stream->checkLiteral(')')
            && !$this->stream->isAtEnd()
            && ($this->stream->check(TokenType::T_LITERAL) || $this->stream->check(TokenType::T_DOT))
        ) {
            $word .= $this->stream->current()->value;
            $this->stream->advance();
        }

        $condition = VersionCondition::read($word);
        if (null === $condition) {
            $this->stream->setPosition($savedPos);

            return null;
        }

        return new VersionConditionNode(
            $condition->operator,
            $condition->version,
            $startPosition,
            $this->stream->previous()->position,
        );
    }

    /**
     * Parses a numeric condition in a conditional construct.
     */
    private function parseNumericCondition(int $startPosition): ?BackrefNode
    {
        // "(?(-1)...)" and "(?(+1)...)" count groups from here; the "+"
        // arrives as a quantifier token.
        $sign = '';
        if ($this->stream->checkLiteral('-')
            || ($this->stream->check(TokenType::T_QUANTIFIER) && '+' === $this->stream->current()->value)) {
            $sign = $this->stream->current()->value;
            $this->stream->advance();

            if (!$this->isLiteralDigitToken()) {
                $this->stream->rewind(1);

                return null;
            }
        }

        if (!$this->isLiteralDigitToken()) {
            return null;
        }

        $this->stream->advance();
        $num = (string) ($this->stream->previous()->value.$this->consumeWhile(
            static fn (string $c): bool => ctype_digit($c),
        ));

        return new BackrefNode($sign.$num, $startPosition, $this->stream->current()->position);
    }

    /**
     * Parses a named condition in a conditional construct.
     */
    private function parseNamedCondition(int $startPosition): ?BackrefNode
    {
        // "(?('name')...)": the reader takes the quotes along with the name.
        if ($this->stream->checkLiteral("'")) {
            $name = $this->groupNames->read(false);

            return new BackrefNode($name, $startPosition, $this->stream->current()->position);
        }

        if (!$this->stream->matchLiteral('<') && !$this->stream->matchLiteral('{')) {
            return null;
        }

        $open = $this->stream->previous()->value;
        $name = $this->groupNames->read(false);
        $close = '<' === $open ? '>' : '}';
        $this->stream->consumeLiteral($close, "Expected $close after condition name");

        return new BackrefNode($name, $startPosition, $this->stream->current()->position);
    }

    /**
     * Parses a subroutine R condition in a conditional construct.
     */
    private function parseSubroutineRCondition(int $startPosition): ?SubroutineNode
    {
        if (!$this->stream->matchLiteral('R')) {
            return null;
        }

        $endPosition = $this->stream->previous()->position;

        // "(?(R&name)...)" asks whether the most recent recursion is into the
        // named group.
        if ($this->stream->matchLiteral('&')) {
            $name = $this->groupNames->read(false);

            return new SubroutineNode('R&'.$name, '', $startPosition, $this->stream->previous()->position);
        }

        $numericPart = '';
        $sawMinus = false;

        if ($this->stream->checkLiteral('-')) {
            $sawMinus = true;
            $this->stream->advance();
        }

        $digits = $this->consumeWhile(static fn (string $c): bool => ctype_digit($c));
        if ('' !== $digits) {
            $numericPart = ($sawMinus ? '-' : '').$digits;
            $endPosition = $this->stream->previous()->position;
        } elseif ($sawMinus) {
            $this->stream->rewind(1);
        }

        $reference = 'R'.$numericPart;

        return new SubroutineNode($reference, '', $startPosition, $endPosition);
    }

    /**
     * Parses a bare name condition in a conditional construct.
     */
    private function parseBareNameCondition(int $startPosition): ?BackrefNode
    {
        if (!$this->stream->check(TokenType::T_LITERAL)) {
            return null;
        }

        $savedPos = $this->stream->getPosition();
        $name = '';
        while (
            $this->stream->check(TokenType::T_LITERAL)
            && !$this->stream->checkLiteral(')')
            && !$this->stream->isAtEnd()
        ) {
            $name .= $this->stream->current()->value;
            $this->stream->advance();
        }

        if ('' !== $name && $this->stream->check(TokenType::T_GROUP_CLOSE)) {
            return new BackrefNode($name, $startPosition, $this->stream->current()->position);
        }

        $this->stream->setPosition($savedPos);

        return null;
    }

    /**
     * Parses the condition part of a conditional construct (?(condition)...).
     */
    private function parseConditionalCondition(): NodeInterface
    {
        $startPosition = $this->stream->current()->position;

        $condition = $this->parseDefineCondition($startPosition)
            ?? $this->parseVersionCondition($startPosition)
            ?? $this->parseNumericCondition($startPosition)
            ?? $this->parseNamedCondition($startPosition)
            ?? $this->parseSubroutineRCondition($startPosition);

        if (null !== $condition) {
            return $condition;
        }

        if ($this->stream->matchLiteral('?')) {
            return $this->parseLookaroundCondition($startPosition);
        }

        $bareName = $this->parseBareNameCondition($startPosition);
        if (null !== $bareName) {
            return $bareName;
        }

        // "(?(+a)": a sign with no number after it, where PCRE then wants a
        // name.
        if ($this->stream->check(TokenType::T_QUANTIFIER)) {
            $position = $this->conditionErrorOffset($startPosition);

            throw $this->parserException(\sprintf('Quantifier without target at position %d', $position), $position);
        }

        // Anything else has to be an atom that refers to a group. PCRE wants
        // a name there, and refuses what cannot be one before reading it.
        try {
            $condition = $this->parseAtom();
        } catch (SyntaxErrorException) {
            $condition = null;
        }

        if (
            !(
                $condition instanceof BackrefNode
                || $condition instanceof GroupNode
                || $condition instanceof AssertionNode
                || $condition instanceof SubroutineNode
            )
        ) {
            $position = $this->conditionErrorOffset($startPosition);

            throw $this->parserException(
                \sprintf(
                    'Invalid conditional construct at position %d. Condition must be a group reference, lookaround, or (DEFINE).',
                    $position,
                ),
                $position,
            );
        }

        return $condition;
    }

    /**
     * Where PCRE stops reading a condition it cannot take, when it is no
     * number: where a version condition goes wrong, or where the characters
     * of a name end.
     */
    private function conditionErrorOffset(int $start): int
    {
        return VersionCondition::errorOffset($this->pattern, $start)
            ?? $this->groupNames->invalidNameOffset($start);
    }

    /**
     * parses a character class, including its parts and negation
     */
    private function parseCharClass(): CharClassNode
    {
        $startToken = $this->stream->previous();
        $startPosition = $startToken->position;
        $isNegated = $this->parseCharClassPrefix();
        $parts = $this->parseCharClassAlternation();

        $endToken = $this->stream->consume(TokenType::T_CHAR_CLASS_CLOSE, 'Expected "]" to close character class');

        return new CharClassNode($parts, $isNegated, $startPosition, $endToken->position + 1);
    }

    /**
     * Read what PCRE skips before the first member of a class: "\E", an
     * empty "\Q\E" and the negating "^", in any order, so "[\E^a]" is
     * negated. The lexer only gives a negation token in that prefix.
     *
     * @return bool whether the class is negated
     */
    private function parseCharClassPrefix(): bool
    {
        $isNegated = false;

        while (true) {
            if ($this->stream->match(TokenType::T_NEGATION)) {
                $isNegated = true;
            } elseif ($this->stream->match(TokenType::T_QUOTE_MODE_START)) {
                $this->inQuoteMode = true;
            } elseif ($this->stream->match(TokenType::T_QUOTE_MODE_END)) {
                $this->inQuoteMode = false;
            } else {
                return $isNegated;
            }
        }
    }

    /**
     * Parses the members of a character class.
     */
    private function parseCharClassAlternation(): NodeInterface
    {
        $parts = [];

        while (
            !$this->stream->check(TokenType::T_CHAR_CLASS_CLOSE)
            && !$this->stream->isAtEnd()
        ) {
            // Silent tokens inside char class
            if ($this->stream->match(TokenType::T_QUOTE_MODE_START)) {
                $this->inQuoteMode = true;

                continue;
            }
            if ($this->stream->match(TokenType::T_QUOTE_MODE_END)) {
                $this->inQuoteMode = false;

                continue;
            }
            $parts[] = $this->parseCharClassPart();
        }

        if (empty($parts)) {
            return $this->createEmptyLiteralNodeAt($this->stream->current()->position);
        }

        if (1 === \count($parts)) {
            return $parts[0];
        }

        $start = $parts[0]->getStartPosition();
        $end = $parts[\count($parts) - 1]->getEndPosition();

        return new AlternationNode($parts, $start, $end);
    }

    /**
     * Determines if a node type cannot be an endpoint in a character class range.
     *
     * In PCRE, CharTypeNode, UnicodePropNode, PosixClassNode, and CharClassNode
     * cannot serve as range endpoints - a hyphen following them is treated as a literal.
     */
    /**
     * PCRE takes a range between two characters; "[\d-z]" names no range.
     *
     * @throws ParserException
     */
    private function guardRangeEndpoint(NodeInterface $node, int $position): void
    {
        if (!$this->isNonRangeEndpointType($node)) {
            return;
        }

        throw $this->parserException(
            \sprintf(
                'Invalid range in character class: a character type, POSIX class, or Unicode property cannot be a range endpoint at position %d.',
                $position,
            ),
            $position,
        );
    }

    /**
     * Whether an escape PCRE refuses inside a class starts at $position:
     * an assertion such as "\B", "\R", "\X", or "\N" that names no code
     * point.
     */
    private function isClassInvalidEscapeAt(int $position): bool
    {
        if ('\\' !== ($this->pattern[$position] ?? '')) {
            return false;
        }

        $letter = $this->pattern[$position + 1] ?? '';

        if ('N' === $letter) {
            return '{' !== ($this->pattern[$position + 2] ?? '');
        }

        return '' !== $letter && str_contains('ABCGKRXZz', $letter);
    }

    private function isNonRangeEndpointType(NodeInterface $node): bool
    {
        return $node instanceof CharTypeNode
            || $node instanceof UnicodePropNode
            || $node instanceof PosixClassNode
            || $node instanceof CharClassNode;
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
        return GroupType::T_GROUP_INLINE_FLAGS === $node->type
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

    /**
     * Parses a single character class atom (literal, char type, unicode, etc).
     *
     * @return array{0: NodeInterface, 1: int} The node and its end position
     */
    private function parseCharClassAtom(int $startPosition): array
    {
        // An anchor, an assertion or a backreference is a plain character
        // inside a class: "[$]" is a dollar sign, not an anchor. The lexer
        // already gives them as literals there, so what is left is the same
        // set of atoms as outside, plus what only a class can hold.
        if (null !== $atom = $this->matchAtom($startPosition)) {
            return [$atom, $atom->getEndPosition()];
        }

        if ($this->stream->match(TokenType::T_CHAR_CLASS_OPEN)) {
            $node = $this->parseCharClass();

            return [$node, $node->getEndPosition()];
        }

        if ($this->stream->match(TokenType::T_RANGE)) {
            $token = $this->stream->previous();

            return [new LiteralNode($token->value, $startPosition, $token->end()), $token->end()];
        }

        if ($this->stream->match(TokenType::T_POSIX_CLASS)) {
            $token = $this->stream->previous();

            return [new PosixClassNode($token->value, $startPosition, $token->end()), $token->end()];
        }

        throw $this->parserException(
            \sprintf(
                'Unexpected token "%s" in character class at position %d.',
                $this->stream->current()->value,
                $this->stream->current()->position,
            ),
            $this->stream->current()->position,
        );
    }

    /**
     * parses a part of a character class, which can be a literal, range, char type, unicode property, etc
     */
    private function parseCharClassPart(): NodeInterface
    {
        $startToken = $this->stream->current();
        $startPosition = $startToken->position;

        [$startNode] = $this->parseCharClassAtom($startPosition);

        // PCRE refuses such an escape as soon as it reads it, before any "-"
        // after it could make a range.
        if ($this->isClassInvalidEscapeAt($startPosition)) {
            return $startNode;
        }

        // PCRE also skips "\E" and an empty "\Q\E" before the "-": "[z\E-a]"
        // is the range z-a. Without a "-" after them, they are left to the
        // class loop as they were. A quoted run of several characters starts
        // its range at its last one, which one node cannot say, so that case
        // keeps its members apart.
        $beforeQuotes = $this->stream->getPosition();
        $wasInQuoteMode = $this->inQuoteMode;
        $singleCharacterStart = !($startNode instanceof LiteralNode && mb_strlen($startNode->value) > 1);
        // A class escape before them, "[\w\E-a]", keeps a member "-" up to
        // PCRE2 10.44, the newest any PHP release bundles; from 10.45 the
        // range forms and fails, so only a newer linked PCRE2 refuses it.
        $classEscapeStart = $startNode instanceof CharTypeNode
            || $startNode instanceof PosixClassNode
            || $startNode instanceof UnicodePropNode;
        if ($singleCharacterStart && (!$classEscapeStart || $this->runningPcreAtLeast('10.45'))) {
            $this->skipEmptyQuotes();
        }

        // Check for Range
        $rangePosition = $this->stream->getPosition();
        if (!$this->stream->match(TokenType::T_RANGE)) {
            $this->stream->setPosition($beforeQuotes);
            $this->inQuoteMode = $wasInQuoteMode;

            return $startNode;
        }

        // PCRE refuses a class escape before the "-" once it has read the "-".
        $afterHyphen = $this->stream->previous()->end();

        // PCRE skips "\E" and an empty "\Q\E" after the "-": "[a-\Ec]" is
        // the range a-c, and in "[a-\Q\E]" the "-" is a plain member.
        $this->skipEmptyQuotes();

        if ($this->stream->check(TokenType::T_CHAR_CLASS_CLOSE)) {
            $this->stream->setPosition($rangePosition);

            return $startNode;
        }

        // An escape PCRE refuses in a class is reported before the range.
        if ($this->isClassInvalidEscapeAt($this->stream->current()->position)) {
            $this->stream->setPosition($rangePosition);

            return $startNode;
        }

        $this->guardRangeEndpoint($startNode, $afterHyphen);

        if ($this->stream->check(TokenType::T_CHAR_CLASS_OPEN)) {
            $this->stream->rewind(1);

            return $startNode;
        }

        // A quoted end, "[a-\Qcz\E]", ends the range at the first quoted
        // character; the lexer gives a class one quoted character at a time,
        // so the rest stay members.
        if ($this->stream->check(TokenType::T_QUOTE_MODE_START)) {
            if (TokenType::T_LITERAL !== $this->stream->peek()->type) {
                $this->stream->setPosition($beforeQuotes);
                $this->inQuoteMode = $wasInQuoteMode;

                return $startNode;
            }

            $this->stream->advance();
            $this->inQuoteMode = true;
        }

        $endToken = $this->stream->current();
        $endPosition = $endToken->position;

        try {
            [$endNode] = $this->parseCharClassAtom($endPosition);
        } catch (ParserException) {
            throw $this->parserException(
                \sprintf(
                    'Unexpected token "%s" in character class range at position %d.',
                    $this->stream->current()->value,
                    $this->stream->current()->position,
                ),
                $this->stream->current()->position,
            );
        }

        // A class escape after it, once it has read the escape.
        $this->guardRangeEndpoint($endNode, $endNode->getEndPosition());

        return new RangeNode($startNode, $endNode, $startPosition, $endNode->getEndPosition());
    }

    /**
     * Skip the "\E" and empty "\Q\E" that PCRE reads as nothing.
     */
    private function skipEmptyQuotes(): void
    {
        while (true) {
            if ($this->stream->match(TokenType::T_QUOTE_MODE_END)) {
                $this->inQuoteMode = false;

                continue;
            }

            if ($this->stream->check(TokenType::T_QUOTE_MODE_START)
                && TokenType::T_QUOTE_MODE_END === $this->stream->peek()->type) {
                $this->stream->advance();
                $this->stream->advance();

                continue;
            }

            return;
        }
    }

    /**
     * parses a subroutine name consisting of alphanumeric characters and underscores
     */
    private function parseSubroutineName(): string
    {
        $nameStart = $this->stream->current()->position;
        $name = '';
        while (
            !$this->stream->check(TokenType::T_GROUP_CLOSE)
            && !$this->stream->isAtEnd()
        ) {
            if ($this->stream->check(TokenType::T_LITERAL) || $this->stream->check(TokenType::T_LITERAL_ESCAPED)) {
                $char = $this->stream->current()->value;
                if (1 !== preg_match('/^[\p{L}\p{Nd}_]$/u', $char)) {
                    throw $this->parserException(
                        'Unexpected token in subroutine name: '.$char,
                        $this->stream->current()->position,
                    );
                }
                $name .= $char;
                $this->stream->advance();
            } else {
                throw $this->parserException(
                    'Unexpected token in subroutine name: '.$this->stream->current()->value,
                    $this->stream->current()->position,
                );
            }
        }
        if ('' === $name) {
            throw $this->parserException(
                'Expected subroutine name at position '.$this->stream->current()->position,
                $this->stream->current()->position,
            );
        }

        // A name that starts with a digit names no group: PCRE stops past the
        // digit.
        if (1 === preg_match('/^\p{Nd}/u', $name)) {
            throw $this->parserException(
                \sprintf(
                    'Invalid group name "%s": names must contain only word characters and must not start with a digit.',
                    $name,
                ),
                $this->groupNames->invalidNameOffset($nameStart),
            );
        }

        return $name;
    }

    /**
     * Where PCRE reports a "(?" group it cannot read, by what follows the
     * "(?": it reads as far as the construct makes sense and stops there.
     *
     * @param int $start the offset of the "("
     */
    private function unreadableGroupOffset(int $start): int
    {
        $pattern = $this->pattern;
        $length = \strlen($pattern);
        $position = $start + 2;
        $char = $pattern[$position] ?? '';

        // "(?[" is a Perl extended class, which PCRE2 reads from 10.45 only;
        // before, it refuses the "[".
        if ('[' === $char) {
            return $position;
        }

        // "(?R" calls the whole pattern, and a ")" has to follow.
        if ('R' === $char) {
            return $position + 1;
        }

        // "(?1", "(?-1", "(?+1": a call, which ends after its number.
        if (1 === preg_match('/\G[+-]?\d++/', $pattern, $matches, 0, $position)) {
            return $position + \strlen($matches[0]);
        }

        // "(?+" without a number: past the character after the "+".
        if ('+' === $char) {
            return min($position + 2, $length);
        }

        if ('C' === $char) {
            return $this->calloutErrorOffset($start) ?? $start;
        }

        // Option letters: PCRE stops past the first one it does not know,
        // past a "-" it cannot take, or at the end of the pattern.
        $letters = self::INLINE_FLAG_LETTERS.($this->supportsPcre1043Modifiers() ? 'r' : '');
        $hyphenAllowed = true;
        if ('^' === $char) {
            $hyphenAllowed = false;
            $position++;
        }

        while ($position < $length && ')' !== $pattern[$position] && ':' !== $pattern[$position]) {
            $char = $pattern[$position++];

            if ('-' === $char) {
                if (!$hyphenAllowed) {
                    return $position;
                }

                $hyphenAllowed = false;

                continue;
            }

            // An ASCII option, "a", takes at most one class letter along.
            if ('a' === $char && $this->supportsPcre1043Modifiers()) {
                $position += (int) ($position < $length && str_contains('DSWPT', $pattern[$position]));

                continue;
            }

            if (!str_contains($letters, $char)) {
                return $position;
            }
        }

        return $position < $length ? $start : $length;
    }

    /**
     * Where PCRE reports a callout it cannot read, or null when it reads it:
     * past a digit that takes the number over 255, at a string delimiter
     * never closed, past a character that opens no string, or where the
     * ")" should follow the argument.
     *
     * @param int $start the offset of the "(" of "(?C"
     */
    private function calloutErrorOffset(int $start): ?int
    {
        $pattern = $this->pattern;
        $length = \strlen($pattern);
        $position = $start + 3;

        if ($position >= $length) {
            return $length;
        }

        if (ctype_digit($pattern[$position])) {
            $number = 0;
            while ($position < $length && ctype_digit($pattern[$position])) {
                $number = $number * 10 + (int) $pattern[$position++];
                if ($number > 255) {
                    return $position;
                }
            }
        } elseif (')' !== $pattern[$position]) {
            $closing = self::CALLOUT_STRING_DELIMITERS[$pattern[$position]] ?? null;
            if (null === $closing) {
                return $position + 1;
            }

            $opening = $position;
            while (true) {
                if (++$position >= $length) {
                    return $opening;
                }

                // A doubled closing delimiter stands for itself.
                if ($closing === $pattern[$position] && (++$position >= $length || $closing !== $pattern[$position])) {
                    break;
                }
            }
        }

        return ')' === ($pattern[$position] ?? '') ? null : $position;
    }

    /**
     * PCRE refuses a quantifier with nothing to repeat once it has read it,
     * before any "?" or "+" that would make it lazy or possessive.
     */
    private function quantifierErrorOffset(Token $token): int
    {
        $suffixed = \strlen($token->value) > 1 && \in_array(substr($token->value, -1), ['?', '+'], true);

        return $token->end() - ($suffixed ? 1 : 0);
    }

    /**
     * Whether the "*" at $position follows the "(" that opens the group it
     * is in, as in "(*MARK:a" the lexer read as no verb for want of ")".
     */
    private function startsUnclosedVerb(int $position): bool
    {
        $previous = $this->stream->previous();

        return '*' === ($this->pattern[$position] ?? '')
            && TokenType::T_GROUP_OPEN === $previous->type
            && $previous->end() === $position;
    }

    /**
     * Where PCRE reports a "(*" the pattern never closes: where its name
     * ends, or at the end of the pattern once a name it knows takes ":".
     *
     * @param int $start the offset of the "("
     */
    private function unclosedVerbOffset(int $start): int
    {
        preg_match('/\G[A-Za-z_]*+/', $this->pattern, $matches, 0, $start + 2);
        $name = $matches[0] ?? '';
        $nameEnd = $start + 2 + \strlen($name);

        if (':' === ($this->pattern[$nameEnd] ?? '') && PcreVerb::takesArgument($name)) {
            return \strlen($this->pattern);
        }

        return $nameEnd;
    }

    /**
     * creates a ParserException with context about the pattern being parsed
     */
    private function parserException(string $message, int $position): ParserException
    {
        return SyntaxErrorException::withContext($message, $position, $this->pattern);
    }

    private function guardRecursionDepth(int $position): void
    {
        if ($this->recursionDepth >= $this->maxRecursionDepth) {
            throw RecursionLimitException::withContext(
                \sprintf('Recursion limit of %d exceeded', $this->maxRecursionDepth),
                $position,
                $this->pattern,
            );
        }
    }

    /**
     * Creates an empty literal node (epsilon) at a given position.
     */
    private function createEmptyLiteralNodeAt(int $position): LiteralNode
    {
        return new LiteralNode('', $position, $position);
    }

    /**
     * Small factory for group nodes to keep argument ordering and end positions consistent.
     */
    private function createGroupNode(
        NodeInterface $expr,
        GroupType $type,
        int $startPosition,
        Token $endToken,
        ?string $name = null,
        ?string $flags = null,
        bool $usePythonSyntax = false,
    ): GroupNode {
        return new GroupNode($expr, $type, $name, $flags, $startPosition, $endToken->position + 1, $usePythonSyntax);
    }

    /**
     * Parses a simple group: alternation content followed by closing paren.
     * Used for non-capturing groups, lookaheads, atomic groups, etc.
     */
    /**
     * Read a lookaround, given the characters that introduce the ones this
     * position accepts.
     *
     * @param array<string, GroupType> $kinds
     */
    private function matchLookaround(int $startPosition, array $kinds): ?GroupNode
    {
        foreach ($kinds as $literal => $type) {
            if ($this->stream->matchLiteral((string) $literal)) {
                return $this->parseSimpleGroup($startPosition, $type);
            }
        }

        return null;
    }

    /**
     * Read a named group, whichever of the four ways the pattern names it.
     *
     * @param bool $expectAngle  true for "(?<name>" and "(?P<name>", where a
     *                           ">" closes the name
     * @param bool $pythonSyntax true for the "(?P...)" spellings, which are
     *                           written back out as they were read
     */
    private function parseNamedGroup(int $startPosition, bool $expectAngle, bool $pythonSyntax = false): GroupNode
    {
        $name = $this->groupNames->read(true, ++$this->captureCount);

        if ($expectAngle) {
            $this->stream->consumeLiteral('>', 'Expected > after group name');
        }

        $expr = $this->parseScopedAlternation();
        $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected )');

        return $this->createGroupNode(
            $expr,
            GroupType::T_GROUP_NAMED,
            $startPosition,
            $endToken,
            $name,
            null,
            $pythonSyntax,
        );
    }

    private function parseSimpleGroup(int $startPosition, GroupType $type): GroupNode
    {
        $expr = $this->parseScopedAlternation();
        $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected )');

        return $this->createGroupNode($expr, $type, $startPosition, $endToken);
    }

    /**
     * Check if current token is a literal digit.
     */
    private function isLiteralDigitToken(): bool
    {
        return $this->stream->check(TokenType::T_LITERAL) && ctype_digit($this->stream->current()->value);
    }

    /**
     * @return bool true if the current token is a T_LITERAL and its value is an alphabetic character (a-z, A-Z)
     */
    private function isLiteralAlphaToken(): bool
    {
        return $this->stream->check(TokenType::T_LITERAL) && ctype_alpha($this->stream->current()->value);
    }

    /**
     * Consumes tokens while the predicate returns true, concatenating their values.
     */
    private function consumeWhile(callable $predicate): string
    {
        $value = '';

        while (
            !$this->stream->isAtEnd()
            && $this->stream->check(TokenType::T_LITERAL)
            && $predicate($this->stream->current()->value)
        ) {
            $value .= $this->stream->current()->value;
            $this->stream->advance();
        }

        return $value;
    }
}
