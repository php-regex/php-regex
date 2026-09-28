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
use RegexParser\Node\ClassOperationNode;
use RegexParser\Node\ClassOperationType;
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

    private int $recursionDepth = 0;

    /**
     * The number the last capturing group was given, counted the way PCRE
     * counts them, branch resets included: a name is tied to a number.
     */
    private int $captureCount = 0;

    /**
     * @var array<int|string, bool>
     */
    private static array $supportsInlineModifierR = [];

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
        $this->groupNames->readUnicodeNames(
            str_contains($flags, 'u') || 1 === preg_match('/\A(?:\(\*[A-Z_]++(?:=\d++)?\))*?\(\*UTF8?\)/', $this->pattern),
        );
        $this->extendedMode = str_contains($flags, 'x');
        $this->noAutoCapture = str_contains($flags, 'n');
        $this->inQuoteMode = false;
        $this->recursionDepth = 0;
        $this->captureCount = 0;

        $patternNode = $this->parseAlternation();
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
        $startPosition = $this->stream->current()->position;

        while (!$this->stream->isAtEnd() && !$this->stream->check(TokenType::T_GROUP_CLOSE) && !$this->stream->check(TokenType::T_ALTERNATION)) {
            if ($this->stream->match(TokenType::T_QUOTE_MODE_START)) {
                $this->inQuoteMode = true;

                continue;
            }
            if ($this->stream->match(TokenType::T_QUOTE_MODE_END)) {
                $this->inQuoteMode = false;

                $this->quantifyLastQuotedCharacter($nodes);

                continue;
            }

            // In extended (/x) mode, consume whitespace and line comments as
            // explicit nodes where appropriate so we can preserve them when
            // reconstructing the pattern.
            if ($this->consumeExtendedModeContent($nodes)) {
                continue;
            }

            $nodes[] = $this->parseQuantifiedAtom();
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
            if (ctype_space($token->value)) {
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

            if (ctype_space($token->value)) {
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
     * A quantifier written right after the end of a quoted run repeats the
     * last quoted character, the way PCRE repeats the "+" of a pattern that
     * quotes one and then stars it.
     *
     * @param array<NodeInterface> $nodes
     */
    private function quantifyLastQuotedCharacter(array &$nodes): void
    {
        if ([] === $nodes || !$this->stream->match(TokenType::T_QUANTIFIER)) {
            return;
        }

        $token = $this->stream->previous();
        $last = array_pop($nodes);
        $this->assertQuantifierCanApply($last, $token);
        [$quantifier, $type] = $this->parseQuantifierValue($token->value);

        $nodes[] = new QuantifierNode($last, $quantifier, $type, $last->getStartPosition(), $token->end());
    }

    private function parseQuantifiedAtom(): NodeInterface
    {
        $node = $this->parseAtom();

        $skipped = $this->skipExtendedModeContent();

        if ($this->stream->match(TokenType::T_QUANTIFIER)) {
            $token = $this->stream->previous();

            $this->assertQuantifierCanApply($node, $token);

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

        if ($skipped > 0) {
            $this->stream->rewind($skipped);
        }

        return $node;
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
        // A callout matches nothing, and PCRE does not let it be repeated.
        if ($node instanceof CalloutNode) {
            throw $this->parserException(
                \sprintf('Quantifier does not follow a repeatable item at position %d: a callout cannot be repeated.', $token->position),
                $token->position,
            );
        }

        if ($this->isEmptyNode($node)) {
            throw $this->parserException(
                \sprintf('Quantifier without target at position %d', $token->position),
                $token->position,
            );
        }

        // PCRE lets "(*ACCEPT)" be repeated, "(*ACCEPT)??" included: it wraps
        // it in a group. No other verb takes a quantifier.
        $isAccept = $node instanceof PcreVerbNode && 1 === preg_match('/^ACCEPT(?::|$)/', $node->verb);

        if (!$isAccept && $this->isAssertionNode($node)) {
            $nodeName = $this->getAssertionNodeName($node);

            throw $this->parserException(
                \sprintf('Quantifier "%s" cannot be applied to assertion or verb "%s" at position %d',
                    $token->value, $nodeName, $node->getStartPosition()),
                $token->position,
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
            throw $this->parserException(
                \sprintf('Quantifier without target at position %d', $this->stream->current()->position),
                $this->stream->current()->position,
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
     * no name follows: PCRE refuses "\k" alone and an empty "\k<>". Other
     * shapes it could not read are left as they are read today.
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
                \sprintf('\k is not followed by a braced, angle-bracketed or quoted name at position %d.', $token->position),
                $token->position + 2,
            );
        }

        if ($closer === ($this->pattern[$token->position + 3] ?? '')) {
            throw $this->parserException(
                \sprintf('Group name expected after \k%s at position %d.', $opener, $token->position),
                $token->position + 3,
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
            TokenType::T_BACKREF => new BackrefNode($token->value, $startPosition, $token->end()),
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
            // "(?Cab)": a callout takes a number or a delimited string, and
            // PCRE reports it past the character it could not read.
            throw $this->parserException(
                \sprintf('Invalid callout argument: %s at position %d', $value, $startPosition),
                $startPosition + 4,
            );
        }

        // A doubled closing delimiter stands for itself: "(?C{a}}b})".
        $quoted = preg_quote($closing, '/');
        if (1 !== preg_match('/^.((?:[^'.$quoted.']|'.$quoted.$quoted.')*+)'.$quoted.'$/s', $value, $matches)) {
            throw $this->parserException(
                \sprintf('Invalid callout argument: %s at position %d', $value, $startPosition),
                $startPosition,
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
        $value = $token->value;
        $endPosition = $startPosition + \strlen($value);

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

        throw $this->parserException(
            \sprintf('Invalid \\g reference syntax: %s at position %d', $value, $token->position),
            $token->position,
        );
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
            return $this->parseNamedGroup($startPosition, $startPosition, false);
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

        // An inline "(?n)" before the payload still holds inside it.
        $flags = $this->noAutoCapture && !str_contains($this->flags, 'n') ? $this->flags.'n' : $this->flags;
        $stream = (new Lexer())->tokenize($payload, $flags);
        $parser = new Parser($this->maxRecursionDepth, $this->phpVersionId);
        $pattern = $parser->parse($stream, $flags, '/', \strlen($payload));

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
                $this->parseSubPattern($payload, $startPosition),
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
            return $this->parseNamedGroup($startPos, $pPos, true, true);
        }

        if ($this->stream->matchLiteral('>')) { // (?P>name) subroutine
            $name = $this->parseSubroutineName();
            $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected ) to close subroutine call');

            return new SubroutineNode($name, 'P>', $startPos, $endToken->position + 1);
        }

        if ($this->stream->matchLiteral('=')) {
            $name = $this->groupNames->read($this->stream->current()->position, false);
            $endToken = $this->stream->consume(TokenType::T_GROUP_CLOSE, 'Expected )');

            return new BackrefNode('\\k<'.$name.'>', $startPos, $endToken->position + 1);
        }

        // PCRE reports it past the character it could not read.
        throw $this->parserException(
            \sprintf('Invalid syntax after (?P at position %d: "<", ">" or "=" is expected.', $pPos),
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
            ?? $this->parseNamedGroup($startPos, $startPos, true);
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

        $letters = self::INLINE_FLAG_LETTERS.($this->supportsInlineModifierR() ? 'r' : '');
        $modifiers = InlineFlags::read($flags, $letters);

        // "(?)", "(?-)" and "(?-:...)" set nothing, and PCRE takes them as
        // such; "(?A:" or "(?{" is no option setting at all.
        $setsNothing = ('' === $flags && $this->stream->check(TokenType::T_GROUP_CLOSE))
            || ('-' === $flags && ($this->stream->check(TokenType::T_GROUP_CLOSE) || $this->stream->checkLiteral(':')));

        if (null === $modifiers && !$setsNothing) {
            throw $this->parserException(
                \sprintf('Invalid group modifier syntax at position %d', $startPosition),
                $startPosition,
            );
        }

        $conflicts = $modifiers?->conflicts() ?? '';
        if ('' !== $conflicts) {
            throw $this->parserException(
                \sprintf('Conflicting flags: %s cannot be both set and unset at position %d', $conflicts, $startPosition),
                $startPosition,
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

        $accepted = self::INLINE_FLAG_LETTERS.($this->supportsInlineModifierR() ? 'r' : '');

        return $letters.$this->consumeWhile(
            static fn (string $c): bool => '-' === $c || str_contains($accepted, $c),
        );
    }

    // Checks if the 'r' inline modifier is supported by the current PCRE/PHP version
    // The 'r' modifier was added in PCRE2 10.43 and PHP 8.4
    private function supportsInlineModifierR(): bool
    {
        $cacheKey = $this->useRuntimePcreDetection ? 'runtime' : $this->phpVersionId;
        if (\array_key_exists($cacheKey, self::$supportsInlineModifierR)) {
            return self::$supportsInlineModifierR[$cacheKey];
        }

        $supports = $this->phpVersionId >= 80400;

        if (!$supports && $this->useRuntimePcreDetection) {
            // For runtime detection, check the PCRE library version directly
            $pcreVersion = \defined('PCRE_VERSION') ? explode(' ', \PCRE_VERSION)[0] : '0';
            $supports = version_compare($pcreVersion, '10.43', '>=');
        }

        self::$supportsInlineModifierR[$cacheKey] = $supports;

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
            $name = $this->groupNames->read($startPosition, false);

            return new BackrefNode($name, $startPosition, $this->stream->current()->position);
        }

        if (!$this->stream->matchLiteral('<') && !$this->stream->matchLiteral('{')) {
            return null;
        }

        $open = $this->stream->previous()->value;
        $name = $this->groupNames->read($startPosition, false);
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
            $name = $this->groupNames->read($this->stream->current()->position, false);

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

        // Anything else has to be an atom that refers to a group.
        $condition = $this->parseAtom();

        if (
            !(
                $condition instanceof BackrefNode
                || $condition instanceof GroupNode
                || $condition instanceof AssertionNode
                || $condition instanceof SubroutineNode
            )
        ) {
            throw $this->parserException(
                \sprintf(
                    'Invalid conditional construct at position %d. Condition must be a group reference, lookaround, or (DEFINE).',
                    $startPosition,
                ),
                $startPosition,
            );
        }

        return $condition;
    }

    /**
     * parses a character class, including its parts and negation
     */
    private function parseCharClass(): CharClassNode
    {
        $startToken = $this->stream->previous();
        $startPosition = $startToken->position;
        $isNegated = $this->parseCharClassPrefix();
        $parts = $this->parseClassExpression();

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
     * Parses a character class expression with intersection (&&) and subtraction (--) operations.
     */
    private function parseClassExpression(): NodeInterface
    {
        $left = $this->parseCharClassAlternation();

        while ($this->stream->check(TokenType::T_CLASS_INTERSECTION) || $this->stream->check(TokenType::T_CLASS_SUBTRACTION)) {
            $type = TokenType::T_CLASS_INTERSECTION === $this->stream->current()->type ? ClassOperationType::INTERSECTION : ClassOperationType::SUBTRACTION;
            $this->stream->advance();
            $right = $this->parseCharClassAlternation();
            $left = new ClassOperationNode($type, $left, $right, $left->getStartPosition(), $right->getEndPosition());
        }

        return $left;
    }

    /**
     * Parses the alternation of character class parts (without operations).
     */
    private function parseCharClassAlternation(): NodeInterface
    {
        $parts = [];

        while (
            !$this->stream->check(TokenType::T_CHAR_CLASS_CLOSE)
            && !$this->stream->check(TokenType::T_CLASS_INTERSECTION)
            && !$this->stream->check(TokenType::T_CLASS_SUBTRACTION)
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

        // Check for Range
        $rangePosition = $this->stream->getPosition();
        if (!$this->stream->match(TokenType::T_RANGE)) {
            return $startNode;
        }

        // PCRE skips "\E" and an empty "\Q\E" after the "-": "[a-\Ec]" is
        // the range a-c, and in "[a-\Q\E]" the "-" is a plain member.
        $this->skipEmptyQuotes();

        if ($this->stream->check(TokenType::T_CHAR_CLASS_CLOSE)) {
            $this->stream->setPosition($rangePosition);

            return $startNode;
        }

        $this->guardRangeEndpoint($startNode, $startPosition);

        if ($this->stream->check(TokenType::T_CHAR_CLASS_OPEN)) {
            $this->stream->rewind(1);

            return $startNode;
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

        $this->guardRangeEndpoint($endNode, $endPosition);

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

        return $name;
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
    private function parseNamedGroup(int $startPosition, int $namePosition, bool $expectAngle, bool $pythonSyntax = false): GroupNode
    {
        $name = $this->groupNames->read($namePosition, true, ++$this->captureCount);

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
