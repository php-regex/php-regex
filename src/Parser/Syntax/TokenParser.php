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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RecursionLimitException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Exception\SyntaxErrorException;
use PHPRegex\Parser\Internal\Ascii;
use PHPRegex\Parser\Internal\CodePointReader;
use PHPRegex\Parser\Internal\ExtendedClassReader;
use PHPRegex\Parser\Internal\GroupNameReader;
use PHPRegex\Parser\Internal\InlineFlags;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Internal\PcreVerb;
use PHPRegex\Parser\Internal\VersionCondition;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ClassSetOperator;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Parser\Validation\Validator;

/**
 * Recursive descent parser for regex patterns.
 *
 * This parser uses intelligent caching, reduced method calls, and
 * streamlined parsing logic for efficiency while maintaining full
 * compatibility with PCRE syntax.
 *
 * @internal
 */
final class TokenParser
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
     * Token types that describe a character the same way wherever they appear.
     */
    private const ATOM_TYPES = [
        TokenType::Literal,
        TokenType::LiteralEscaped,
        TokenType::CharType,
        TokenType::UnicodeProp,
        TokenType::ControlChar,
        TokenType::Unicode,
        TokenType::UnicodeNamed,
        TokenType::Octal,
        TokenType::OctalLegacy,
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
        $this->maxRecursionDepth = $maxRecursionDepth ?? self::MAX_RECURSION_DEPTH;
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
        $this->groupNames = new GroupNameReader($stream);
        $this->groupNames->allowDuplicates(str_contains($flags, 'J'));
        $this->groupNames->reportPastTheFault($this->supports(PcreFeature::ErrorOffsetPastTheFault));
        // PCRE2 10.44 took names from 32 code units to 128; PHP bundles it
        // from 8.4.
        $this->groupNames->limitNameLength(
            $this->supports(PcreFeature::LongGroupNames)
                ? GroupNameReader::MAX_NAME_LENGTH
                : GroupNameReader::MAX_NAME_LENGTH_BEFORE_PCRE_1044,
        );
        // Group names take any letter in Unicode mode: "u", or "(*UTF)" at
        // the start.
        $this->unicodeMode = str_contains($flags, 'u')
            || 1 === LibraryPcre::match('/\A(?:\(\*[A-Z_]++(?:=\d++)?\))*?\(\*UTF8?\)/', $this->pattern);
        $this->groupNames->readUnicodeNames($this->unicodeMode);
        if (null !== $this->namesAround) {
            $this->groupNames->shareNamesWith($this->namesAround);
            $this->groupNames->allowDuplicates($this->namesAround->duplicatesAllowed());
        }

        $this->extendedMode = str_contains($flags, 'x');
        $this->extendedMoreMode = $this->extendedMoreBefore;
        $this->noAutoCapture = str_contains($flags, 'n');
        $this->inQuoteMode = false;
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
            $position = $this->stream->current()->position + ($this->supports(PcreFeature::ErrorOffsetPastTheFault) ? 1 : 0);

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

        while (!$this->stream->isAtEnd() && !$this->stream->check(TokenType::GroupClose) && !$this->stream->check(TokenType::Alternation)) {
            if ($this->stream->match(TokenType::QuoteModeStart)) {
                $this->inQuoteMode = true;

                continue;
            }
            if ($this->stream->match(TokenType::QuoteModeEnd)) {
                $this->inQuoteMode = false;

                continue;
            }

            // In extended (/x) mode, consume whitespace and line comments as
            // explicit nodes where appropriate so we can preserve them when
            // reconstructing the pattern.
            if ($this->consumeExtendedModeContent($nodes)) {
                continue;
            }

            if (!$this->quantifyPreviousItem($nodes)) {
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
        if (!$this->extendedMode || $this->inQuoteMode) {
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

        $comment = $this->sourceTextOf($startToken);
        $this->stream->advance();

        while (!$this->stream->isAtEnd()) {
            $token = $this->stream->current();

            // Comment ends at newline (included) or at end of pattern.
            if (TokenType::Literal === $token->type && "\n" === $token->value) {
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
            $position = $this->quantifierErrorOffset($token);

            throw $this->parserException(
                \sprintf('Quantifier without target at position %d', $position),
                ErrorCode::QuantifierNothingToRepeat,
                $position,
            );
        }

        // "a*(?#c)++": the first "+" is the modifier, the second repeats nothing.
        if (\strlen($token->value) > 1) {
            $position = $this->pastTheFault($token->position + \strlen($token->value));

            throw $this->parserException(
                \sprintf('Quantifier without target at position %d', $position),
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
            [$escape, $text] = $node->children;
            $last = $this->parseQuantifiedText($text);

            return $last === $text ? $node : new SequenceNode([$escape, $last], $node->startPosition, $last->getEndPosition());
        }

        // A quantifier after a comment repeats the item before the comment.
        if ($node instanceof CommentNode) {
            return $node;
        }

        $skipped = $this->skipExtendedModeContent();

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
        // its lazy/possessive modifier: "a* +" means "a*+" to PCRE.
        if (QuantifierType::Greedy === $type && $this->extendedMode && !$this->inQuoteMode) {
            $skippedModifier = $this->skipExtendedModeContent();
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
        $position = $this->quantifierErrorOffset($token);

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
                \sprintf('Quantifier without target at position %d', $position),
                ErrorCode::QuantifierNothingToRepeat,
                $position,
            );
        }

        // PCRE lets "(*ACCEPT)" be repeated, "(*ACCEPT)??" included: it wraps
        // it in a group. No other verb takes a quantifier. An alphabetic name
        // PCRE does not know, as "(*scs:" before PCRE2 10.45, is refused where
        // it ends, before its quantifier is read: the validator reports it.
        $isAccept = $node instanceof PcreVerbNode && 1 === LibraryPcre::match('/^ACCEPT(?::|$)/', $node->verb);
        $isUnknownName = $node instanceof PcreVerbNode && 1 === LibraryPcre::match('/^[a-z]/', $node->verb);

        if (!$isAccept && !$isUnknownName && $this->isAssertionNode($node)) {
            $this->guardQuantifierCount($token);
            $nodeName = $this->getAssertionNodeName($node);

            throw $this->parserException(
                \sprintf('Quantifier "%s" cannot be applied to assertion or verb "%s" at position %d',
                    $token->value, $nodeName, $position),
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

        if ($this->stream->match(TokenType::ExtendedClass)) {
            return $this->parseExtendedClass($token);
        }

        if ($this->stream->match(TokenType::CommentOpen)) {
            return $this->parseComment();
        }

        if ($this->stream->match(TokenType::Callout)) {
            return $this->parseCallout();
        }

        if ($this->stream->match(TokenType::QuoteModeStart)) {
            $this->inQuoteMode = true;

            return $this->parseAtom();
        }
        if ($this->stream->match(TokenType::QuoteModeEnd)) {
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

        if ($this->stream->check(TokenType::Quantifier)) {
            $position = $this->stream->current()->position;

            $this->guardQuantifierCount($this->stream->current());

            // "(*MARK:a" with no ")" is a verb PCRE reads to the end.
            $unclosedVerb = $this->startsUnclosedVerb($position);
            if ($unclosedVerb && '*' === $this->pattern[$position + 1]) {
                throw $this->doubleStarError($position + 1);
            }

            $namePosition = $position + 1;
            $position = $unclosedVerb
                ? $this->unclosedVerbOffset($position - 1)
                : $this->quantifierErrorOffset($this->stream->current());

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
                : $this->parserException(\sprintf('Quantifier without target at position %d', $position), ErrorCode::QuantifierNothingToRepeat, $position);
        }

        $val = $this->stream->current()->value;
        $type = $this->stream->current()->type->value;

        throw $this->parserException(
            \sprintf('Unexpected token "%s" (%s) at position %d.', $val, $type, $startPosition),
            ErrorCode::TokenUnexpected,
            $startPosition,
        );
    }

    private function parseSimpleAtom(int $startPosition): ?NodeInterface
    {
        $this->guardNamedReferenceEscape();

        if (null !== $atom = $this->matchAtom($startPosition, self::OUTSIDE_ATOM_TYPES)) {
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
        if (TokenType::LiteralEscaped !== $token->type || 'k' !== $token->value || $this->inQuoteMode) {
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
        $nameEnd = $this->groupNames->invalidNameOffset($nameStart);
        $digit = $this->unicodeMode ? '/\G\p{Nd}/u' : '/\G[0-9]/';

        if (1 === LibraryPcre::match($digit, $this->pattern, $matches, 0, $nameStart)) {
            throw $this->parserException(
                \sprintf('Group name after \k%s must not start with a digit at position %d.', $opener, $nameEnd),
                ErrorCode::GroupNameInvalid,
                $nameEnd,
            );
        }

        if ($nameEnd === $nameStart) {
            throw $this->parserException(
                \sprintf('Group name expected after \k%s at position %d.', $opener, $nameStart),
                ErrorCode::GroupNameExpected,
                $nameStart,
            );
        }

        if ($closer !== ($this->pattern[$nameEnd] ?? '')) {
            throw $this->parserException(
                \sprintf('Missing "%s" to close the group name after \k%s at position %d.', $closer, $opener, $nameEnd),
                ErrorCode::GroupNameUnterminated,
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
        if (TokenType::Backref === $type) {
            $octal = $this->digitsFromUnreadReference($token, $startPosition)
                ?? $this->octalEscapeFromReference($token, $startPosition);
            if (null !== $octal) {
                return $octal;
            }

            $this->guardReferenceNameLength($token->value, $token->position);
        }

        return match ($type) {
            // Between "\Q" and "\E" a backslash is text: "\Q\x\E" is "\x".
            TokenType::Literal => new LiteralNode($token->value, $startPosition, $token->end()),
            // "\x" with no digit is NUL where PCRE takes it (up to 10.44).
            TokenType::LiteralEscaped => '\\x' === substr($this->pattern, $token->position, 2) && 2 === $token->end() - $token->position
                && '{' !== ($this->pattern[$token->end()] ?? '')
                ? new CharLiteralNode('\\x', 0, CharLiteralType::Unicode, $startPosition, $token->end())
                : new LiteralNode($token->value, $startPosition, $token->end()),
            TokenType::CharType => new CharTypeNode($token->value, $startPosition, $token->end()),
            TokenType::Anchor => new AnchorNode($token->value, $startPosition, $token->end()),
            TokenType::Assertion => new AssertionNode($token->value, $startPosition, $token->end()),
            TokenType::Backref => new BackrefNode(self::withoutBracePadding($token->value), $startPosition, $token->end()),
            TokenType::ControlChar => new ControlCharNode(
                $token->value,
                CodePointReader::fromControlChar($token->value),
                $startPosition,
                $token->end(),
            ),
            TokenType::UnicodeProp => new UnicodePropNode(
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
        if ($this->stream->match(TokenType::GroupOpen)) {
            $startToken = $this->stream->previous();

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

        if ($this->stream->match(TokenType::GroupModifierOpen)) {
            return $this->parseGroupModifier();
        }

        if ($this->stream->match(TokenType::CharClassOpen)) {
            return $this->parseWordBoundaryClass() ?? $this->parseCharClass();
        }

        return null;
    }

    /**
     * "[[:<:]]" and "[[:>:]]", exactly, are no classes: PCRE2 reads them as
     * the start and the end of a word, "\b(?=\w)" and "\b(?<=\w)".
     */
    private function parseWordBoundaryClass(): ?GroupNode
    {
        $start = $this->stream->previous()->position;
        $text = substr($this->pattern, $start, 7);
        if ('[[:<:]]' !== $text && '[[:>:]]' !== $text) {
            return null;
        }

        $end = $start + 7;
        while (!$this->stream->isAtEnd() && $this->stream->current()->position < $end) {
            $this->stream->advance();
        }

        // Each node starts inside the one holding it, as written groups do.
        $side = new GroupNode(
            new CharTypeNode('w', $start + 2, $end - 1),
            '<' === $text[3] ? GroupType::LookaheadPositive : GroupType::LookbehindPositive,
            null,
            null,
            $start + 1,
            $end - 1,
        );

        return new GroupNode(
            new SequenceNode([new AssertionNode('b', $start + 1, $start + 1), $side], $start + 1, $end - 1),
            GroupType::NonCapturing,
            null,
            null,
            $start,
            $end,
        );
    }

    private function parseVerbAtom(int $startPosition): ?NodeInterface
    {
        if (!$this->stream->match(TokenType::PcreVerb)) {
            return null;
        }

        return $this->verbNode($this->stream->previous(), $startPosition);
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
            throw $this->doubleStarError($token->position + 2);
        }

        if ($token->end() - $token->position !== \strlen($token->value) + 2) {
            return $this->createPcreVerbNode($token->value, $startPosition, $startPosition + \strlen($token->value) + self::PCRE_VERB_WRAPPER_LENGTH);
        }

        $read = PcreVerb::read($token->value);
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
        $token = $this->stream->previous();
        $startPosition = $token->position;
        $value = $token->value;
        $endPosition = $startPosition + \strlen($token->value) + self::CALLOUT_WRAPPER_LENGTH;

        if ('' === $value) {
            return new CalloutNode(null, false, $startPosition, $endPosition);
        }

        if (Ascii::isDigit($value)) {
            return new CalloutNode((int) $value, false, $startPosition, $endPosition);
        }

        $closing = self::CALLOUT_STRING_DELIMITERS[$value[0]] ?? null;
        if (null === $closing) {
            // "(?Cab)": a callout takes a number or a delimited string;
            // "(?C1x)": the number is read, and ")" is due after it.
            $code = ErrorCode::CalloutInvalidDelimiter;
            if (1 === LibraryPcre::match('/^\d++/', $value, $number)) {
                $code = \strlen(ltrim($number[0], '0')) > 3 || (int) $number[0] > 255 ? ErrorCode::CalloutOutOfRange : ErrorCode::CalloutUnclosed;
            }
            $position = $this->calloutFault($startPosition)[0] ?? $startPosition + 4;

            throw $this->parserException(
                \sprintf('Invalid callout argument: %s at position %d: %s.', $value, $position, self::calloutProblem($code)),
                $code,
                $position,
            );
        }

        // A doubled closing delimiter stands for itself: "(?C{a}}b})".
        $quoted = preg_quote($closing, '/');
        if (1 !== LibraryPcre::match('/^.((?:[^'.$quoted.']|'.$quoted.$quoted.')*+)'.$quoted.'$/s', $value, $matches)) {
            // "(?C"a"b)": the string is closed, and ")" is due after it.
            $closed = 1 === LibraryPcre::match('/^.(?:[^'.$quoted.']|'.$quoted.$quoted.')*+'.$quoted.'/s', $value);
            $code = $closed ? ErrorCode::CalloutUnclosed : ErrorCode::CalloutUnclosedString;
            $position = $this->calloutFault($startPosition)[0] ?? $startPosition;

            throw $this->parserException(
                \sprintf('Invalid callout argument: %s at position %d: %s.', $value, $position, self::calloutProblem($code)),
                $code,
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
        $this->guardReferenceNameLength($token->value, $token->position);

        // \g{N} or \gN (numeric, incl. relative) -> Backreference; \g'N',
        // like \g<N>, calls the group instead.
        if (LibraryPcre::match('/^\\\\g(?:\{([0-9+-]++)\}|([0-9+-]++))$/', $value, $m)) {
            return new BackrefNode($value, $startPosition, $endPosition);
        }

        // \g{name} is a back reference, like \k{name}; it is recorded that
        // way, and the compiler gives back the spelling the pattern used.
        if (LibraryPcre::match('/^\\\\g\{([\p{L}\p{Nd}_]++)\}$/u', $value, $m)) {
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

        // \g<name> and \g'name' (non-numeric) call the group -> Subroutine
        if (LibraryPcre::match('/^\\\\g<([+-]?[\p{L}\p{Nd}_]++)>$/u', $value, $m)) {
            return new SubroutineNode($m[1], 'g', $startPosition, $endPosition);
        }

        if (LibraryPcre::match('/^\\\\g\'([+-]?[\p{L}\p{Nd}_]++)\'$/u', $value, $m)) {
            return new SubroutineNode($m[1], 'g', $startPosition, $endPosition);
        }

        $position = $this->gReferenceErrorOffset($token->position);
        $code = $this->gReferenceErrorCode($token->position, $position);

        throw $this->parserException(
            \sprintf('Invalid \\g reference syntax: %s at position %d%s', $value, $position, match ($code) {
                ErrorCode::GroupNameExpected => ': a group name or number is expected.',
                ErrorCode::GroupNameUnterminated => ': nothing closes the group name there.',
                default => '',
            }),
            $code,
            $position,
        );
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

        $nameStart = $start + 3 + ('{' === $opener ? strspn($this->pattern, " \t", $start + 3) : 0);

        return match (true) {
            1 === LibraryPcre::match('/\G[+-]?\d/', $this->pattern, $matches, 0, $nameStart) => ErrorCode::BackrefInvalidSyntax,
            $position === $nameStart => ErrorCode::GroupNameExpected,
            default => ErrorCode::GroupNameUnterminated,
        };
    }

    /**
     * PCRE reads the name of "\k<name>", "\g{name}" and the like before it
     * looks the group up, and stops where a name past the limit ends.
     *
     * @param int $start the offset of the backslash
     */
    private function guardReferenceNameLength(string $reference, int $start): void
    {
        if (1 === LibraryPcre::match('/^\\\\[gk][<{\'][ \t]*+([^\d+\- \t>}\'][^ \t>}\']*+)/', $reference, $matches, \PREG_OFFSET_CAPTURE)) {
            $this->guardNameLength($matches[1][0], $start + $matches[1][1]);
        }
    }

    /**
     * @param int $nameStart the offset of the name's first character
     */
    private function guardNameLength(string $name, int $nameStart): void
    {
        if (\strlen($name) > $this->groupNames->maxNameLength()) {
            throw $this->parserException(
                \sprintf('Group name is too long: %d code units, PCRE allows at most %d.', \strlen($name), $this->groupNames->maxNameLength()),
                ErrorCode::GroupNameTooLong,
                $nameStart + \strlen($name),
            );
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

        // Only "\g{...}" takes spaces around what it holds.
        $blanks = '}' === $closing ? " \t" : '';
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
            && !$this->stream->check(TokenType::GroupClose)
        ) {
            $token = $this->stream->current();
            $comment .= $this->sourceTextOf($token);
            $this->stream->advance();
        }

        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected ) to close comment', ErrorCode::CommentUnclosed);
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

    /**
     * "\NN" of 10 or more that starts with 1 to 7, past the groups opened so
     * far, is no reference: PCRE reads an octal escape of up to three octal
     * digits, and the digits left as text, "\1000" being "@" then "0".
     */
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

    private function createCharLiteralNodeFromToken(Token $token, TokenType $type, int $startPosition): CharLiteralNode
    {
        [$representation, $charType] = match ($type) {
            TokenType::Unicode => [$token->value, CharLiteralType::Unicode],
            TokenType::UnicodeNamed => ['\\N{'.$token->value.'}', CharLiteralType::UnicodeNamed],
            TokenType::Octal => [$token->value, CharLiteralType::Octal],
            TokenType::OctalLegacy => ['\\'.$token->value, CharLiteralType::OctalLegacy],
            default => throw new \LogicException('Unsupported character literal token type.'),
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
        if ($this->stream->match(TokenType::Callout)) {
            return $this->parseCalloutConditional($startPosition);
        }

        // 2.1 "(?(" followed by "(*...)" is a conditional whose condition is
        // spelled as a verb: "(?(*pla:a)yes|no)".
        if ($this->stream->match(TokenType::PcreVerb)) {
            return $this->parseVerbConditional($startPosition, $this->stream->previous());
        }

        // 2.2 "(?(?[a])b)": an extended class is no assertion; PCRE refuses
        // it past the "(", on it before PCRE2 10.47.
        if ($this->stream->check(TokenType::ExtendedClass) && $this->stream->current()->position === $startPosition + 2) {
            $position = $this->pastTheFault($this->stream->current()->position + 1);

            throw $this->parserException(
                \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after "(?(".', $position),
                ErrorCode::ConditionAssertionExpected,
                $position,
            );
        }

        // 2.3 "(?(?#c)(?=a)b)": a comment where the condition starts is
        // skipped, and the assertion is due after it.
        if ($this->stream->check(TokenType::CommentOpen) && $this->stream->current()->position === $startPosition + 2) {
            return $this->parseCommentedConditional($startPosition);
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
     * Where PCRE refuses what follows the callout of a condition instead of
     * an assertion: where it starts, from PCRE2 10.47; before, a character
     * read as is on its last byte, one in "\Q...\E" included.
     */
    private function calloutConditionErrorOffset(): int
    {
        return $this->supports(PcreFeature::CalloutConditionErrorAtItemStart)
            ? $this->stream->current()->position
            : $this->conditionFaultOnItsLastByte();
    }

    /**
     * Where PCRE refuses what follows a comment where the assertion of a
     * condition is due: past a character read as is, past the "(" or "\"
     * that starts anything else, and on a "\Q...\E" that holds text, from
     * PCRE2 10.47; before, as after a callout.
     */
    private function commentConditionErrorOffset(): int
    {
        if (!$this->supports(PcreFeature::ErrorOffsetPastTheFault)) {
            return $this->conditionFaultOnItsLastByte();
        }

        $token = $this->stream->current();

        return match ($token->type) {
            TokenType::QuoteModeStart => $token->position,
            TokenType::Literal => $token->position + \strlen($this->firstCharacterOf($token)),
            default => $token->position + 1,
        };
    }

    /**
     * Before PCRE2 10.47, PCRE refuses what stands where the assertion of a
     * condition is due on its first character, and a character read as is
     * on its last byte, one in "\Q...\E" included.
     */
    private function conditionFaultOnItsLastByte(): int
    {
        $token = $this->stream->current();
        if (TokenType::QuoteModeStart === $token->type && TokenType::Literal === $this->stream->peek()->type) {
            $token = $this->stream->peek();
        }

        if (TokenType::Literal !== $token->type) {
            return $token->position;
        }

        return $token->position + \strlen($this->firstCharacterOf($token)) - 1;
    }

    private function firstCharacterOf(Token $token): string
    {
        return $this->unicodeMode && 1 === LibraryPcre::match('/^./su', $token->value, $matches) ? $matches[0] : $token->value[0];
    }

    /**
     * Parses "(?(?#c)(?=a)yes|no)": PCRE skips the comment, as it skips "x"
     * whitespace and an empty "\Q\E" after it, and wants the assertion
     * next, a callout before it allowed. The comment is not kept.
     */
    private function parseCommentedConditional(int $startPosition): NodeInterface
    {
        do {
            $this->skipEmptyQuotes();
            $skipped = $this->skipExtendedModeContent();
            if ($this->stream->match(TokenType::CommentOpen)) {
                $this->parseComment();
                $skipped++;
            }
        } while ($skipped > 0);

        if ($this->stream->match(TokenType::Callout)) {
            return $this->parseCalloutConditional($startPosition);
        }

        if ($this->stream->match(TokenType::PcreVerb)) {
            return $this->parseVerbConditional($startPosition, $this->stream->previous());
        }

        $this->guardConditionCutShort();
        $position = $this->commentConditionErrorOffset();
        if ($this->opensUnclosedVerb()) {
            throw $this->unclosedVerbConditionError($this->stream->current()->position, $position);
        }

        if ($this->stream->match(TokenType::GroupModifierOpen)) {
            $groupStart = $this->stream->previous()->position;
            $this->guardCutShortAssertion($groupStart, $position);

            return $this->parseConditionalBranches($startPosition, $this->parseLookaroundCondition($groupStart));
        }

        throw $this->parserException(
            \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after "(?(".', $position),
            ErrorCode::ConditionAssertionExpected,
            $position,
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

        // PCRE skips a comment, an empty "\Q\E" and "x" whitespace there.
        do {
            $this->skipEmptyQuotes();
            $skipped = $this->skipExtendedModeContent();
            if ($this->stream->match(TokenType::CommentOpen)) {
                $this->parseComment();
                $skipped++;
            }
        } while ($skipped > 0);

        $this->guardConditionCutShort();

        // "(?(?C1)(*pla:a)b)": the assertion spelled as a verb, refused as
        // anything else there is when it is no lookaround.
        if ($this->stream->check(TokenType::PcreVerb)) {
            $verbToken = $this->stream->current();
            $this->guardVerbCondition($verbToken, $this->calloutConditionErrorOffset());
            $this->stream->advance();
            $assertion = $this->verbNode($verbToken, $verbToken->position);
        } elseif ($this->opensUnclosedVerb()) {
            throw $this->unclosedVerbConditionError($this->stream->current()->position, $this->calloutConditionErrorOffset());
        } elseif (!$this->stream->match(TokenType::GroupModifierOpen)) {
            $position = $this->calloutConditionErrorOffset();

            throw $this->parserException(
                \sprintf('Invalid conditional condition at position %d: a callout in a condition must be followed by an assertion.', $position),
                ErrorCode::ConditionAssertionExpected,
                $position,
            );
        } else {
            // A group that is no assertion is refused where it starts, as
            // anything else there is.
            $groupStart = $this->stream->previous()->position;
            $this->guardCutShortAssertion($groupStart, $groupStart);
            $assertion = $this->parseLookaroundCondition($groupStart, $groupStart);
        }

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
        // PCRE stops at the "*", past it from PCRE2 10.47.
        $this->guardVerbCondition($verbToken, $this->pastTheFault($verbToken->position + 1));

        return $this->parseConditionalBranches($startPosition, $this->verbNode($verbToken, $verbToken->position));
    }

    /**
     * Refuses the verb token where the assertion of a condition belongs,
     * unless it is a lookaround: PCRE refuses a verb, an atomic group or a
     * script run there, at the colon of a named one, at $unnamedAt for any
     * other.
     */
    private function guardVerbCondition(Token $verbToken, int $unnamedAt): void
    {
        $read = PcreVerb::read($verbToken->value);
        if (null !== $read->assertion && GroupType::Atomic !== $read->assertion && !$read->nonAtomic) {
            return;
        }

        $alphaError = $this->alphaNameConditionError($verbToken->position + 2);
        if (null !== $alphaError) {
            throw $alphaError;
        }

        $position = 1 === LibraryPcre::match('/^[a-z_]++(?=:)/', $verbToken->value, $name)
            ? $verbToken->position + 2 + \strlen($name[0])
            : $unnamedAt;

        throw $this->parserException(
            \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after "(?(".', $position),
            ErrorCode::ConditionAssertionExpected,
            $position,
        );
    }

    /**
     * Refuses a condition the pattern ends in before its assertion: after
     * "(?(?C1)" or "(?(?#c)", and what PCRE skips after them, it misses the
     * ")" of the conditional.
     */
    private function guardConditionCutShort(): void
    {
        if (!$this->stream->isAtEnd()) {
            return;
        }

        $position = $this->stream->current()->position;

        throw $this->parserException(\sprintf('Missing ")" to close the conditional at position %d.', $position), ErrorCode::GroupUnclosed, $position);
    }

    /**
     * Refuses the assertion of a condition opened at $open, its "(", that
     * the pattern ends too soon after: PCRE reads one there only with at
     * least three characters after the "(", and refuses "(?=" or "(*p" at
     * the end at $faultAt, as anything else that is no assertion.
     */
    private function guardCutShortAssertion(int $open, int $faultAt): void
    {
        if (\strlen($this->pattern) - $open > 3) {
            return;
        }

        throw $this->parserException(
            \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after "(?(".', $faultAt),
            ErrorCode::ConditionAssertionExpected,
            $faultAt,
        );
    }

    /**
     * Whether the current token is the "(" of a "(*" the lexer read as no
     * verb, for want of a ")" after it.
     */
    private function opensUnclosedVerb(): bool
    {
        $token = $this->stream->current();

        return TokenType::GroupOpen === $token->type && '*' === ($this->pattern[$token->position + 1] ?? '');
    }

    /**
     * The error of a "(*" opened at $open, which no ")" closes, where the
     * assertion of a condition is due: refused as an assertion cut short,
     * then as an alphabetic name PCRE refuses there, else as no assertion,
     * at $unnamedAt.
     */
    private function unclosedVerbConditionError(int $open, int $unnamedAt): ParserException
    {
        $this->guardCutShortAssertion($open, $unnamedAt);

        return $this->alphaNameConditionError($open + 2) ?? $this->parserException(
            \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after "(?(".', $unnamedAt),
            ErrorCode::ConditionAssertionExpected,
            $unnamedAt,
        );
    }

    /**
     * What PCRE refuses in an alphabetic name, one that starts with a
     * lowercase letter, written at $nameStart where the assertion of a
     * condition belongs: a name it does not know, where the name ends; a
     * name no ":" follows, past the character after it from PCRE2 10.47 and
     * where the name ends before; a name the pattern ends in, as a group
     * left open from 10.47. Null for a name PCRE knows followed by ":", or
     * for no such name there.
     */
    private function alphaNameConditionError(int $nameStart): ?ParserException
    {
        if (1 !== LibraryPcre::match('/\G[a-z][A-Za-z0-9_]*+/', $this->pattern, $name, 0, $nameStart)) {
            return null;
        }

        $nameEnd = $nameStart + \strlen($name[0]);
        if (':' === ($this->pattern[$nameEnd] ?? '')) {
            // Before PCRE2 10.45, a substring scan is a name PCRE does not know.
            $scan = \in_array($name[0], ['scs', 'scan_substring'], true) && $this->supports(PcreFeature::ScanSubstring);

            return PcreVerb::takesArgument($name[0]) || $scan
                ? null
                : $this->parserException(\sprintf('Unknown alphabetic assertion "(*%s:" at position %d.', $name[0], $nameEnd), ErrorCode::VerbInvalid, $nameEnd);
        }

        if ($nameEnd >= \strlen($this->pattern) && $this->supports(PcreFeature::AlphaNameAtPatternEndIsUnclosed)) {
            return $this->parserException(\sprintf('Missing ")" to close "(*%s" at position %d.', $name[0], $nameEnd), ErrorCode::GroupUnclosed, $nameEnd);
        }

        $position = $nameEnd >= \strlen($this->pattern) ? $nameEnd : $this->pastTheFault($nameEnd + 1);

        return $this->parserException(\sprintf('Unknown alphabetic assertion "(*%s" at position %d: a ":" is expected after the name.', $name[0], $position), ErrorCode::VerbInvalid, $position);
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
        $flags = str_replace('x', '', $this->flags).($this->extendedMode ? 'x' : '');
        $flags = $this->noAutoCapture && !str_contains($flags, 'n') ? $flags.'n' : $flags;

        // The payload is read for the same target as the pattern around it,
        // its tokens moved to where they stand in the whole pattern.
        $this->partLexer ??= new Lexer($this->target);

        try {
            $stream = $this->partLexer->tokenizePart($this->pattern, $absoluteOffset, \strlen($payload), $flags, $this->extendedMoreMode);
        } catch (LexerException $error) {
            // The lexer around it only found where the body ends: an escape
            // it refuses in the body, as "\x{}", is met here, where the body
            // is read, and placed in the whole pattern.
            throw $this->movedError($error, $absoluteOffset);
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
     * "(?[ \p{L} - [aeiou] ])", PCRE2 10.45: the expression is read from the
     * text; each nested class and escape is read apart, a class under "xx"
     * as PCRE reads it there.
     */
    private function parseExtendedClass(Token $token): ExtendedCharClassNode
    {
        $reader = new ExtendedClassReader(
            $this->pattern,
            // An escape is read as a class reads it: "\b" is a backspace, "\1" an octal escape.
            function (string $escape, int $at): NodeInterface {
                // "\p{" never closed is refused where the pattern ends.
                if (1 === LibraryPcre::match('/^\\\\[pP]\{[^}]*+$/', $escape)) {
                    $end = \strlen($this->pattern);

                    throw $this->parserException(\sprintf('Malformed \\%s sequence: the braced name never closes at position %d.', $escape[1], $end), ErrorCode::UnicodePropertyMalformed, $end);
                }

                // A backslash ends the pattern: PCRE refuses it there.
                if ('\\' === $escape) {
                    $end = \strlen($this->pattern);

                    throw $this->parserException(\sprintf('A backslash ends the pattern at position %d.', $end), ErrorCode::EscapeTrailingBackslash, $end);
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
                    throw $this->parserException(\sprintf('Unknown POSIX class "%s" at position %d.', $class[3], $at + 6), ErrorCode::PosixInvalid, $at + 6);
                }

                return $this->parseOperandAt($class, $at, true);
            },
            fn (LexerException|ParserException $reason, int $at, array $operands): never => throw $this->firstOperandError($operands, $token->position) ?? $reason,
            $this->supports(PcreFeature::ErrorOffsetPastTheFault),
            $this->unicodeMode || str_contains($this->flags, 'u'),
            $this->maxRecursionDepth,
            // Parentheses nest as groups do, against the same limit.
            function (int $at, int $parentheses): void {
                $this->guardRecursionDepth($at, $parentheses - 1);
            },
        );

        // The lexer's token ends where the reader does: past the "])".
        [$expression, $end] = $reader->read($token->position);

        return new ExtendedCharClassNode($expression, $token->position, $end, substr($this->pattern, $token->position, $end - $token->position));
    }

    /**
     * An error in text read apart, which counts positions from its own start,
     * reported where it stands in the whole pattern.
     */
    private function movedError(LexerException|ParserException $error, int $offset): LexerException|ParserException
    {
        return $error::withContext($error->getMessage(), $error->getErrorCode(), (int) $error->getPosition() + $offset, $this->pattern, $error);
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

        $flags = $this->unicodeMode && !str_contains($this->flags, 'u') ? $this->flags.'u' : $this->flags;
        $end = \strlen($this->pattern);
        $tree = new RegexNode(new ExtendedCharClassNode($expression, $start, $end), $flags, '/', 0, $end, $this->pattern);

        try {
            $tree->accept(new Validator(pattern: $this->pattern, target: $this->target));
        } catch (RegexException $error) {
            // A parse error, which keeps the judgement it wraps.
            return ParserException::withContext($error->getMessage(), $error->getErrorCode(), $error->getPosition() ?? $start, $this->pattern, $error);
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
            $moved = $this->movedError($error, $at);

            throw $this->firstMemberErrorBefore($this->movedTokens($lexer->tokensRead(), $at), $moved->getPosition() ?? $at) ?? $moved;
        }

        $tokens = $this->movedTokens($read, $at, $class);

        $parser = new self($this->maxRecursionDepth, $this->target);
        $parser->capturesBefore = $this->captureCount;

        try {
            return $parser->parse(new TokenStream($tokens, $this->pattern), $this->flags, '/', \strlen($this->pattern))->pattern;
        } catch (ParserException $error) {
            throw ($class ? $this->firstMemberErrorBefore($tokens, $error->getPosition() ?? $at) : null) ?? $error;
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
     * A member of a class PCRE refuses before $position, where reading the
     * class failed: PCRE judges each member as it reads it.
     *
     * @param list<Token> $tokens
     */
    private function firstMemberErrorBefore(array $tokens, int $position): ?ParserException
    {
        foreach ($tokens as $token) {
            if ($token->end() > $position || \in_array($token->type, [TokenType::CharClassOpen, TokenType::CharClassClose, TokenType::Range, TokenType::Eof], true)) {
                continue;
            }

            $member = [
                new Token(TokenType::CharClassOpen, '[', $token->position),
                $token,
                new Token(TokenType::CharClassClose, ']', $token->end()),
                new Token(TokenType::Eof, '', $token->end()),
            ];

            try {
                $class = (new self($this->maxRecursionDepth, $this->target))->parse(new TokenStream($member, $this->pattern), $this->flags, '/', \strlen($this->pattern))->pattern;
            } catch (ParserException) {
                continue;
            }

            // A member refused where the fault is was read first.
            $error = $this->firstOperandError([$class], $token->position);
            if (null !== $error && ($error->getPosition() ?? $position) <= $position) {
                return $error;
            }
        }

        return null;
    }

    /**
     * "(*scs:(1,<name>)body)": the list of groups, then the body, read apart
     * like the body of any alphabetic assertion.
     */
    private function createScanSubstringNode(string $name, int $startPosition, int $endPosition): GroupNode
    {
        $listOpen = $startPosition + 2 + \strlen($name) + 1;
        if ('(' !== ($this->pattern[$listOpen] ?? '')) {
            throw $this->parserException(\sprintf('Missing "(" to open the groups of (*%s: at position %d.', $name, $listOpen), ErrorCode::ScanSubstringMissingList, $listOpen);
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
            $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected ) to close subroutine call', ErrorCode::GroupUnclosed);

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
        $position = $this->pastTheFault($pPos + 1 + $length, $length);

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
        // with it repeats nothing.
        if ($this->stream->check(TokenType::Quantifier) && \in_array($this->stream->current()->value, ['*+', '*?'], true)) {
            $token = $this->stream->current();
            $position = $this->pastTheFault($token->position + \strlen($token->value));

            throw $this->parserException(
                \sprintf('Quantifier without target at position %d', $position),
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

        if ($this->isLiteralDigitToken()) {
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
        if (!$this->supports(PcreFeature::CallsReturnCaptureGroups) || !$this->stream->check(TokenType::GroupOpen)) {
            return null;
        }

        [$groups, $at] = $this->readGroupList($this->stream->current()->position + 1);

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
        $fault = PcreVerb::groupListFault($this->pattern, $at - 1, $this->supports(PcreFeature::ErrorOffsetPastTheFault), $this->unicodeMode);

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
                    \strlen(ltrim($digits, '0')) > 5 || (int) $digits > 65535 => ['is too big', ErrorCode::GroupNumberTooBig],
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
        return $this->parserException($fault[2], $fault[1], $fault[0]);
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
        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected ) to close subroutine call', ErrorCode::GroupUnclosed);

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
            $position = $this->pastTheFault($startPosition + 2 + (int) strpos($flags, '-') + 1);

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
     * Parses conditional constructs (?(condition)...).
     */
    private function parseConditional(int $startPosition, bool $isModifier): ConditionalNode|DefineNode
    {
        if ($isModifier) {
            // Inline Lookaround condition
            $conditionStartPos = $this->stream->previous()->position;
            $this->guardCutShortAssertion($conditionStartPos, $this->pastTheFault($conditionStartPos + 1));

            // "(?(?C1" that no ")" closes, the "(?" read alone: the callout
            // is refused as anywhere else.
            if ($this->stream->checkLiteral('C')) {
                [$fault, $code] = $this->calloutFault($conditionStartPos) ?? [\strlen($this->pattern), ErrorCode::CalloutUnclosed];

                throw $this->parserException(\sprintf('Invalid callout at position %d: %s.', $fault, self::calloutProblem($code)), $code, $fault);
            }

            $condition = $this->parseLookaroundCondition($conditionStartPos);
        } else {
            $condition = $this->parseConditionalCondition();

            // "(?(VERSION=10.4": PCRE reads the ")" as part of the version.
            if ($condition instanceof VersionConditionNode && !$this->stream->check(TokenType::GroupClose)) {
                throw $this->versionConditionError($this->versionConditionErrorOffset($condition->startPosition) ?? $this->stream->current()->position, $condition->startPosition);
            }

            $this->stream->consume(TokenType::GroupClose, 'Expected ) after condition', ErrorCode::ConditionUnclosed);
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
            $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);
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

        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);
        $endPosition = $endToken->position + 1;

        return new ConditionalNode($condition, $yesBranch, $no, $startPosition, $endPosition);
    }

    /**
     * Parses lookaround conditions inside conditional constructs (?(?=...)...).
     *
     * @param int|null $faultPosition where a group that is no lookaround is
     *                                refused, past its "?" when null
     */
    private function parseLookaroundCondition(int $startPosition, ?int $faultPosition = null): NodeInterface
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

        // Past the "?" from PCRE2 10.47, on it before.
        $position = $faultPosition ?? $this->pastTheFault($startPosition + 1);

        throw $this->parserException(
            'Invalid conditional condition at position '.$position,
            ErrorCode::ConditionAssertionExpected,
            $position,
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

        if ('DEFINE' === $word && $this->stream->check(TokenType::GroupClose)) {
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
            && ($this->stream->check(TokenType::Literal) || $this->stream->check(TokenType::Dot))
        ) {
            $word .= $this->stream->current()->value;
            $this->stream->advance();
        }

        // A space before "VERSION" stands where PCRE wants a name.
        $condition = str_starts_with($word, 'VERSION') ? VersionCondition::read($word) : null;
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
            || ($this->stream->check(TokenType::Quantifier) && '+' === $this->stream->current()->value)) {
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
            static fn (string $c): bool => Ascii::isDigit($c),
        ));

        // PCRE refuses a number past 65535 as it reads it, before the ")".
        $position = $this->numberTooBigOffset($num, $startPosition + \strlen($sign));
        if (null !== $position) {
            throw $this->parserException(\sprintf('Group number %s%s is too big at position %d: PCRE takes at most 65535.', $sign, $num, $position), ErrorCode::GroupNumberTooBig, $position);
        }

        // "(?(+n)" counts the groups before it too: past 65535 in all, PCRE
        // refuses it where the digits end.
        if ('+' === $sign && (int) $num + $this->captureCount > 65535) {
            $position = $this->stream->current()->position;

            throw $this->parserException(\sprintf('Group number %s%s is too big at position %d: with the groups before it, it goes past 65535.', $sign, $num, $position), ErrorCode::GroupNumberTooBig, $position);
        }

        // "(?(-n)" counts back over the groups opened so far: PCRE refuses a
        // count past them as it reads it, before any later error.
        if ('-' === $sign && (int) $num > $this->captureCount) {
            $position = $this->stream->current()->position;

            throw $this->parserException(\sprintf('Condition relative reference -%s is outside the range of available capture groups.', $num), ErrorCode::BackrefRelative, $position);
        }

        return new BackrefNode($sign.$num, $startPosition, $this->stream->current()->position);
    }

    /**
     * Parses a named condition in a conditional construct.
     */
    private function parseNamedCondition(int $startPosition): ?BackrefNode
    {
        // "(?('name')...)": the reader takes the quotes along with the name.
        if ($this->stream->checkLiteral("'")) {
            $name = $this->groupNames->read(false, null, true);

            return new BackrefNode($name, $startPosition, $this->stream->current()->position);
        }

        if (!$this->stream->matchLiteral('<')) {
            return null;
        }

        $name = $this->groupNames->read(false);
        $this->stream->consumeLiteral('>', 'Expected > after condition name', ErrorCode::GroupNameUnterminated);

        return new BackrefNode($name, $startPosition, $this->stream->current()->position);
    }

    /**
     * Parses a subroutine R condition in a conditional construct: "(?(R)",
     * "(?(R2)" and "(?(R&name)" test a recursion, unless a group has the
     * name "R" or "R2", which PCRE2 looks up first: then it tests that group.
     */
    private function parseSubroutineRCondition(int $startPosition): SubroutineNode|BackrefNode|null
    {
        $savedPos = $this->stream->getPosition();
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

        // Anything else PCRE reads as a name up to the ")": a sign, as in
        // "(?(R-1)", ends it unterminated. "R" and digits alone is the
        // recursion condition; any other name, "Rx" or "R1a", is looked up.
        $nameError = $this->conditionNameError($startPosition);
        if (null !== $nameError) {
            throw $nameError;
        }

        $digits = $this->consumeWhile(static fn (string $c): bool => Ascii::isDigit($c));
        if (!$this->stream->check(TokenType::GroupClose)) {
            $this->stream->setPosition($savedPos);

            return null;
        }

        $name = 'R'.$digits;
        if (\in_array($name, $this->patternNames, true)) {
            return new BackrefNode($name, $startPosition, $this->stream->current()->position);
        }
        $this->recursionTests[] = $name;

        if ('' !== $digits) {
            $endPosition = $this->stream->previous()->position;
        }

        return new SubroutineNode($name, '', $startPosition, $endPosition);
    }

    /**
     * Parses a bare name condition in a conditional construct.
     */
    private function parseBareNameCondition(int $startPosition): ?BackrefNode
    {
        if (!$this->stream->check(TokenType::Literal)) {
            return null;
        }

        $savedPos = $this->stream->getPosition();
        $nameStart = $this->stream->current()->position;
        $name = '';
        while (
            $this->stream->check(TokenType::Literal)
            && !$this->stream->checkLiteral(')')
            && !$this->stream->isAtEnd()
        ) {
            $name .= $this->stream->current()->value;
            $this->stream->advance();
        }

        // Only characters a name may hold, up to the ")".
        if ('' !== $name && $this->stream->check(TokenType::GroupClose)
            && $this->groupNames->invalidNameOffset($nameStart) === $this->stream->current()->position) {
            $this->guardNameLength($name, $nameStart);

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

        // "(?(" the pattern ends in misses its ")".
        if ($this->stream->isAtEnd() && $startPosition >= \strlen($this->pattern)) {
            throw $this->parserException(\sprintf('Missing ")" to close the conditional at position %d.', $startPosition), ErrorCode::GroupUnclosed, $startPosition);
        }

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

        // "(?(*" the lexer read as no verb, for want of a name or a ")", is
        // no assertion either: refused past the "(", on it before 10.47.
        if ($this->stream->check(TokenType::Quantifier) && '*' === ($this->pattern[$startPosition] ?? '')) {
            throw $this->unclosedVerbConditionError($startPosition - 1, $this->pastTheFault($startPosition));
        }

        // "(?(VERSION=10z)", or "(?(VERSIONx)": PCRE reads a version
        // condition before a name, and refuses it where it goes wrong.
        $versionError = $this->versionConditionErrorOffset($startPosition);
        if (null !== $versionError) {
            throw $this->versionConditionError($versionError, $startPosition);
        }

        // Under /u no name starts with a digit of any script, as in "(?(٣)":
        // PCRE refuses the digit, past it from PCRE2 10.47.
        if ($this->unicodeMode && 1 === LibraryPcre::match('/\G\p{Nd}/u', $this->pattern, $matches, 0, $startPosition)) {
            $position = $this->groupNames->invalidNameOffset($startPosition);

            throw $this->parserException(
                \sprintf('Invalid condition name at position %d: a group name must not start with a digit.', $position),
                ErrorCode::GroupNameInvalid,
                $position,
            );
        }

        $bareName = $this->parseBareNameCondition($startPosition);
        if (null !== $bareName) {
            return $bareName;
        }

        // A name that something other than ")" ends, as in "(?(ab!)".
        $nameError = $this->conditionNameError($startPosition);
        if (null !== $nameError) {
            throw $nameError;
        }

        // Anything else, "(?(+a)" or "(?({2})" included, has to be a name, and PCRE refuses what cannot be one
        // before reading it: an escape such as "\1", or a group such as
        // "((?=a))", is no condition.
        $position = $this->groupNames->invalidNameOffset($startPosition);

        throw $this->parserException(
            \sprintf(
                'Invalid conditional construct at position %d. Condition must be a group reference, lookaround, or (DEFINE).',
                $position,
            ),
            ErrorCode::ConditionalInvalid,
            $position,
        );
    }

    /**
     * What PCRE refuses in the name it reads as a condition from $nameStart
     * to the ")" that must end it: past the length limit, where the name
     * ends, then anything but ")" there. Null when ")" ends the name, or
     * when no name starts there: nothing a name holds, or a digit.
     */
    private function conditionNameError(int $nameStart): ?ParserException
    {
        $nameEnd = $this->groupNames->invalidNameOffset($nameStart);
        $digit = $this->unicodeMode ? '/\G\p{Nd}/u' : '/\G[0-9]/';
        if ($nameEnd === $nameStart || 1 === LibraryPcre::match($digit, $this->pattern, $matches, 0, $nameStart)) {
            return null;
        }

        if ($nameEnd - $nameStart > $this->groupNames->maxNameLength()) {
            return $this->parserException(
                \sprintf('Group name is too long: %d code units, PCRE allows at most %d.', $nameEnd - $nameStart, $this->groupNames->maxNameLength()),
                ErrorCode::GroupNameTooLong,
                $nameEnd,
            );
        }

        if (')' === ($this->pattern[$nameEnd] ?? '')) {
            return null;
        }

        return $this->parserException(
            \sprintf('Missing ")" to close the condition name at position %d.', $nameEnd),
            ErrorCode::GroupNameUnterminated,
            $nameEnd,
        );
    }

    /**
     * Where PCRE refuses the version condition that starts at $start, or
     * null when it takes it or reads none there.
     */
    private function versionConditionErrorOffset(int $start): ?int
    {
        return VersionCondition::errorOffset($this->pattern, $start, !$this->supports(PcreFeature::VersionConditionWholeNumbers), $this->supports(PcreFeature::ErrorOffsetPastTheFault), $this->unicodeMode);
    }

    /**
     * @param int $start where the "VERSION" of the condition starts
     */
    private function versionConditionError(int $position, int $start): ParserException
    {
        // Before PCRE2 10.47, what follows the major number where the ")"
        // belongs leaves the condition open.
        if (!$this->supports(PcreFeature::VersionConditionLeftOpenIsVersionError) && VersionCondition::isMajorLeftOpenAt($this->pattern, $start, $position)) {
            return $this->parserException(
                \sprintf('Missing ")" to close the condition at position %d.', $position),
                ErrorCode::ConditionUnclosed,
                $position,
            );
        }

        return $this->parserException(
            \sprintf('Invalid VERSION condition at position %d: PCRE takes "VERSION=" or "VERSION>=", a major number, an optional ".minor", and ")".', $position),
            ErrorCode::ConditionVersionSyntax,
            $position,
        );
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

        $endToken = $this->stream->consume(TokenType::CharClassClose, 'Expected "]" to close character class', ErrorCode::CharclassUnclosed);

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
            if ($this->stream->match(TokenType::Negation)) {
                $isNegated = true;
            } elseif ($this->stream->match(TokenType::QuoteModeStart)) {
                $this->inQuoteMode = true;
            } elseif ($this->stream->match(TokenType::QuoteModeEnd)) {
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
            !$this->stream->check(TokenType::CharClassClose)
            && !$this->stream->isAtEnd()
        ) {
            // Silent tokens inside char class
            if ($this->stream->match(TokenType::QuoteModeStart)) {
                $this->inQuoteMode = true;

                continue;
            }
            if ($this->stream->match(TokenType::QuoteModeEnd)) {
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
    private function guardRangeEndpoint(NodeInterface $node, int $position, bool $isEnd): void
    {
        if (!$this->isNonRangeEndpointType($node)) {
            return;
        }

        // Before PCRE2 10.45, a range start is refused on the hyphen, a POSIX
        // class ending a range just inside its "[", and a type or a property
        // ending one past its letter.
        if (!($this->supports(PcreFeature::RangeFromTypeReadToItsEnd))) {
            $position = match (true) {
                !$isEnd => $position - 1,
                $node instanceof PosixClassNode => $node->getStartPosition() + 1,
                // A type or a property.
                default => $node->getStartPosition() + 2,
            };
        }

        throw $this->parserException(
            \sprintf(
                'Invalid range in character class: a character type, POSIX class, or Unicode property cannot be a range endpoint at position %d.',
                $position,
            ),
            ErrorCode::RangeInvalidBounds,
            $position,
        );
    }

    /**
     * Whether an escape PCRE refuses inside a class starts at $position:
     * an assertion such as "\B", "\R", "\X", or "\N" that names no code
     * point, or a letter with no meaning there, as "\j".
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

        // An assertion, or a letter PCRE gives no meaning in a class; "\k" is
        // the letter from PCRE2 10.45.
        return '' !== $letter && (str_contains('ABCFGIJKLMORTUXYZijlmquyz', $letter) || ('k' === $letter && !$this->supports(PcreFeature::ClassBackslashKIsLetter)));
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

        if ($this->stream->match(TokenType::CharClassOpen)) {
            $node = $this->parseCharClass();

            return [$node, $node->getEndPosition()];
        }

        if ($this->stream->match(TokenType::Range)) {
            $token = $this->stream->previous();

            return [new LiteralNode($token->value, $startPosition, $token->end()), $token->end()];
        }

        if ($this->stream->match(TokenType::PosixClass)) {
            $token = $this->stream->previous();

            return [new PosixClassNode($token->value, $startPosition, $token->end()), $token->end()];
        }

        throw $this->parserException(
            \sprintf(
                'Unexpected token "%s" in character class at position %d.',
                $this->stream->current()->value,
                $this->stream->current()->position,
            ),
            ErrorCode::TokenUnexpected,
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
        if ($singleCharacterStart && (!$classEscapeStart || $this->supports(PcreFeature::EmptyQuoteSkippedAfterClassEscape))) {
            $this->skipEmptyQuotes();
        }

        // Check for Range
        $rangePosition = $this->stream->getPosition();
        if (!$this->stream->match(TokenType::Range)) {
            $this->stream->setPosition($beforeQuotes);
            $this->inQuoteMode = $wasInQuoteMode;

            return $startNode;
        }

        // PCRE refuses a class escape before the "-" once it has read the "-".
        $afterHyphen = $this->stream->previous()->end();

        // PCRE skips "\E" and an empty "\Q\E" after the "-": "[a-\Ec]" is
        // the range a-c, and in "[a-\Q\E]" the "-" is a plain member.
        $this->skipEmptyQuotes();

        if ($this->stream->check(TokenType::CharClassClose)) {
            $this->stream->setPosition($rangePosition);

            return $startNode;
        }

        // Before PCRE2 10.45 a range from a type is refused on its hyphen,
        // before its end is read; from 10.45 an escape PCRE refuses in a
        // class at its end is reported first.
        if (!$this->supports(PcreFeature::RangeFromTypeReadToItsEnd)) {
            $this->guardRangeEndpoint($startNode, $afterHyphen, false);
        }

        $endAt = $this->stream->current()->position;
        if ($this->isClassInvalidEscapeAt($endAt)) {
            // Before PCRE2 10.45, "\N" ends a range as a type does: the
            // range is refused past its letter.
            if ('N' === $this->pattern[$endAt + 1] && !$this->supports(PcreFeature::ClassNEndingRangeRefusedAsN)) {
                throw $this->parserException(
                    \sprintf('Invalid range in character class: "\N" cannot end a range, at position %d.', $endAt + 2),
                    ErrorCode::RangeInvalidBounds,
                    $endAt + 2,
                );
            }

            $this->stream->setPosition($rangePosition);

            return $startNode;
        }

        $this->guardRangeEndpoint($startNode, $afterHyphen, false);

        if ($this->stream->check(TokenType::CharClassOpen)) {
            $this->stream->rewind(1);

            return $startNode;
        }

        // A quoted end, "[a-\Qcz\E]", ends the range at the first quoted
        // character; the lexer gives a class one quoted character at a time,
        // so the rest stay members.
        if ($this->stream->check(TokenType::QuoteModeStart)) {
            if (TokenType::Literal !== $this->stream->peek()->type) {
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
                ErrorCode::TokenUnexpected,
                $this->stream->current()->position,
            );
        }

        // A class escape after it, once it has read the escape.
        $this->guardRangeEndpoint($endNode, $endNode->getEndPosition(), true);

        return new RangeNode($startNode, $endNode, $startPosition, $endNode->getEndPosition());
    }

    /**
     * Skip the "\E" and empty "\Q\E" that PCRE reads as nothing.
     */
    private function skipEmptyQuotes(): void
    {
        while (true) {
            if ($this->stream->match(TokenType::QuoteModeEnd)) {
                $this->inQuoteMode = false;

                continue;
            }

            if ($this->stream->check(TokenType::QuoteModeStart)
                && TokenType::QuoteModeEnd === $this->stream->peek()->type) {
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
            !$this->stream->check(TokenType::GroupClose)
            && !$this->stream->isAtEnd()
            // "(?&name(<g>))": the groups the call returns, PCRE2 10.47 on.
            && !('' !== $name && $this->stream->check(TokenType::GroupOpen) && $this->supports(PcreFeature::CallsReturnCaptureGroups))
        ) {
            $isLiteral = $this->stream->check(TokenType::Literal) || $this->stream->check(TokenType::LiteralEscaped);
            $char = $this->stream->current()->value;
            if (!$isLiteral || 1 !== LibraryPcre::match('/^[\p{L}\p{Nd}_]$/u', $char)) {
                throw $this->subroutineNameError($name, $nameStart, $char);
            }

            $name .= $char;
            $this->stream->advance();
        }
        if ('' === $name) {
            throw $this->parserException(
                'Expected subroutine name at position '.$this->stream->current()->position,
                ErrorCode::GroupNameExpected,
                $this->stream->current()->position,
            );
        }

        // A name that starts with a digit names no group: PCRE stops past the
        // digit.
        if (1 === LibraryPcre::match('/^\p{Nd}/u', $name)) {
            throw $this->parserException(
                \sprintf(
                    'Invalid group name "%s": names must contain only word characters and must not start with a digit.',
                    $name,
                ),
                ErrorCode::GroupNameInvalid,
                $this->groupNames->invalidNameOffset($nameStart),
            );
        }

        $this->guardNameLength($name, $nameStart);

        return $name;
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
     * A character that cannot be part of the name of "(?&name)" or
     * "(?P>name)": PCRE reads the name, then wants ")". With no name read,
     * a name is expected; a name starting with a digit is refused first.
     */
    private function subroutineNameError(string $name, int $nameStart, string $found): ParserException
    {
        $position = $this->stream->current()->position;

        if ('' === $name) {
            return $this->parserException(\sprintf('Unexpected token "%s" at position %d: a subroutine name is expected there.', $found, $position), ErrorCode::GroupNameExpected, $position);
        }

        if (1 === LibraryPcre::match('/^\p{Nd}/u', $name)) {
            $position = $this->groupNames->invalidNameOffset($nameStart);

            return $this->parserException(\sprintf('Invalid subroutine name "%s" at position %d: a group name must not start with a digit.', $name, $position), ErrorCode::GroupNameInvalid, $position);
        }

        return $this->parserException(\sprintf('Unexpected token "%s" after the subroutine name at position %d: ")" is expected to close the call.', $found, $position), ErrorCode::GroupUnclosed, $position);
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
        $syntax = [ErrorCode::GroupSyntax, 'Invalid group modifier syntax at position %d'];
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
            $tooBig = $this->numberTooBigOffset($digits, $position + \strlen($sign))
                ?? ('+' === $sign && (int) $digits + $this->captureCount > 65535 ? $end : null);

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
            $fault = $this->pastTheFault(min($position + 2, $length), min($position + 2, $length) - $position);

            return $position + 1 >= $length
                ? [$fault, ...$unclosed]
                : [$fault, ErrorCode::SubroutineInvalidSyntax, 'A digit is expected after "(?+", at position %d.'];
        }

        if ('C' === $char) {
            [$fault, $code] = $this->calloutFault($start) ?? [$start, ErrorCode::GroupSyntax];

            return [$fault, $code, 'Invalid callout at position %d: '.str_replace('%', '%%', self::calloutProblem($code)).'.'];
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
                    return [$this->pastTheFault($position), ErrorCode::GroupOptionHyphen, 'Invalid hyphen in option setting at position %d: modifiers are turned off once, after a single "-" that no "^" precedes.'];
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
                return [$this->pastTheFault($position), ...$syntax];
            }
        }

        return $position < $length ? [$start, ...$syntax] : [$length, ...$unclosed];
    }

    /**
     * Where PCRE refuses a number past 65535, a group number or a count,
     * whose digits start at $at: past the whole number from PCRE2 10.45,
     * past the digit that takes it over before. Null for one within.
     */
    private function numberTooBigOffset(string $digits, int $at): ?int
    {
        $value = 0;
        foreach (str_split($digits) as $read => $digit) {
            $value = $value * 10 + (int) $digit;
            if ($value > 65535) {
                return $at + ($this->supports(PcreFeature::NumberTooBigPastWholeNumber) ? \strlen($digits) : $read + 1);
            }
        }

        return null;
    }

    /**
     * Where PCRE refuses a callout, and why, or null when it reads it: past
     * a digit that takes the number over 255, at a string delimiter never
     * closed, past a character that opens no string, where the ")" should
     * follow the argument, or at the end of a pattern that ends after "(?C".
     *
     * @param int $start the offset of the "(" of "(?C"
     *
     * @return array{0: int, 1: ErrorCode}|null
     */
    private function calloutFault(int $start): ?array
    {
        $pattern = $this->pattern;
        $length = \strlen($pattern);
        $position = $start + 3;

        if ($position >= $length) {
            return [$length, ErrorCode::GroupUnclosed];
        }

        if (Ascii::isDigit($pattern[$position])) {
            $number = 0;
            while ($position < $length && Ascii::isDigit($pattern[$position])) {
                $number = $number * 10 + (int) $pattern[$position++];
                if ($number > 255) {
                    return [$position, ErrorCode::CalloutOutOfRange];
                }
            }
        } elseif (')' !== $pattern[$position]) {
            $closing = self::CALLOUT_STRING_DELIMITERS[$pattern[$position]] ?? null;
            if (null === $closing) {
                return [$this->pastTheFault($position + 1), ErrorCode::CalloutInvalidDelimiter];
            }

            $opening = $position;
            while (true) {
                if (++$position >= $length) {
                    return [$opening, ErrorCode::CalloutUnclosedString];
                }

                // A doubled closing delimiter stands for itself.
                if ($closing === $pattern[$position] && (++$position >= $length || $closing !== $pattern[$position])) {
                    break;
                }
            }
        }

        return ')' === ($pattern[$position] ?? '') ? null : [$position, ErrorCode::CalloutUnclosed];
    }

    /**
     * What is wrong with a callout calloutFault() refuses, in words.
     */
    private static function calloutProblem(ErrorCode $code): string
    {
        return match ($code) {
            ErrorCode::CalloutOutOfRange => 'the callout number is greater than 255',
            ErrorCode::CalloutInvalidDelimiter => 'a callout takes a number, or a string opened by one of ` \' " ^ % # $ {',
            ErrorCode::CalloutUnclosedString => 'the callout string is not closed by its delimiter',
            ErrorCode::CalloutUnclosed => '")" is expected after the callout argument',
            default => '")" is expected to close the callout',
        };
    }

    /**
     * PCRE refuses a quantifier with nothing to repeat once it has read it,
     * before any "?" or "+" that would make it lazy or possessive; numbers
     * it cannot take are refused while it reads them.
     */
    private function quantifierErrorOffset(Token $token): int
    {
        $countError = $this->countErrorOffset($token);
        if (null !== $countError) {
            return $countError;
        }

        $suffixed = \strlen($token->value) > 1 && \in_array(substr($token->value, -1), ['?', '+'], true);

        return $this->pastTheFault($token->end() - ($suffixed ? 1 : 0));
    }

    /**
     * Where PCRE refuses the numbers of a count: past the digit that takes
     * one over 65535, or past the maximum when they are out of order. Null
     * for a count PCRE reads, or no count at all. From PCRE2 10.43, a count
     * may be padded with spaces and tabs, and "{,n}" is one.
     */
    private function countErrorOffset(Token $token): ?int
    {
        $blank = $this->supports(PcreFeature::OpenAndPaddedRepeatCounts) ? '[ \t]*+' : '';
        if (1 !== LibraryPcre::match('/^\{'.$blank.'(\d*+)'.$blank.'(?:(,)'.$blank.'(\d*+)'.$blank.')?\}/', $token->value, $matches, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        [$minimum, $minimumAt] = $matches[1];
        [$maximum, $maximumAt] = $matches[3];
        $minimum ??= '';
        $maximum ??= '';
        if ('' === $minimum && ('' === $blank || '' === $maximum)) {
            return null;
        }

        foreach ([[$minimum, $minimumAt], [$maximum, $maximumAt]] as [$digits, $at]) {
            $tooBig = $this->numberTooBigOffset($digits, $token->position + $at);
            if (null !== $tooBig) {
                return $tooBig;
            }
        }

        return '' !== $maximum && '' !== $minimum && (int) $maximum < (int) $minimum ? $token->position + $maximumAt + \strlen($maximum) : null;
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
        $countError = $this->countErrorOffset($token);
        if (null !== $countError) {
            throw $this->parserException(
                \sprintf('Invalid quantifier "%s" at position %d: its numbers are out of order or past 65535.', $token->value, $countError),
                $this->countErrorCode($token),
                $countError,
            );
        }
    }

    /**
     * What is wrong with a count countErrorOffset() refuses: a number past
     * 65535, or else numbers out of order.
     */
    private function countErrorCode(Token $token): ErrorCode
    {
        LibraryPcre::matchAll('/\d++/', $token->value, $numbers);

        foreach ($numbers[0] as $digits) {
            if (\strlen(ltrim($digits, '0')) > 5 || (int) $digits > 65535) {
                return ErrorCode::QuantifierTooBig;
            }
        }

        return ErrorCode::QuantifierInvalidRange;
    }

    /**
     * Where PCRE reports an error it reports past the character at fault
     * from PCRE2 10.47, given that later offset: $shift characters earlier
     * before 10.47, which every PHP bundles.
     */
    private function pastTheFault(int $offset, int $shift = 1): int
    {
        return $this->supports(PcreFeature::ErrorOffsetPastTheFault) ? $offset : $offset - $shift;
    }

    /**
     * "(**" opens no verb: a verb name never starts with "*", and "(?*" is
     * the only short spelling of a lookahead. PCRE refuses it at the second
     * "*", at $position.
     */
    private function doubleStarError(int $position): ParserException
    {
        return $this->parserException(
            \sprintf('Unknown verb at position %d: "(**" opens no verb, PCRE wants a name after "(*".', $position),
            ErrorCode::VerbInvalid,
            $position,
        );
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
     * Where PCRE reports a "(*" the pattern never closes: where its name
     * ends, or at the end of the pattern once a name it knows takes ":".
     *
     * @param int $start the offset of the "("
     */
    private function unclosedVerbOffset(int $start): int
    {
        // A limit is only read in the run of settings that opens the
        // pattern; elsewhere it is a verb PCRE does not know.
        LibraryPcre::match('/\A(?:\(\*[A-Z_]++(?:=\d*+)?\))*+/', $this->pattern, $settings);
        $limit = $start > \strlen($settings[0] ?? '') ? null : PcreVerb::limitValueErrorOffset(
            $this->pattern,
            $start,
            $this->supports(PcreFeature::LimitValueErrorOnFaultingCharacter),
        );
        if (null !== $limit) {
            return $limit;
        }

        LibraryPcre::match('/\G[A-Za-z0-9_]*+/', $this->pattern, $matches, 0, $start + 2);
        $name = $matches[0] ?? '';
        $nameEnd = $start + 2 + \strlen($name);

        if (':' === ($this->pattern[$nameEnd] ?? '') && PcreVerb::takesArgument($name)) {
            return \strlen($this->pattern);
        }

        // "(*" at the end is a "*" with nothing to repeat, and from PCRE2
        // 10.47 an alphabetic name, one that starts with a lowercase letter,
        // followed by no colon is refused past the character after it.
        $pastTheFault = $this->supports(PcreFeature::ErrorOffsetPastTheFault);
        if ('' === $name && $nameEnd >= \strlen($this->pattern)) {
            return $this->pastTheFault($nameEnd);
        }

        return $pastTheFault && 1 === LibraryPcre::match('/^[a-z]/', $name) && $nameEnd < \strlen($this->pattern) && ':' !== $this->pattern[$nameEnd]
            ? $nameEnd + 1
            : $nameEnd;
    }

    /**
     * creates a ParserException with context about the pattern being parsed
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
                \sprintf('Recursion limit of %d exceeded', $this->maxRecursionDepth),
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

    private function parseSimpleGroup(int $startPosition, GroupType $type): GroupNode
    {
        $expr = $this->parseScopedAlternation();
        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);

        return $this->createGroupNode($expr, $type, $startPosition, $endToken);
    }

    /**
     * Check if current token is a literal digit.
     */
    private function isLiteralDigitToken(): bool
    {
        return $this->stream->check(TokenType::Literal) && Ascii::isDigit($this->stream->current()->value);
    }

    /**
     * @return bool true if the current token is a T_LITERAL and its value is an alphabetic character (a-z, A-Z)
     */
    private function isLiteralAlphaToken(): bool
    {
        return $this->stream->check(TokenType::Literal) && Ascii::isAlpha($this->stream->current()->value);
    }

    /**
     * Consumes tokens while the predicate returns true, concatenating their values.
     */
    private function consumeWhile(callable $predicate): string
    {
        $value = '';

        while (
            !$this->stream->isAtEnd()
            && $this->stream->check(TokenType::Literal)
            && $predicate($this->stream->current()->value)
        ) {
            $value .= $this->stream->current()->value;
            $this->stream->advance();
        }

        return $value;
    }
}
