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
use PHPRegex\Parser\Exception\RecursionLimitException;
use PHPRegex\Parser\Exception\SyntaxErrorException;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\PhpVersionGates;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;

/**
 * Reads the tokens of a pattern into its tree, as PCRE2 reads the pattern.
 *
 * A recursive descent over the tokens the lexer gives: an alternation of
 * sequences of quantified atoms, and a group read for each "(" with the
 * alternation inside it. It follows the PCRE2 release of its target, down
 * to the offset each error is reported at, and reads in this order: the
 * entry and the sequences, with the trivia of "x"; quantifiers; atoms,
 * escapes and references; groups, inline options and verbs; extended
 * classes; names and the faults of a "(?" group it cannot read.
 *
 * The parts with a grammar of their own are read apart: a character class
 * by ClassParser and the condition of a conditional by ConditionParser,
 * both sharing this parser's ParseState, the stream and the modes. Where
 * PCRE refuses a count, an unclosed verb or a callout is asked of
 * RepeatCount, PcreVerb and Callout, which read no parser state. A body
 * PCRE reads from the text, the payload of an alphabetic assertion or an
 * operand of an extended class, is lexed and read by a parser of its own,
 * its offsets moved into the whole pattern.
 *
 * A pattern with a recursion test written before the group its name gives,
 * "(?(R2)b|c)(?<R2>a)", is read a second time, knowing every name.
 *
 * @internal
 */
final class TokenParser
{
    private const INLINE_FLAG_LETTERS = InlineFlags::LETTERS;

    // Token length constants for calculating positions
    private const PCRE_VERB_WRAPPER_LENGTH = 3; // (*...)

    /**
     * The two lookaheads, by the character that introduces them.
     */
    private const LOOKAHEADS = [
        '=' => GroupType::LookaheadPositive,
        '!' => GroupType::LookaheadNegative,
    ];

    /**
     * The two lookbehinds, which follow a "<".
     */
    private const LOOKBEHINDS = [
        '=' => GroupType::LookbehindPositive,
        '!' => GroupType::LookbehindNegative,
    ];

    /**
     * The marker a lookaround carries in its flags when it is non-atomic,
     * "(?*...)" or "(*napla:...)": PCRE may backtrack into it. It is the
     * character that spells it in the short form.
     */
    private const NON_ATOMIC_FLAG = '*';

    /**
     * Token types that are atoms outside a class only: inside one the lexer
     * gives "$", "\b" and "\1" as the characters they are there.
     */
    private const OUTSIDE_ATOM_TYPES = [
        TokenType::Anchor,
        TokenType::Assertion,
        TokenType::Backref,
    ];

    private TokenStream $stream;

    private GroupNameReader $groupNames;

    private string $pattern = '';

    private string $flags = '';

    private bool $extendedMode = false;

    /**
     * Whether "(?xx)" is in force, under which the lexer skips the spaces and
     * tabs that open a class: the body of an alphabetic assertion is read
     * apart in the mode around it.
     */
    private bool $extendedMoreMode = false;

    /**
     * Whether "n" (NO_AUTO_CAPTURE) is in force: a plain "(...)" group then
     * captures nothing and takes no number; named groups still capture.
     */
    private bool $noAutoCapture = false;

    /**
     * The reading state this parser shares with the readers of a class and
     * of a condition: the stream, the pattern, the target, the UTF and
     * bad-escape modes, and whether the tokens are quoted. The stream,
     * pattern, target and UTF mode are also kept on this parser, the same
     * values, which every step reads.
     */
    private ParseState $state;

    /**
     * The reader of the classes of the text this parser reads, made on the
     * first class.
     */
    private ?ClassParser $classes = null;

    /**
     * The reader of the conditions of the text this parser reads, made on
     * the first conditional.
     */
    private ?ConditionParser $conditions = null;

    /**
     * The reader of the extended classes of the text this parser reads, made
     * on the first one.
     */
    private ?ExtendedClassParser $extendedClasses = null;

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

    private readonly int $maxRecursionDepth;

    private readonly PcreTarget $target;

    /**
     * An octal escape and the digits left as text after it, as the atom just
     * read: a quantifier after it repeats only the last digit.
     */
    private ?SequenceNode $splitEscape = null;

    /**
     * The groups opened before the text this parser reads: a body read apart,
     * as "(*pla:...)", counts those of the pattern around it.
     */
    private int $capturesBefore = 0;

    /**
     * The recursion depth the text this parser reads starts at: a body read
     * apart is as deep as the group it stands for in the pattern around it.
     */
    private int $depthBefore = 0;

    /**
     * Whether "(?xx)" holds where the text this parser reads starts.
     */
    private bool $extendedMoreBefore = false;

    /**
     * The group names of the pattern around a body read apart, and whether
     * "J" holds where it opens: PCRE judges a name in the body against the
     * whole pattern.
     */
    private ?GroupNameReader $namesAround = null;

    /**
     * Reads the bodies of the pattern read apart, shared with the parsers
     * of the bodies nested in them: what it found of one body is not read
     * again for the bodies inside it.
     */
    private ?Lexer $partLexer = null;

    /**
     * How many tokens of the stream the last item read whole ends past.
     */
    private int $tokensReadWhole = 0;

    /**
     * Every name the pattern gives a group, once a first reading met a
     * "(?(R)" or "(?(R2)" written before the group so named: PCRE2 looks the
     * name up before it reads a recursion test, wherever the group stands.
     *
     * @var list<string>
     */
    private array $patternNames = [];

    /**
     * The names of the "(?(R)" and "(?(R2)" read as recursion tests for want
     * of a group so named, here and in the bodies read apart.
     *
     * @var list<string>
     */
    private array $recursionTests = [];

    /**
     * The names the groups of the bodies read apart were given.
     *
     * @var list<string>
     */
    private array $namesReadApart = [];

    /**
     * @param PcreTarget|null $target the PHP and PCRE2 judged; the running ones when null
     */
    public function __construct(?int $maxRecursionDepth = null, ?PcreTarget $target = null)
    {
        $this->maxRecursionDepth = $maxRecursionDepth ?? ParserOptions::DEFAULT_MAX_RECURSION_DEPTH;
        $this->target = $target ?? PcreTarget::runtime();
    }

    public function parse(TokenStream $stream, string $flags = '', string $delimiter = '/', int $patternLength = 0): RegexNode
    {
        $firstToken = $stream->getPosition();
        $regex = $this->read($stream, $flags, $delimiter, $patternLength);

        // A recursion test written before the group its name gives, as in
        // "(?(R2)b|c)(?<R2>a)", tests that group: read again, knowing every
        // name.
        $names = [...$this->namesReadApart, ...$this->groupNames->names()];
        if ([] === array_intersect($this->recursionTests, $names)) {
            return $regex;
        }

        $again = new self($this->maxRecursionDepth, $this->target);
        $again->patternNames = $names;
        $stream->setPosition($firstToken);

        return $again->read($stream, $flags, $delimiter, $patternLength);
    }

    /**
     * How many tokens of the stream the items read whole so far end past,
     * at any depth, the last parse failed or not: the stream up to there,
     * its groups closed, parses where the rest did not.
     *
     * @internal
     */
    public function tokensReadWhole(): int
    {
        return $this->tokensReadWhole;
    }

    /**
     * Reads the pattern, or a body read apart, once.
     */
    private function read(TokenStream $stream, string $flags, string $delimiter, int $patternLength): RegexNode
    {
        $this->stream = $stream;
        $this->pattern = $stream->getPattern();
        $this->flags = $flags;
        $this->groupNames = new GroupNameReader($stream, $this->target);
        $this->groupNames->allowDuplicates(str_contains($flags, 'J'));
        // Group names take any letter in Unicode mode, "u" or "(*UTF)" at
        // the start, from PCRE2 10.33.
        $this->unicodeMode = str_contains($flags, 'u')
            || StartOptions::turnUtfOn($this->pattern);
        $this->groupNames->readUnicodeNames($this->unicodeMode && $this->supports(PcreFeature::NonAsciiGroupNames) ? $this->target : null);
        if (null !== $this->namesAround) {
            $this->groupNames->shareNamesWith($this->namesAround);
            $this->groupNames->allowDuplicates($this->namesAround->duplicatesAllowed());
        }

        $this->extendedMode = str_contains($flags, 'x');
        $this->extendedMoreMode = $this->extendedMoreBefore;
        $this->noAutoCapture = str_contains($flags, 'n');
        // Without "X", PHP 7.3 and 7.4 compile with
        // PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL: an escape PCRE2 refuses as it
        // reads it is the character after the backslash. The lexer reads
        // those it can; a reference counted back past the groups opened so
        // far, or an octal escape past a byte, is known here.
        $this->state = new ParseState($stream, $this->pattern, $this->target, $this->unicodeMode, PhpVersionGates::badEscapeIsLiteral($this->target->phpVersionId, $flags));
        $this->classes = null;
        $this->conditions = null;
        $this->extendedClasses = null;
        $this->recursionDepth = $this->depthBefore;
        $this->captureCount = $this->capturesBefore;
        $this->splitEscape = null;
        $this->tokensReadWhole = 0;
        $this->recursionTests = [];
        $this->namesReadApart = [];

        $patternNode = $this->parseAlternation();

        // A ")" no group opened: PCRE2 10.47 reports it past the ")", the
        // releases before on it.
        if ($this->stream->check(TokenType::GroupClose)) {
            $position = $this->target->faultOffset($this->stream->current()->position + 1);

            throw $this->parserException(\sprintf('Unmatched closing parenthesis at position %d.', $position), ErrorCode::GroupUnmatchedClose, $position);
        }

        $this->stream->consume(TokenType::Eof, 'Unexpected content at end of pattern', ErrorCode::TokenUnexpected);

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
        $extendedMoreMode = $this->extendedMoreMode;
        $noAutoCapture = $this->noAutoCapture;
        $duplicateNames = $this->groupNames->duplicatesAllowed();

        try {
            return $this->parseAlternation();
        } finally {
            $this->extendedMode = $extendedMode;
            $this->extendedMoreMode = $extendedMoreMode;
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

            while ($this->stream->match(TokenType::Alternation)) {
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

        // The type of the token under the cursor decides each step: it is
        // read once per item.
        while (TokenType::Eof !== $type = $this->stream->currentType()) {
            if (TokenType::GroupClose === $type || TokenType::Alternation === $type) {
                break;
            }

            if (TokenType::QuoteModeStart === $type || TokenType::QuoteModeEnd === $type) {
                $this->state->inQuoteMode = TokenType::QuoteModeStart === $type;
                $this->stream->advance();

                continue;
            }

            // In extended (/x) mode, consume whitespace and line comments as
            // explicit nodes where appropriate so we can preserve them when
            // reconstructing the pattern.
            if ($this->consumeExtendedModeContent($nodes)) {
                continue;
            }

            if (TokenType::Quantifier !== $type || !$this->quantifyPreviousItem($nodes)) {
                $nodes[] = $this->parseQuantifiedAtom();
            }

            $this->tokensReadWhole = $this->stream->getPosition();
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
     * @param array<NodeInterface> $nodes
     */
    private function consumeExtendedModeContent(array &$nodes): bool
    {
        if (!$this->extendedMode || $this->state->inQuoteMode) {
            return false;
        }

        $skipped = false;
        while (!$this->stream->isAtEnd() && !$this->stream->check(TokenType::GroupClose) && !$this->stream->check(TokenType::Alternation)) {
            $token = $this->stream->current();
            if (TokenType::Literal !== $token->type) {
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

        $comment = $this->state->sourceTextOf($startToken);
        $this->stream->advance();

        while (!$this->stream->isAtEnd()) {
            $token = $this->stream->current();

            // Comment ends at newline (included) or at end of pattern.
            if (TokenType::Literal === $token->type && "\n" === $token->value) {
                $comment .= $this->state->sourceTextOf($token);
                $this->stream->advance();

                break;
            }

            $comment .= $this->state->sourceTextOf($token);
            $this->stream->advance();
        }

        $endPosition = $startPosition + \strlen($comment);

        return new CommentNode($comment, $startPosition, $endPosition, true);
    }

    /**
     * Skip extended-mode (/x) whitespace and comments *without* producing
     * nodes. This is used where the parser needs to see through trivia it
     * does not keep, as in a conditional's opening.
     */
    private function skipExtendedModeContent(): int
    {
        if (!$this->extendedMode || $this->state->inQuoteMode) {
            return 0;
        }

        $skipped = 0;
        while (!$this->stream->isAtEnd() && !$this->stream->check(TokenType::GroupClose) && !$this->stream->check(TokenType::Alternation)) {
            $token = $this->stream->current();
            if (TokenType::Literal !== $token->type) {
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
     * Skip extended-mode (/x) whitespace only. A line comment is left to the
     * sequence, which keeps it as a node: "a # c\n +" is "a+" to PCRE, and
     * the quantifier after the comment goes on the item before it.
     */
    private function skipExtendedWhitespace(): int
    {
        if (!$this->extendedMode || $this->state->inQuoteMode) {
            return 0;
        }

        $skipped = 0;
        while (!$this->stream->isAtEnd() && TokenType::Literal === $this->stream->current()->type && $this->isExtendedWhitespace($this->stream->current()->value)) {
            $this->stream->advance();
            $skipped++;
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
     */
    private function quantifyPreviousItem(array &$nodes): bool
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
        if ($this->unicodeMode && 1 === LibraryPcre::match('/.\z/su', $text, $matches)) {
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

            throw $this->parserException(
                \sprintf('Quantifier without target at position %d.', $position),
                ErrorCode::QuantifierNothingToRepeat,
                $position,
            );
        }

        // "a*(?#c)++": the first "+" is the modifier, the second repeats nothing.
        if (\strlen($token->value) > 1) {
            $position = $this->target->faultOffset($token->position + \strlen($token->value));

            throw $this->parserException(
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

    private function parseQuantifiedAtom(): NodeInterface
    {
        $node = $this->parseAtom();
        // PCRE reads the item before what repeats it.
        $this->tokensReadWhole = $this->stream->getPosition();

        // "\1000*": the octal escape, then the text "0" the quantifier repeats.
        if (null !== $this->splitEscape && $node === $this->splitEscape) {
            $this->splitEscape = null;
            $children = $node->children;
            $text = array_pop($children) ?? $node;
            $last = $this->parseQuantifiedText($text);
            // One sequence: a second quantifier finds the repeated character
            // last in it, as it does after "\Qab\E*".
            $tail = $last instanceof SequenceNode ? $last->children : [$last];

            return $last === $text ? $node : new SequenceNode([...$children, ...$tail], $node->startPosition, $last->getEndPosition());
        }

        // A quantifier after a comment repeats the item before the comment.
        if ($node instanceof CommentNode) {
            return $node;
        }

        $skipped = $this->skipExtendedWhitespace();

        if ($this->stream->match(TokenType::Quantifier)) {
            $token = $this->stream->previous();

            [$prefix, $node] = $this->splitRepeatedCharacter($node);
            $this->assertQuantifierCanApply($node, $token);
            $quantified = $this->quantify($node, $token);

            return null === $prefix ? $quantified : new SequenceNode([$prefix, $quantified], $prefix->getStartPosition(), $quantified->getEndPosition());
        }

        if ($skipped > 0) {
            $this->stream->rewind($skipped);
        }

        return $node;
    }

    /**
     * Digits left as text after an octal escape: a quantifier after them
     * repeats the last one.
     */
    private function parseQuantifiedText(NodeInterface $text): NodeInterface
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

    private function quantify(NodeInterface $node, Token $token): QuantifierNode
    {
        [$quantifier, $type] = $this->parseQuantifierValue($token->value);

        $startPosition = $node->getStartPosition();
        $endPosition = $token->position + \strlen($token->value);

        // In extended (/x) mode, whitespace may separate a quantifier from
        // its lazy/possessive modifier: "a* +" means "a*+" to PCRE. A line
        // comment there is kept by the sequence, which then applies the
        // modifier after it to this quantifier.
        if (QuantifierType::Greedy === $type && $this->extendedMode && !$this->state->inQuoteMode) {
            $skippedModifier = $this->skipExtendedWhitespace();
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

            throw $this->parserException(
                \sprintf('Quantifier does not follow a repeatable item at position %d: a callout cannot be repeated.', $position),
                ErrorCode::QuantifierNothingToRepeat,
                $position,
            );
        }

        if ($this->isEmptyNode($node)) {
            $this->guardQuantifierCount($token);

            throw $this->parserException(
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

            throw $this->parserException(
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

    private function parseAtom(): NodeInterface
    {
        $token = $this->stream->current();
        $startPosition = $token->position;

        // Each type of token opens one kind of item, so the type alone
        // picks the reader; a type that opens none falls through to the
        // errors below. "\Q" and "\E" never get here: the sequence reads
        // them before it asks for an item.
        switch ($token->type) {
            case TokenType::ExtendedClass:
                $this->stream->advance();

                return $this->extendedClasses()->parse($token);
            case TokenType::CommentOpen:
                $this->stream->advance();

                return $this->state->comment();
            case TokenType::Callout:
                $this->stream->advance();

                return $this->parseCallout();
            case TokenType::GroupOpen:
                $this->stream->advance();

                return $this->parsePlainGroup($token);
            case TokenType::GroupModifierOpen:
                $this->stream->advance();

                return $this->parseGroupModifier();
            case TokenType::CharClassOpen:
                $this->stream->advance();

                return $this->classes()->wordBoundary() ?? $this->classes()->parse();
            case TokenType::PcreVerb:
                $this->stream->advance();

                return $this->verbNode($token, $startPosition);
            case TokenType::Quantifier:
                break;
            default:
                if (null !== $node = $this->parseSimpleAtom($startPosition)) {
                    return $node;
                }
        }

        if (TokenType::Quantifier === $token->type) {
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
                ? PcreVerb::unclosedOffset($this->pattern, $position - 1, $this->target, $this->unicodeMode)
                : RepeatCount::nothingToRepeatOffset($this->stream->current(), $this->target);

            // A name that starts with a lowercase letter is an alphabetic
            // assertion PCRE does not know, wherever it ends before PCRE2
            // 10.47, and from 10.47 unless the pattern ends with it.
            if ($unclosedVerb && 1 === LibraryPcre::match('/\G[a-z]/', $this->pattern, $letter, 0, $namePosition)
                && (!$this->supports(PcreFeature::AlphaNameAtPatternEndIsUnclosed) || 1 !== LibraryPcre::match('/\G\w++\z/', $this->pattern, $name, 0, $namePosition))) {
                throw $this->parserException(
                    \sprintf('Unknown alphabetic assertion "(*%s" at position %d.', substr($this->pattern, $namePosition, $position - $namePosition), $position),
                    ErrorCode::VerbInvalid,
                    $position,
                );
            }

            throw $unclosedVerb
                ? $this->parserException(\sprintf('Missing ")" to close the verb at position %d.', $position), ErrorCode::VerbUnclosed, $position)
                : $this->parserException(\sprintf('Quantifier without target at position %d.', $position), ErrorCode::QuantifierNothingToRepeat, $position);
        }

        $val = $this->stream->current()->value;
        $type = $this->stream->current()->type->value;

        throw $this->parserException(
            \sprintf('Unexpected token "%s" (%s) at position %d.', $val, $type, $startPosition),
            ErrorCode::TokenUnexpected,
            $startPosition,
        );
    }

    /**
     * Before PCRE2 10.37, the digits after a "{" read as text are still read
     * as a count, before anything checks that a "}" closes it, and one past
     * 65535 is refused there: "a{65536", "a{65536 }". PCRE2 reads the "{"
     * where the pattern reads an item, so a name or a condition it stands
     * in is refused first.
     *
     * @throws ParserException
     */
    private function guardOpenCountBeforeTen37(Token $token): void
    {
        if (TokenType::Literal !== $token->type || $this->state->inQuoteMode || '{' !== ($this->pattern[$token->position] ?? '')
            || $this->supports(PcreFeature::LargeOpenCountIsText)) {
            return;
        }

        $overflow = RepeatCount::overflowBeforeTen37($this->pattern, $token->position);
        if (null !== $overflow) {
            throw $this->parserException(
                \sprintf('Number too big in "{" at position %d: PCRE2 before 10.37 reads the digits after it as a count, at most 65535.', $overflow),
                ErrorCode::QuantifierTooBig,
                $overflow,
            );
        }
    }

    private function parseSimpleAtom(int $startPosition): ?NodeInterface
    {
        $this->guardNamedReferenceEscape();

        if (null !== $atom = $this->matchAtom($startPosition)) {
            $this->guardOpenCountBeforeTen37($this->stream->previous());

            // Without UTF mode "x" skips a 0x85 byte inside a character too.
            if ($atom instanceof LiteralNode && $this->extendedMode && !$this->unicodeMode && !$this->state->inQuoteMode
                && TokenType::Literal === $this->stream->previous()->type && \strlen($atom->value) > 1 && str_contains($atom->value, "\x85")) {
                return new LiteralNode(str_replace("\x85", '', $atom->value), $atom->getStartPosition(), $atom->getEndPosition(), $atom->isRaw);
            }

            return $atom;
        }

        if ($this->stream->match(TokenType::Dot)) {
            return new DotNode($startPosition, $this->stream->previous()->end());
        }

        if ($this->stream->match(TokenType::GReference)) {
            return $this->parseGReference($startPosition);
        }

        if ($this->stream->match(TokenType::Keep)) {
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
        if (TokenType::LiteralEscaped !== $token->type || 'k' !== $token->value || $this->state->inQuoteMode || $this->readsBadEscapeAt($token->position)) {
            return;
        }

        $opener = $this->pattern[$token->position + 2] ?? '';
        $closer = ['<' => '>', '{' => '}', "'" => "'"][$opener] ?? null;

        if (null === $closer) {
            throw $this->parserException(
                \sprintf('\k is not followed by a braced, angle-bracketed or quoted name at position %d.', $token->position + 2),
                ErrorCode::BackrefInvalidSyntax,
                $token->position + 2,
            );
        }

        $nameStart = $token->position + 3;
        // Inside braces PCRE skips the spaces before the name as well.
        if ('{' === $opener && $this->supports(PcreFeature::PaddedBracedEscapes)) {
            $nameStart += strspn($this->pattern, " \t", $nameStart);
        }
        $nameEnd = $this->groupNames->invalidNameOffset($nameStart);

        if (ErrorCode::GroupNameInvalid === $this->groupNames->nameFault($nameStart, $nameEnd)) {
            throw $this->parserException(
                \sprintf('Group name after \k%s must not start with a digit at position %d.', $opener, $nameEnd),
                ErrorCode::GroupNameInvalid,
                $nameEnd,
            );
        }

        // PCRE measures the name before it looks for what closes it.
        $tooLong = $this->groupNames->tooLongAt($nameStart, $nameEnd);
        if (null !== $tooLong) {
            throw $this->groupNames->tooLongError($nameStart, $nameEnd, $tooLong);
        }

        if ($nameEnd === $nameStart) {
            throw $this->parserException(
                \sprintf('Group name expected after \k%s at position %d.', $opener, $nameStart),
                ErrorCode::GroupNameExpected,
                $nameStart,
            );
        }

        // Inside braces PCRE skips the spaces after the name, then wants
        // the "}": it stops on the first character that is neither.
        $closeAt = $nameEnd;
        if ('}' === $closer && $this->supports(PcreFeature::PaddedBracedEscapes)) {
            $closeAt += strspn($this->pattern, " \t", $nameEnd);
        }

        if ($closer !== ($this->pattern[$closeAt] ?? '')) {
            throw $this->parserException(
                \sprintf('Missing "%s" to close the group name after \k%s at position %d.', $closer, $opener, $closeAt),
                ErrorCode::GroupNameUnterminated,
                $closeAt,
            );
        }
    }

    /**
     * Read the next token if it is an atom, and turn it into a node: a
     * character, read the same way inside a class, or an anchor, an
     * assertion or a back reference, which only stand outside one.
     */
    private function matchAtom(int $startPosition): ?NodeInterface
    {
        $type = $this->stream->currentType();
        if (!\in_array($type, self::OUTSIDE_ATOM_TYPES, true)) {
            return $this->state->matchAtom($startPosition);
        }

        $this->stream->advance();

        return $this->atomFromToken($this->stream->previous(), $type, $startPosition);
    }

    private function atomFromToken(Token $token, TokenType $type, int $startPosition): NodeInterface
    {
        if (TokenType::Backref === $type) {
            if ($this->readsBadEscapeAt($token->position)) {
                return $this->badEscapeLiteral($token, $startPosition);
            }

            $octal = $this->digitsFromUnreadReference($token, $startPosition)
                ?? $this->octalEscapeFromReference($token, $startPosition);
            if (null !== $octal) {
                return $octal;
            }

            $this->guardReferenceNameLength($token->value, $token->position);
        }

        return match ($type) {
            TokenType::Anchor => new AnchorNode($token->value, $startPosition, $token->end()),
            TokenType::Assertion => new AssertionNode($token->value, $startPosition, $token->end()),
            default => new BackrefNode(self::withoutBracePadding($token->value), $startPosition, $token->end()),
        };
    }

    /**
     * The group a plain "(" opens, the "(" read.
     */
    private function parsePlainGroup(Token $startToken): NodeInterface
    {
        // Under "n" a plain group groups and nothing more, as "(?:...)"
        // does; the compiler gives back the "(" it was written with.
        $captures = !$this->noAutoCapture;
        if ($captures) {
            $this->captureCount++;
        }

        $expr = $this->parseScopedAlternation();
        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);

        return $this->createGroupNode(
            $expr,
            $captures ? GroupType::Capturing : GroupType::NonCapturing,
            $startToken->position,
            $endToken,
        );
    }

    /**
     * The node of a verb token: a verb, or the group an alphabetic assertion
     * or a script run stands for, its body read from its text. A body read
     * in place by the lexer, one that never closes or any body when every
     * one is read in place, has the opener alone for its token, with no ")"
     * in its text, "(*pla:" or "(?*": the tokens after it are its body, up
     * to its ")", or to the ")" PCRE misses at the end.
     */
    private function verbNode(Token $token, int $startPosition): NodeInterface
    {
        // "(**)" reads as "(?*)" does, a token whose value starts with "*":
        // the text tells them apart.
        if ('**' === substr($this->pattern, $token->position + 1, 2)) {
            throw PcreVerb::doubleStarError($this->pattern, $token->position + 2);
        }

        if ($token->end() - $token->position !== \strlen($token->value) + 2) {
            return $this->createPcreVerbNode($token->value, $startPosition, $startPosition + \strlen($token->value) + self::PCRE_VERB_WRAPPER_LENGTH);
        }

        $read = PcreVerb::read($token->value);
        // "(*scs:(1)" that no ")" closes: PCRE reads its list of groups
        // before the body, refusing a group it names that it cannot be.
        if (null !== $read->scanSubstring) {
            $this->readGroupList($token->position + 3 + \strlen($read->scanSubstring) + 1);
        }

        $body = $this->parseScopedAlternation();
        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);

        // Reached only from the tokens of Lexer::tokenizeInPlace(), which
        // reads a closed body in place too: "(*pla:(a))b". Lexer::tokenize()
        // keeps a closed body in the opener's token, so there no ")" closes
        // a body read here.
        return $this->createGroupNode($body, $read->assertion ?? GroupType::NonCapturing, $startPosition, $endToken, null, $read->nonAtomic ? self::NON_ATOMIC_FLAG : null);
    }

    /**
     * parses callouts like (?C), (?C1), (?C"name"), (?C"string"), and (?Cname)
     */
    private function parseCallout(): CalloutNode
    {
        return Callout::read($this->stream->previous(), $this->pattern, $this->target);
    }

    /**
     * parses \g references (backreferences and subroutines)
     */
    private function parseGReference(int $startPosition): NodeInterface
    {
        $token = $this->stream->previous();
        if ($this->readsBadEscapeAt($token->position)) {
            return $this->badEscapeLiteral($token, $startPosition);
        }

        $endPosition = $startPosition + \strlen($token->value);
        $value = self::withoutBracePadding($token->value);
        $this->guardReferenceNameLength($token->value, $token->position);

        // \g{N} or \gN (numeric, incl. relative) -> Backreference; \g'N',
        // like \g<N>, calls the group instead. Braces hold one sign at most,
        // then digits only: "\g{1-}" and "\g{--1}" are refused below.
        if (1 === LibraryPcre::match('/^\\\\g(?:\{[+-]?\d++\}|[0-9+-]++)$/', $value)) {
            return new BackrefNode($value, $startPosition, $endPosition);
        }

        // PCRE reads the number after "\g{", "\g<" or "\g'" before it looks
        // for what closes it, and refuses one it cannot read right after
        // the "g", on every release.
        $numberError = $this->gReferenceNumberError($token->position);
        if (null !== $numberError) {
            throw $this->parserException(\sprintf('Invalid \\g reference %s at position %d: %s.', $value, $token->position + 2, $numberError[1]), $numberError[0], $token->position + 2);
        }

        // \g{name} is a back reference, like \k{name}; it is recorded that
        // way, and the compiler gives back the spelling the pattern used.
        if (1 === LibraryPcre::match('/^\\\\g\{([\p{L}\p{Nd}_]++)\}$/u', $value, $m)) {
            return new BackrefNode('\\k{'.$m[1].'}', $startPosition, $endPosition);
        }

        // "\g<5fg>": PCRE reads a number after "\g<" or "\g'", and wants the
        // closing character right after it; before PCRE2 10.47, it refuses
        // the "\g" itself.
        if (1 === LibraryPcre::match('/^\\\\g[<\'][+-]?\d/', $value) && 1 !== LibraryPcre::match('/^\\\\g(?:<[+-]?\d++>|\'[+-]?\d++\')$/', $value)) {
            $position = $this->gReferenceErrorOffset($token->position);

            throw $this->parserException(
                \sprintf('Invalid subroutine call %s at position %d: the group number is not followed by what closes it.', $value, $position),
                $this->supports(PcreFeature::GReferenceNumberReadBeforeClosing) ? ErrorCode::SubroutineInvalidSyntax : ErrorCode::BackrefInvalidSyntax,
                $position,
            );
        }

        // \g<name> and \g'name' (non-numeric) call the group -> Subroutine;
        // a sign before a name is no name.
        if (1 === LibraryPcre::match('/^\\\\g<([+-]?\d++|[\p{L}\p{Nd}_]++)>$/u', $value, $m)) {
            return new SubroutineNode($m[1], 'g', $startPosition, $endPosition);
        }

        if (1 === LibraryPcre::match('/^\\\\g\'([+-]?\d++|[\p{L}\p{Nd}_]++)\'$/u', $value, $m)) {
            return new SubroutineNode($m[1], 'g', $startPosition, $endPosition);
        }

        $position = $this->gReferenceErrorOffset($token->position);
        $code = $this->gReferenceErrorCode($token->position, $position);

        throw $this->parserException(
            \sprintf('Invalid \\g reference syntax: %s at position %d%s', $value, $position, match ($code) {
                ErrorCode::GroupNameExpected => ': a group name or number is expected.',
                ErrorCode::GroupNameUnterminated => ': nothing closes the group name there.',
                default => '.',
            }),
            $code,
            $position,
        );
    }

    /**
     * The error PCRE's read_number() gives the number after the "\g{",
     * "\g<" or "\g'" whose backslash stands at $start, and its reason: a
     * number past 65535, a forward one counted from the groups opened before
     * it included; a relative zero; a backward one past the groups opened.
     * Null when it reads the number, or none stands there. Only "\g{" takes
     * spaces and tabs before it, from PCRE2 10.43.
     *
     * @return array{ErrorCode, string}|null
     */
    private function gReferenceNumberError(int $start): ?array
    {
        $opener = $this->pattern[$start + 2] ?? '';
        if (!\in_array($opener, ['{', '<', "'"], true)) {
            return null;
        }

        $at = $start + 3;
        if ('{' === $opener && $this->supports(PcreFeature::PaddedBracedEscapes)) {
            $at += strspn($this->pattern, " \t", $at);
        }

        if (1 !== LibraryPcre::match('/\G([+-]?)(\d++)/', $this->pattern, $number, 0, $at)) {
            return null;
        }

        [, $sign, $digits] = $number;
        $value = (int) $digits;
        $call = '{' !== $opener;

        return match (true) {
            PcreNumber::exceeds($digits) || ('+' === $sign && $value + $this->captureCount > PcreNumber::MAX) => [ErrorCode::GroupNumberTooBig, 'the group number is too big: PCRE takes at most 65535'],
            '' !== $sign && 0 === $value => [$call ? ErrorCode::SubroutineRelativeZero : ErrorCode::BackrefZero, 'a relative number may not be zero'],
            '-' === $sign && $value > $this->captureCount => [$call ? ErrorCode::SubroutineRelativeMissing : ErrorCode::BackrefRelative, \sprintf('-%d counts back past the %d groups opened before it', $value, $this->captureCount)],
            default => null,
        };
    }

    /**
     * What PCRE reports for a "\g" it stops reading at $position: after a
     * "{", "<" or quote, a number not closed is a syntax error in it, and
     * otherwise PCRE reads a name, which is either missing or not closed.
     *
     * @param int $start the offset of the backslash
     */
    private function gReferenceErrorCode(int $start, int $position): ErrorCode
    {
        $opener = $this->pattern[$start + 2] ?? '';
        if (!\in_array($opener, ['{', '<', "'"], true)) {
            return ErrorCode::BackrefInvalidSyntax;
        }

        // Only "\g{" takes spaces and tabs before a name or number, from
        // PCRE2 10.43.
        $nameStart = $start + 3 + ('{' === $opener && $this->supports(PcreFeature::PaddedBracedEscapes) ? strspn($this->pattern, " \t", $start + 3) : 0);

        if (1 === LibraryPcre::match('/\G[+-]?\d/', $this->pattern, $matches, 0, $nameStart)) {
            return ErrorCode::BackrefInvalidSyntax;
        }

        // PCRE measures the name before it looks for what closes it.
        if (!$this->groupNames->startsWithDigit($nameStart)
            && $position === $this->groupNames->tooLongAt($nameStart, $this->groupNames->invalidNameOffset($nameStart))) {
            return ErrorCode::GroupNameTooLong;
        }

        return $this->groupNames->nameFault($nameStart, $position);
    }

    /**
     * PCRE reads the name of "\k<name>", "\g{name}" and the like before it
     * looks the group up, and stops where a name past the limit ends.
     *
     * @param int $start the offset of the backslash
     */
    private function guardReferenceNameLength(string $reference, int $start): void
    {
        if (1 !== LibraryPcre::match('/^\\\\[gk]([<{\'])/', $reference, $matches)) {
            return;
        }

        // Only braces take spaces and tabs before the name, from PCRE2 10.43;
        // the name ends where the characters a name holds end.
        $nameStart = $start + 3;
        if ('{' === $matches[1] && $this->supports(PcreFeature::PaddedBracedEscapes)) {
            $nameStart += strspn($this->pattern, " \t", $nameStart);
        }

        if ($this->groupNames->startsWithDigit($nameStart)) {
            return;
        }

        $nameEnd = $this->groupNames->invalidNameOffset($nameStart);
        $tooLong = $this->groupNames->tooLongAt($nameStart, $nameEnd);
        if (null !== $tooLong) {
            throw $this->groupNames->tooLongError($nameStart, $nameEnd, $tooLong);
        }
    }

    /**
     * "\g{ 1 }" and "\k{ name }" refer to what "\g{1}" and "\k{name}" do:
     * PCRE2 10.43 lets spaces and tabs follow the "{" and precede the "}".
     * Whether the target PCRE2 takes them is the validator's to say.
     */
    private static function withoutBracePadding(string $reference): string
    {
        return LibraryPcre::replace('/^(\\\\[gk]\{)[ \t]*+(.*?)[ \t]*+\}$/', '$1$2}', $reference) ?? $reference;
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

        // Only "\g{...}" takes spaces around what it holds, from PCRE2 10.43.
        $blanks = '}' === $closing && $this->supports(PcreFeature::PaddedBracedEscapes) ? " \t" : '';
        $position++;
        $position += strspn($this->pattern, $blanks, $position);

        if (1 === LibraryPcre::match('/\G[+-]?\d++/', $this->pattern, $matches, 0, $position)) {
            // Before PCRE2 10.47, a number nothing closes fails "\g" itself,
            // or, before 10.43, the space after "{" that pads it.
            if (!$this->supports(PcreFeature::GReferenceNumberReadBeforeClosing)) {
                $padded = str_contains(" \t", $this->pattern[$start + 3] ?? 'x');

                return $start + ($padded && !$this->supports(PcreFeature::PaddedBracedEscapes) ? 3 : 2);
            }

            $position += \strlen($matches[0]);
        } elseif ($this->groupNames->startsWithDigit($position)) {
            // A digit first is refused there, before any padding.
            return $this->groupNames->invalidNameOffset($position);
        } else {
            $nameStart = $position;
            $position = $this->groupNames->invalidNameOffset($position);
            $tooLong = $this->groupNames->tooLongAt($nameStart, $position);
            if (null !== $tooLong) {
                return $tooLong;
            }
        }

        return $position + strspn($this->pattern, $blanks, $position);
    }

    /**
     * Whether PHP 7.3 and 7.4 read the escape whose backslash stands at
     * $position, outside a class, as the character after the backslash,
     * knowing the groups opened so far.
     */
    private function readsBadEscapeAt(int $position): bool
    {
        return $this->state->badEscapeIsLiteral && !$this->state->inQuoteMode
            && BadEscape::at($this->pattern, $position, false, $this->unicodeMode, $this->target, $this->captureCount);
    }

    /**
     * An escape read as the character after its backslash: "\g{-1}" with
     * no group open is "g" then the text "{-1}", "\400" without UTF is "4"
     * then "00". What follows the character is plain text in either, but
     * for the "+" of "\g{+n}", "\g<+n>", "\g'+n'" or "\g+n", which repeats
     * the character before it: "g{+65535}" is "g", "{" once or more, then
     * the text "65535}".
     */
    private function badEscapeLiteral(Token $token, int $startPosition): SequenceNode
    {
        $characterEnd = $startPosition + 2;
        $end = $token->end();
        $character = new LiteralNode($this->pattern[$startPosition + 1], $startPosition, $characterEnd);
        $parts = [$character];
        $textStart = $characterEnd;
        $plusAt = '+' === ($this->pattern[$characterEnd] ?? '') ? $characterEnd : $characterEnd + 1;
        if ('g' === $character->value && '+' === ($this->pattern[$plusAt] ?? '') && $plusAt < $end) {
            $parts = $plusAt === $characterEnd
                ? [new QuantifierNode($character, '+', QuantifierType::Greedy, $startPosition, $plusAt + 1)]
                : [$character, new QuantifierNode(new LiteralNode($this->pattern[$characterEnd], $characterEnd, $plusAt), '+', QuantifierType::Greedy, $characterEnd, $plusAt + 1)];
            $textStart = $plusAt + 1;
        }

        $parts[] = new LiteralNode(substr($this->pattern, $textStart, $end - $textStart), $textStart, $end);
        $this->splitEscape = new SequenceNode($parts, $startPosition, $end);

        return $this->splitEscape;
    }

    /**
     * Before PCRE2 10.45 a number past 214748363 is read as no reference:
     * "\8" or "\9" and eight digits or more is then the digit, and the
     * digits after it text.
     */
    private function digitsFromUnreadReference(Token $token, int $startPosition): ?NodeInterface
    {
        if ($this->supports(PcreFeature::HugeBackreferenceNumberIsReference) || 1 !== LibraryPcre::match('/^\\\\([89]\d{8,})$/', $token->value, $matches)) {
            return null;
        }

        $digitEnd = $startPosition + 2;
        $this->splitEscape = new SequenceNode(
            [new LiteralNode($matches[1][0], $startPosition, $digitEnd), new LiteralNode(substr($matches[1], 1), $digitEnd, $token->end())],
            $startPosition,
            $token->end(),
        );

        return $this->splitEscape;
    }

    /**
     * "\NN" of 10 or more that starts with 1 to 7, past the groups opened so
     * far, is no reference: PCRE reads an octal escape of up to three octal
     * digits, and the digits left as text, "\1000" being "@" then "0".
     */
    private function octalEscapeFromReference(Token $token, int $startPosition): ?NodeInterface
    {
        if (1 !== LibraryPcre::match('/^\\\\([1-7]\d++)$/', $token->value, $matches)
            || (\strlen($matches[1]) < 6 && (int) $matches[1] <= $this->captureCount)
            || 1 !== LibraryPcre::match('/^[0-7]{1,3}/', $matches[1], $octal)) {
            return null;
        }

        $representation = '\\'.$octal[0];
        $escapeEnd = $startPosition + \strlen($representation);
        $escape = new CharLiteralNode(
            $representation,
            CodePointReader::fromLiteral($representation, CharLiteralType::OctalLegacy),
            CharLiteralType::OctalLegacy,
            $startPosition,
            $escapeEnd,
        );

        $text = substr($matches[1], \strlen($octal[0]));
        if ('' === $text) {
            return $escape;
        }

        $this->splitEscape = new SequenceNode([$escape, new LiteralNode($text, $escapeEnd, $token->end())], $startPosition, $token->end());

        return $this->splitEscape;
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
        if ($this->stream->match(TokenType::Callout)) {
            return $this->conditions()->calloutConditional($startPosition);
        }

        // 2.1 "(?(" followed by "(*...)" is a conditional whose condition is
        // spelled as a verb: "(?(*pla:a)yes|no)".
        if ($this->stream->match(TokenType::PcreVerb)) {
            return $this->conditions()->verbConditional($startPosition, $this->stream->previous());
        }

        // 2.2 "(?(?[a])b)": an extended class is no assertion; PCRE refuses
        // it past the "(", on it before PCRE2 10.47.
        if ($this->stream->check(TokenType::ExtendedClass) && $this->stream->current()->position === $startPosition + 2) {
            $position = $this->target->faultOffset($this->stream->current()->position + 1);

            throw $this->parserException(
                \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after "(?(".', $position),
                ErrorCode::ConditionalAssertionExpected,
                $position,
            );
        }

        // 2.3 "(?(?#c)(?=a)b)": a comment where the condition starts is
        // skipped, and the assertion is due after it.
        if ($this->stream->check(TokenType::CommentOpen) && $this->stream->current()->position === $startPosition + 2) {
            return $this->conditions()->commentedConditional($startPosition);
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
        if ($this->stream->match(TokenType::GroupModifierOpen)) {
            $isConditionalWithModifier = true;
        } elseif ($this->stream->match(TokenType::GroupOpen)) {
            $isConditionalWithModifier = false;
        }

        if (null !== $isConditionalWithModifier) {
            return $this->conditions()->conditional($startPosition, $isConditionalWithModifier, $this->captureCount);
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

        $verb = $this->state->consumeWhile(static fn (string $c): bool => ':' !== $c);

        // A verb may carry an argument, as "(*MARK:name)" does.
        $argument = $this->stream->matchLiteral(':')
            ? $this->state->consumeWhile(static fn (): bool => true)
            : '';

        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected ) to close PCRE verb', ErrorCode::VerbUnclosed);
        $endPosition = $endToken->position + 1;

        // Parse the rest of the pattern after the verb group
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
     * Parses a raw sub-pattern string (e.g. the payload of an alphabetic
     * assertion verb) into an AST. Node positions are those of the enclosing
     * pattern: the payload is read from $absoluteOffset on.
     */
    private function parseSubPattern(string $payload, int $absoluteOffset): NodeInterface
    {
        if ('' === $payload) {
            return $this->createEmptyLiteralNodeAt($absoluteOffset);
        }

        // An inline "(?n)" or "(?x)" before the payload still holds inside
        // it, and a "(?-x)" still turns "x" off there.
        // An inline "(?-n)" turns "n" off there as well.
        $flags = str_replace(['x', 'n'], '', $this->flags).($this->extendedMode ? 'x' : '').($this->noAutoCapture ? 'n' : '');

        // The payload is read for the same target as the pattern around it,
        // its tokens moved to where they stand in the whole pattern.
        $this->partLexer ??= new Lexer($this->target);

        try {
            $stream = $this->partLexer->tokenizePart($this->pattern, $absoluteOffset, \strlen($payload), $flags, $this->extendedMoreMode);
        } catch (LexerException $error) {
            // The lexer around it only found where the body ends: an escape
            // it refuses in the body, as "\x{}", is met here, where the body
            // is read, and placed in the whole pattern.
            throw $this->state->movedError($error, $absoluteOffset);
        }

        $tokens = [];
        foreach ($stream->getTokens() as $token) {
            $tokens[] = new Token($token->type, $token->value, $token->position + $absoluteOffset, $token->sourceLength);
        }

        $inner = new TokenParser($this->maxRecursionDepth, $this->target);
        $inner->capturesBefore = $this->captureCount;
        $inner->depthBefore = $this->recursionDepth;
        $inner->extendedMoreBefore = $this->extendedMoreMode;
        $inner->namesAround = $this->groupNames;
        $inner->partLexer = $this->partLexer;
        $inner->patternNames = $this->patternNames;
        // Read once: what it found of names is judged with the whole pattern.
        $pattern = $inner->read(new TokenStream($tokens, $this->pattern), $flags, '/', \strlen($this->pattern));
        $this->recursionTests = [...$this->recursionTests, ...$inner->recursionTests];
        $this->namesReadApart = [...$this->namesReadApart, ...$inner->namesReadApart, ...$inner->groupNames->names()];

        // The groups it holds take numbers in the enclosing pattern: the
        // body's parser counted on from those opened before it, and shared
        // the names. A "(?J)" set in the body holds there only.
        $this->captureCount = $inner->captureCount;

        return $pattern->pattern;
    }

    /**
     * "(*scs:(1,<name>)body)": the list of groups, then the body, read apart
     * like the body of any alphabetic assertion.
     */
    private function createScanSubstringNode(string $name, int $startPosition, int $endPosition): GroupNode
    {
        $listOpen = $startPosition + 2 + \strlen($name) + 1;
        if ('(' !== ($this->pattern[$listOpen] ?? '')) {
            throw $this->groupListError([$listOpen, ErrorCode::ScanSubstringMissingList, \sprintf('Missing "(" to open the groups of (*%s: at position %d.', $name, $listOpen)]);
        }

        [$groups, $bodyStart] = $this->readGroupList($listOpen + 1);
        $body = substr($this->pattern, $bodyStart, $endPosition - 1 - $bodyStart);

        return new GroupNode(
            $this->parseSubPattern($body, $bodyStart),
            GroupType::ScanSubstring,
            // The spelling, "scs" or "scan_substring".
            $name,
            null,
            $startPosition,
            $endPosition,
            false,
            $groups,
        );
    }

    private function createPcreVerbNode(string $verb, int $startPosition, int $endPosition): NodeInterface
    {
        $read = PcreVerb::read($verb);

        if (null !== $read->scanSubstring && $this->supports(PcreFeature::ScanSubstring)) {
            return $this->createScanSubstringNode($read->scanSubstring, $startPosition, $endPosition);
        }

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
            $returned = $this->readReturnedGroups() ?? [];
            $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected ) to close subroutine call', $this->callLeftOpenCode());

            return new SubroutineNode($name, 'P>', $startPos, $endToken->position + 1, $returned);
        }

        if ($this->stream->matchLiteral('=')) {
            $name = $this->groupNames->read(false);
            $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupNameUnterminated);

            return new BackrefNode('\\k<'.$name.'>', $startPos, $endToken->position + 1);
        }

        // A pattern that ends there misses the ")" of the group.
        if ($pPos + 1 >= \strlen($this->pattern)) {
            throw $this->parserException(\sprintf('Missing ")" to close "(?P" at position %d.', $pPos + 1), ErrorCode::GroupUnclosed, $pPos + 1);
        }

        // PCRE reports it past the character it could not read from PCRE2
        // 10.47, all its bytes in UTF mode; on it before.
        $length = $this->unicodeMode && 1 === LibraryPcre::match('/\G./su', $this->pattern, $character, 0, $pPos + 1) ? \strlen($character[0]) : 1;
        $position = $this->target->faultOffset($pPos + 1 + $length, $length);

        throw $this->parserException(
            \sprintf('Invalid syntax after (?P at position %d: "<", ">" or "=" is expected.', $position),
            ErrorCode::GroupSyntax,
            $position,
        );
    }

    /**
     * Parses standard groups like (?<=...), (?<!...), and (?<name>...).
     */
    private function parseStandardGroup(int $startPos): NodeInterface
    {
        // "(?<*...)" is the non-atomic lookbehind; the "*" arrives as a
        // quantifier token. In "(?<*+" or "(?<*?" the "+" or "?" that came
        // with it repeats nothing. Before PCRE2 10.35 the "*" is where a
        // group name is expected.
        if ($this->stream->check(TokenType::Quantifier) && str_starts_with($this->stream->current()->value, '*') && !$this->supports(PcreFeature::NonAtomicAssertionShortSyntax)) {
            $position = $this->stream->current()->position;

            throw $this->parserException(
                \sprintf('Expected group name at position %d: PCRE2 10.35 added "(?<*" for "(*naplb:"%s.', $position, $this->supports(PcreFeature::NonAtomicAssertionNames) ? '; write "(*naplb:" instead' : ''),
                ErrorCode::GroupNameExpected,
                $position,
            );
        }

        if ($this->stream->check(TokenType::Quantifier) && \in_array($this->stream->current()->value, ['*+', '*?'], true)) {
            $token = $this->stream->current();
            $position = $this->target->faultOffset($token->position + \strlen($token->value));

            throw $this->parserException(
                \sprintf('Quantifier without target at position %d.', $position),
                ErrorCode::QuantifierNothingToRepeat,
                $position,
            );
        }

        if ($this->stream->check(TokenType::Quantifier) && '*' === $this->stream->current()->value) {
            $this->stream->advance();
            $expr = $this->parseScopedAlternation();
            $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);

            return $this->createGroupNode($expr, GroupType::LookbehindPositive, $startPos, $endToken, null, self::NON_ATOMIC_FLAG);
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
        } elseif ($this->stream->check(TokenType::Quantifier) && '+' === $this->stream->current()->value) {
            // "+" after "(?" is lexed as a quantifier token; here it is the
            // sign of a relative subroutine call like (?+1).
            $this->stream->advance();
            $num = '+';
            $tokensConsumed++;
        }

        if ($this->state->isLiteralDigitToken()) {
            $num .= $this->stream->current()->value;
            $this->stream->advance();
            $tokensConsumed++;

            // Consume additional digits
            while ($this->stream->check(TokenType::Literal) && Ascii::isDigit($this->stream->current()->value)) {
                $num .= $this->stream->current()->value;
                $this->stream->advance();
                $tokensConsumed++;
            }

            $returned = $this->readReturnedGroups();
            if (null !== $returned || $this->stream->check(TokenType::GroupClose)) {
                $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);

                return new SubroutineNode($num, '', $startPos, $endToken->position + 1, $returned ?? []);
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
     * "(?1(2,<name>))": the groups a call returns, read from PCRE2 10.47 when
     * a "(" follows the reference. PCRE reads the list from the text, and
     * refuses what it cannot take as it reads it: an item that is no number
     * or name, group zero, a relative zero, a group before the first, or a
     * number past 65535. Null when no list follows.
     *
     * @return list<string>|null
     */
    private function readReturnedGroups(): ?array
    {
        // Whatever the lexer made of the "(" and what follows it, as "(?:".
        $open = $this->stream->current()->position;
        if (!$this->supports(PcreFeature::CallsReturnCaptureGroups) || $this->stream->isAtEnd() || '(' !== ($this->pattern[$open] ?? '')) {
            return null;
        }

        [$groups, $at] = $this->readGroupList($open + 1);

        while (!$this->stream->isAtEnd() && $this->stream->current()->position < $at) {
            $this->stream->advance();
        }

        return $groups;
    }

    /**
     * A list of groups, "2,-1,<name>,'name'", from $at to its ")": the groups
     * a call returns or a substring scan matches. PCRE reads it from the
     * text, and refuses what it cannot take as it reads it: an item that is
     * no number or name, group zero, a relative zero, a group before the
     * first, or a number past 65535.
     *
     * @return array{0: list<string>, 1: int} the items as written, and the
     *                                        offset past the ")"
     */
    private function readGroupList(int $at): array
    {
        // What stops PCRE reading the list, if anything; the numbers it has
        // read before are judged first.
        $fault = PcreVerb::groupListFault($this->pattern, $at - 1, $this->target, $this->unicodeMode);

        $groups = [];
        while (true) {
            if (1 !== LibraryPcre::match('/\G(?:([+-]?)(\d++)|<[^>]*+>|\'[^\']*+\')/', $this->pattern, $matches, 0, $at)) {
                // The reader stops on such an item too: the fallback is for the type.
                throw $this->groupListError($fault ?? [$at, ErrorCode::GroupListItemExpected, \sprintf('Expected a capture group number or name at position %d.', $at)]);
            }

            $itemEnd = $at + \strlen($matches[0]);
            $sign = $matches[1] ?? '';
            $digits = $matches[2] ?? '';
            if ('' !== $digits) {
                [$refused, $code] = match (true) {
                    PcreNumber::exceeds($digits) => ['is too big', ErrorCode::GroupNumberTooBig],
                    0 === (int) $digits => '' === $sign
                        ? ['names no group', ErrorCode::GroupListMissingGroup]
                        : ['is a relative zero', ErrorCode::GroupListRelativeZero],
                    '-' === $sign && (int) $digits > $this->captureCount => ['names no group', ErrorCode::GroupListMissingGroup],
                    default => [null, null],
                };
                if (null !== $refused && null !== $code) {
                    throw $this->parserException(\sprintf('Group "%s" listed at position %d %s.', $matches[0], $itemEnd, $refused), $code, $itemEnd);
                }
            }

            // A name PCRE refuses, or no "," or ")" after the item.
            if (null !== $fault && $fault[0] <= $itemEnd) {
                throw $this->groupListError($fault);
            }

            $groups[] = $matches[0];
            $at = $itemEnd + 1;
            if (')' === ($this->pattern[$itemEnd] ?? '')) {
                return [$groups, $at];
            }
        }
    }

    /**
     * @param array{0: int, 1: ErrorCode, 2: string} $fault
     */
    private function groupListError(array $fault): ParserException
    {
        if (!$this->supports(PcreFeature::GroupListErrors)) {
            $fault = PcreVerb::groupListFaultBefore1047($fault, \strlen($this->pattern));
        }

        return $this->parserException($fault[2], $fault[1], $fault[0]);
    }

    /**
     * The code of a call by name the pattern ends in: before PCRE2 10.47 the
     * name wants a ")" right after it, from 10.47 the call does.
     */
    private function callLeftOpenCode(): ErrorCode
    {
        return $this->supports(PcreFeature::CallsReturnCaptureGroups) ? ErrorCode::GroupUnclosed : ErrorCode::GroupNameUnterminated;
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
        $returned = $this->readReturnedGroups() ?? [];
        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected ) to close subroutine call', $this->callLeftOpenCode());

        return new SubroutineNode($name, '&', $startPosition, $endToken->position + 1, $returned);
    }

    /**
     * Parses a numeric or R subroutine group modifier like (?R), (?1), (?-1).
     */
    private function parseNumericSubroutineModifier(int $startPosition): ?SubroutineNode
    {
        if ($this->stream->matchLiteral('R')) {
            $returned = $this->readReturnedGroups();
            if (null !== $returned || $this->stream->check(TokenType::GroupClose)) {
                $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);

                return new SubroutineNode('R', '', $startPosition, $endToken->position + 1, $returned ?? []);
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
            return $this->parseSimpleGroup($startPosition, GroupType::NonCapturing);
        }

        if ($this->stream->matchLiteral('=')) {
            return $this->parseSimpleGroup($startPosition, GroupType::LookaheadPositive);
        }

        if ($this->stream->matchLiteral('!')) {
            return $this->parseSimpleGroup($startPosition, GroupType::LookaheadNegative);
        }

        if ($this->stream->matchLiteral('>')) {
            return $this->parseSimpleGroup($startPosition, GroupType::Atomic);
        }

        if ($this->stream->match(TokenType::Alternation)) {
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
        $extendedMoreMode = $this->extendedMoreMode;
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
            } while ($this->stream->match(TokenType::Alternation));
        } finally {
            $this->recursionDepth--;
            $this->extendedMode = $extendedMode;
            $this->extendedMoreMode = $extendedMoreMode;
            $this->noAutoCapture = $noAutoCapture;
            $this->groupNames->allowDuplicates($duplicateNames);
        }

        $this->captureCount = $highest;

        $expr = 1 === \count($branches)
            ? $branches[0]
            : new AlternationNode($branches, $branchStart, end($branches)->getEndPosition());

        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);

        return $this->createGroupNode($expr, GroupType::BranchReset, $startPosition, $endToken);
    }

    /**
     * Parses inline flags and optional sub-expressions (?(?flags:...)).
     */
    private function parseInlineFlags(int $startPosition): NodeInterface
    {
        $flags = $this->readModifierLetters();

        // "(?^" turns every other modifier off already, so PCRE refuses a
        // "-" after it, and reports it past the hyphen (on it before 10.47).
        if (str_starts_with($flags, '^') && str_contains($flags, '-')) {
            $position = $this->target->faultOffset($startPosition + 2 + (int) strpos($flags, '-') + 1);

            throw $this->parserException(
                \sprintf('Invalid hyphen in option setting at position %d: "(?^" cannot turn modifiers off.', $position),
                ErrorCode::GroupOptionHyphen,
                $position,
            );
        }

        $letters = self::INLINE_FLAG_LETTERS.($this->supports(PcreFeature::CaselessRestrictModifier) ? 'r' : '');
        // The ASCII options were only read where PCRE2 takes them.
        $tracked = InlineFlags::withoutAsciiOptions($flags);
        $modifiers = InlineFlags::read($tracked, $letters);

        // "(?)", "(?-)" and "(?-:...)" set nothing, and PCRE takes them as
        // such, as "(?a)" or "(?-aD)" set nothing this library tracks; "(?A:"
        // or "(?{" is no option setting at all.
        $setsNothing = ('' === $flags && $this->stream->check(TokenType::GroupClose))
            || ('-' === $flags && ($this->stream->check(TokenType::GroupClose) || $this->stream->checkLiteral(':')))
            || ($tracked !== $flags && \in_array($tracked, ['', '-'], true));

        if (null === $modifiers && !$setsNothing) {
            throw $this->unreadableGroupError($startPosition);
        }

        $wasExtended = $this->extendedMode;
        $wasExtendedMore = $this->extendedMoreMode;
        $wasNoAutoCapture = $this->noAutoCapture;
        $wasAllowingDuplicates = $this->groupNames->duplicatesAllowed();

        if ($modifiers?->turnsOn('J')) {
            $this->groupNames->allowDuplicates(true);
        }
        if ($modifiers?->turnsOff('J')) {
            $this->groupNames->allowDuplicates(false);
        }

        $this->extendedMode = $modifiers?->inForce('x', $this->extendedMode) ?? $this->extendedMode;
        $this->extendedMoreMode = $modifiers?->extendedMoreInForce($this->extendedMoreMode) ?? $this->extendedMoreMode;
        $this->noAutoCapture = $modifiers?->inForce('n', $this->noAutoCapture) ?? $this->noAutoCapture;

        // "(?iz)": the letters are read as far as PCRE knows them.
        if (!$this->stream->check(TokenType::GroupClose) && !$this->stream->checkLiteral(':')) {
            throw $this->unreadableGroupError($startPosition);
        }

        $expr = null;
        if ($this->stream->matchLiteral(':')) {
            $expr = $this->parseScopedAlternation();
            // "(?x:...)" only covers its own group; "(?x)" keeps going.
            $this->extendedMode = $wasExtended;
            $this->extendedMoreMode = $wasExtendedMore;
            $this->noAutoCapture = $wasNoAutoCapture;
            $this->groupNames->allowDuplicates($wasAllowingDuplicates);
        }

        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);
        $expr ??= $this->createEmptyLiteralNodeAt($this->stream->previous()->position);

        return $this->createGroupNode(
            $expr,
            GroupType::InlineFlags,
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

        if ($this->stream->check(TokenType::Anchor) && '^' === $this->stream->current()->value) {
            $letters = '^';
            $this->stream->advance();
        }

        $accepted = self::INLINE_FLAG_LETTERS.($this->supports(PcreFeature::CaselessRestrictModifier) ? 'r' : '');

        // From PCRE2 10.43, "a" may take one of "D", "S", "W", "P", "T".
        $ascii = $this->supports(PcreFeature::AsciiOptions);
        $afterA = false;

        return $letters.$this->state->consumeWhile(
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

    private function classes(): ClassParser
    {
        return $this->classes ??= new ClassParser($this->state);
    }

    /**
     * The reader of extended classes, which calls this parser back for each
     * operand it reads apart and for the nesting limit.
     */
    private function extendedClasses(): ExtendedClassParser
    {
        return $this->extendedClasses ??= new ExtendedClassParser(
            $this->state,
            $this->flags,
            $this->maxRecursionDepth,
            function (array $tokens, bool $counted): NodeInterface {
                $parser = new self($this->maxRecursionDepth, $this->target);
                if ($counted) {
                    $parser->capturesBefore = $this->captureCount;
                }

                return $parser->parse(new TokenStream($tokens, $this->pattern), $this->flags, '/', \strlen($this->pattern))->pattern;
            },
            function (int $at, int $parentheses): void {
                $this->guardRecursionDepth($at, $parentheses - 1);
            },
        );
    }

    /**
     * The reader of conditions, which calls this parser back for the
     * branches, the lookaround of a condition, a verb and "x" trivia, and
     * tells it the recursion tests it reads.
     */
    private function conditions(): ConditionParser
    {
        return $this->conditions ??= new ConditionParser(
            $this->state,
            $this->groupNames,
            $this->patternNames,
            $this->parseScopedAlternation(...),
            fn (int $start, bool $behind): ?GroupNode => $this->matchLookaround($start, $behind ? self::LOOKBEHINDS : self::LOOKAHEADS),
            $this->verbNode(...),
            $this->skipExtendedModeContent(...),
            function (string $name): void {
                $this->recursionTests[] = $name;
            },
        );
    }

    private function supports(PcreFeature $feature): bool
    {
        return $this->target->supports($feature);
    }

    /**
     * Whether "x" skips the character: PCRE skips Pattern_White_Space, which
     * in UTF mode also holds U+0085, U+200E, U+200F, U+2028 and U+2029, and
     * without it the byte 0x85.
     */
    private function isExtendedWhitespace(string $character): bool
    {
        if (Ascii::isSpace($character)) {
            return true;
        }

        return $this->unicodeMode
            ? \in_array($character, ["\u{85}", "\u{200e}", "\u{200f}", "\u{2028}", "\u{2029}"], true)
            : "\x85" === $character;
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

    /**
     * Reads the name of "(?&name)" or "(?P>name)" as PCRE2's read_name()
     * does: a digit first is refused, then the characters a name holds are
     * read, the name measured, an empty one refused, and, up to PCRE2 10.46,
     * the ")" that must close it looked for right after it. From 10.47 the
     * name is read alone: the caller looks for the groups it returns and the
     * ")".
     */
    private function parseSubroutineName(): string
    {
        $nameStart = $this->stream->current()->position;
        $nameEnd = $this->groupNames->invalidNameOffset($nameStart);

        if ($this->groupNames->startsWithDigit($nameStart)) {
            throw $this->parserException(
                \sprintf('Invalid subroutine name at position %d: a group name must not start with a digit.', $nameEnd),
                ErrorCode::GroupNameInvalid,
                $nameEnd,
            );
        }

        $tooLong = $this->groupNames->tooLongAt($nameStart, $nameEnd);
        if (null !== $tooLong) {
            throw $this->groupNames->tooLongError($nameStart, $nameEnd, $tooLong);
        }

        if ($nameEnd === $nameStart) {
            throw $this->parserException(
                \sprintf('Expected subroutine name at position %d.', $nameStart),
                ErrorCode::GroupNameExpected,
                $nameStart,
            );
        }

        if (!$this->supports(PcreFeature::CallsReturnCaptureGroups) && ')' !== ($this->pattern[$nameEnd] ?? '')) {
            throw $this->parserException(
                \sprintf('Missing ")" to close subroutine call: the name ends at position %d.', $nameEnd),
                ErrorCode::GroupNameUnterminated,
                $nameEnd,
            );
        }

        while (!$this->stream->isAtEnd() && $this->stream->current()->position < $nameEnd) {
            $this->stream->advance();
        }

        return substr($this->pattern, $nameStart, $nameEnd - $nameStart);
    }

    /**
     * What PCRE reports for a "(?" group it cannot read: the problem of the
     * construct the characters after "(?" start, where PCRE stops reading.
     *
     * @param int $start the offset of the "("
     */
    private function unreadableGroupError(int $start): ParserException
    {
        [$position, $code, $message] = $this->unreadableGroupFault($start);

        return $this->parserException(\sprintf($message, $position), $code, $position);
    }

    /**
     * Where PCRE reports a "(?" group it cannot read, by what follows the
     * "(?": it reads as far as the construct makes sense and stops there.
     *
     * @param int $start the offset of the "("
     *
     * @return array{0: int, 1: ErrorCode, 2: string} the offset, the code,
     *                                                and the message, whose
     *                                                "%d" is the offset
     */
    private function unreadableGroupFault(int $start): array
    {
        $pattern = $this->pattern;
        $length = \strlen($pattern);
        $position = $start + 2;
        $char = $pattern[$position] ?? '';
        $syntax = [ErrorCode::GroupSyntax, 'Invalid group modifier syntax at position %d.'];
        $unclosed = [ErrorCode::GroupUnclosed, 'Missing ")" to close the group at position %d.'];

        // Tokens with no pattern text behind them: nothing to read.
        if ($position > $length) {
            return [$length, ...$syntax];
        }

        // "(?[" is a Perl extended class, which PCRE2 reads from 10.45 only;
        // before, it refuses the "[".
        if ('[' === $char) {
            return [$position, ...$syntax];
        }

        // "(?R" calls the whole pattern, and a ")" has to follow.
        if ('R' === $char) {
            return [$position + 1, ErrorCode::SubroutineInvalidSyntax, '"(?R" must be followed by ")", at position %d.'];
        }

        // "(?1", "(?-1", "(?+1": a call, which ends after its number, and is
        // refused while its number is read when PCRE cannot take it.
        if (1 === LibraryPcre::match('/\G([+-]?)(\d++)/', $pattern, $matches, 0, $position)) {
            [$number, $sign, $digits] = $matches;
            $end = $position + \strlen($number);
            $tooBig = RepeatCount::tooBigOffset($digits, $position + \strlen($sign), $this->target)
                ?? ('+' === $sign && (int) $digits + $this->captureCount > PcreNumber::MAX ? $end : null);

            return match (true) {
                null !== $tooBig => [$tooBig, ErrorCode::GroupNumberTooBig, 'Group number '.$number.' is too big at position %d: PCRE takes at most 65535.'],
                '' !== $sign && 0 === (int) $digits => [$end, ErrorCode::SubroutineRelativeZero, 'Subroutine call relative reference cannot be zero, at position %d.'],
                '-' === $sign && (int) $digits > $this->captureCount => [$end, ErrorCode::SubroutineRelativeMissing, 'Subroutine call relative reference '.$number.' points before the first group, at position %d.'],
                default => [$end, ErrorCode::GroupUnclosed, 'Missing ")" to close the subroutine call at position %d.'],
            };
        }

        // "(?+" without a number: past the character after the "+", from
        // PCRE2 10.47; on the "+" before.
        if ('+' === $char) {
            $fault = $this->target->faultOffset(min($position + 2, $length), min($position + 2, $length) - $position);

            return $position + 1 >= $length
                ? [$fault, ...$unclosed]
                : [$fault, ErrorCode::SubroutineInvalidSyntax, 'A digit is expected after "(?+", at position %d.'];
        }

        if ('C' === $char) {
            [$fault, $code] = Callout::fault($this->pattern, $start, $this->target) ?? [$start, ErrorCode::GroupSyntax];

            return [$fault, $code, 'Invalid callout at position %d: '.str_replace('%', '%%', Callout::problem($code)).'.'];
        }

        // Option letters: PCRE stops past the first one it does not know,
        // past a "-" it cannot take, or at the end of the pattern.
        $letters = self::INLINE_FLAG_LETTERS.($this->supports(PcreFeature::CaselessRestrictModifier) ? 'r' : '');
        $hyphenAllowed = true;
        if ('^' === $char) {
            $hyphenAllowed = false;
            $position++;
        }

        while ($position < $length && ')' !== $pattern[$position] && ':' !== $pattern[$position]) {
            $char = $pattern[$position++];

            if ('-' === $char) {
                if (!$hyphenAllowed) {
                    return [$this->target->faultOffset($position), ErrorCode::GroupOptionHyphen, 'Invalid hyphen in option setting at position %d: modifiers are turned off once, after a single "-" that no "^" precedes.'];
                }

                $hyphenAllowed = false;

                continue;
            }

            // An ASCII option, "a", takes at most one class letter along.
            if ('a' === $char && $this->supports(PcreFeature::AsciiOptions)) {
                $position += (int) ($position < $length && str_contains('DSWPT', $pattern[$position]));

                continue;
            }

            if (!str_contains($letters, $char)) {
                return [$this->target->faultOffset($position), ...$syntax];
            }
        }

        return $position < $length ? [$start, ...$syntax] : [$length, ...$unclosed];
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
            throw $this->parserException(
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
     * A syntax error at $position, with the pattern around it.
     */
    private function parserException(string $message, ErrorCode $code, int $position): ParserException
    {
        return SyntaxErrorException::withContext($message, $code, $position, $this->pattern);
    }

    /**
     * @param int $deeper the levels the pattern is read at below the parser's own
     */
    private function guardRecursionDepth(int $position, int $deeper = 0): void
    {
        if ($this->recursionDepth + $deeper >= $this->maxRecursionDepth) {
            throw RecursionLimitException::withContext(
                \sprintf('Recursion limit of %d exceeded.', $this->maxRecursionDepth),
                ErrorCode::NestingTooDeep,
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
        $name = $this->groupNames->read(true, ++$this->captureCount, !$expectAngle);

        if ($expectAngle) {
            $this->stream->consumeLiteral('>', 'Expected > after group name', ErrorCode::GroupNameUnterminated);
        }

        $expr = $this->parseScopedAlternation();
        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);

        return $this->createGroupNode(
            $expr,
            GroupType::Named,
            $startPosition,
            $endToken,
            $name,
            null,
            $pythonSyntax,
        );
    }

    /**
     * Parses a simple group: alternation content followed by closing paren.
     * Used for non-capturing groups, lookaheads, atomic groups, etc.
     */
    private function parseSimpleGroup(int $startPosition, GroupType $type): GroupNode
    {
        $expr = $this->parseScopedAlternation();
        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);

        return $this->createGroupNode($expr, $type, $startPosition, $endToken);
    }
}
