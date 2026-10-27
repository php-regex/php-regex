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
use PHPRegex\Parser\Exception\SyntaxErrorException;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\PhpVersionGates;
use PHPRegex\Parser\Text\Utf8;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;

/**
 * Reads the tokens of a pattern into its tree, as PCRE2 reads the pattern.
 *
 * A recursive descent over the tokens the lexer gives: an alternation of
 * sequences of quantified atoms, and a group read for each "(" with the
 * alternation inside it. It follows the PCRE2 release of its target, down
 * to the offset each error is reported at. This parser holds the reading
 * loop, the entry, the alternatives and the sequences with the trivia of
 * "x", and the dispatch: the type of the token under the cursor, and after
 * "(?" what follows it, picks the reader of the item.
 *
 * Each family of constructs is read by a reader of its own: quantifiers by
 * QuantifierParser, back references and the escapes PCRE reads in their
 * place by ReferenceParser, the groups a "(?" opens and the option settings
 * by GroupParser, the calls by SubroutineParser, verbs by VerbParser, a
 * character class by ClassParser, an extended class by ExtendedClassParser
 * and the condition of a conditional by ConditionParser. They share this
 * parser's ParseState, the stream, the modes and the group count, and are
 * made for each reading on the first item of their family. Where PCRE
 * refuses a count, an unclosed verb or a callout is asked of RepeatCount,
 * PcreVerb and Callout, which read no parser state. A body PCRE reads from
 * the text, the payload of an alphabetic assertion or an operand of an
 * extended class, is lexed and read by a parser of its own, its offsets
 * moved into the whole pattern.
 *
 * A pattern with a recursion test written before the group its name gives,
 * "(?(R2)b|c)(?<R2>a)", is read a second time, knowing every name.
 *
 * @internal
 */
final class TokenParser
{
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

    /**
     * The reading state this parser shares with the readers of each family
     * of constructs: the stream, the pattern, the target, the UTF and
     * bad-escape modes, whether the tokens are quoted, the options in force
     * and the groups numbered so far. The stream, pattern, target and UTF
     * mode are also kept on this parser, the same values, which every step
     * reads.
     */
    private ParseState $state;

    /**
     * The reader of the quantifiers of the text this parser reads, made on
     * the first quantifier.
     */
    private ?QuantifierParser $quantifiers = null;

    /**
     * The reader of the references and escapes of the text this parser
     * reads, made on the first one.
     */
    private ?ReferenceParser $references = null;

    /**
     * The reader of the verbs of the text this parser reads, made on the
     * first one.
     */
    private ?VerbParser $verbs = null;

    /**
     * The reader of the calls a "(?" opens in the text this parser reads,
     * made on the first group that might be one.
     */
    private ?SubroutineParser $subroutines = null;

    /**
     * The reader of the groups a "(?" opens in the text this parser reads,
     * made on the first.
     */
    private ?GroupParser $groups = null;

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

    private readonly int $maxRecursionDepth;

    private readonly PcreTarget $target;

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

        // Without "X", PHP 7.3 and 7.4 compile with
        // PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL: an escape PCRE2 refuses as it
        // reads it is the character after the backslash. The lexer reads
        // those it can; a reference counted back past the groups opened so
        // far, or an octal escape past a byte, is known here.
        $this->state = new ParseState($stream, $this->pattern, $this->target, $this->unicodeMode, PhpVersionGates::badEscapeIsLiteral($this->target->phpVersionId, $flags));
        $this->state->extendedMode = str_contains($flags, 'x');
        $this->state->extendedMoreMode = $this->extendedMoreBefore;
        $this->state->noAutoCapture = str_contains($flags, 'n');
        $this->state->captureCount = $this->capturesBefore;
        $this->forgetReaders();
        $this->recursionDepth = $this->depthBefore;
        $this->tokensReadWhole = 0;
        $this->recursionTests = [];
        $this->namesReadApart = [];

        try {
            $patternNode = $this->parseAlternation();

            // A ")" no group opened: PCRE2 10.47 reports it past the ")", the
            // releases before on it.
            if ($this->stream->check(TokenType::GroupClose)) {
                $position = $this->target->faultOffset($this->stream->current()->position + 1);

                throw $this->parserException(\sprintf('Unmatched closing parenthesis at position %d.', $position), ErrorCode::GroupUnmatchedClose, $position);
            }

            $this->stream->consume(TokenType::Eof, 'Unexpected content at end of pattern', ErrorCode::TokenUnexpected);

            return new RegexNode($patternNode, $flags, $delimiter, 0, $patternLength, $this->pattern);
        } finally {
            // The readers that call this parser back hold it in their
            // closures: dropped with the reading, they leave no cycle for
            // the garbage collector to find.
            $this->forgetReaders();
        }
    }

    /**
     * Drops the readers made for the text read: the next reading makes its
     * own, on its own state.
     */
    private function forgetReaders(): void
    {
        $this->classes = null;
        $this->conditions = null;
        $this->extendedClasses = null;
        $this->quantifiers = null;
        $this->references = null;
        $this->verbs = null;
        $this->subroutines = null;
        $this->groups = null;
    }

    /**
     * Parse the body of a group. A "(?x)" setting holds until the end of the
     * enclosing group — crossing "|" — so the mode is restored here and not
     * per alternation branch, the way PCRE scopes it.
     */
    private function parseScopedAlternation(): NodeInterface
    {
        $extendedMode = $this->state->extendedMode;
        $extendedMoreMode = $this->state->extendedMoreMode;
        $noAutoCapture = $this->state->noAutoCapture;
        $duplicateNames = $this->groupNames->duplicatesAllowed();

        try {
            return $this->parseAlternation();
        } finally {
            $this->state->extendedMode = $extendedMode;
            $this->state->extendedMoreMode = $extendedMoreMode;
            $this->state->noAutoCapture = $noAutoCapture;
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

            if (TokenType::Quantifier !== $type || !$this->quantifiers()->quantifyPreviousItem($nodes)) {
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
        if (!$this->state->extendedMode || $this->state->inQuoteMode) {
            return false;
        }

        $skipped = false;
        while (!$this->stream->isAtEnd() && !$this->stream->check(TokenType::GroupClose) && !$this->stream->check(TokenType::Alternation)) {
            $token = $this->stream->current();
            if (TokenType::Literal !== $token->type) {
                break;
            }

            // Skip pure whitespace silently; comments will be explicit nodes.
            if ($this->state->isExtendedWhitespace($token->value)) {
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
        if (!$this->state->extendedMode || $this->state->inQuoteMode) {
            return 0;
        }

        $skipped = 0;
        while (!$this->stream->isAtEnd() && !$this->stream->check(TokenType::GroupClose) && !$this->stream->check(TokenType::Alternation)) {
            $token = $this->stream->current();
            if (TokenType::Literal !== $token->type) {
                break;
            }

            if ($this->state->isExtendedWhitespace($token->value)) {
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

    private function parseQuantifiedAtom(): NodeInterface
    {
        $node = $this->parseAtom();
        // PCRE reads the item before what repeats it.
        $this->tokensReadWhole = $this->stream->getPosition();

        // "\1000*": the octal escape, then the text "0" the quantifier repeats.
        if (null !== $this->state->splitEscape && $node === $this->state->splitEscape) {
            $this->state->splitEscape = null;
            $children = $node->children;
            $text = array_pop($children) ?? $node;
            $last = $this->quantifiers()->parseQuantifiedText($text);
            // One sequence: a second quantifier finds the repeated character
            // last in it, as it does after "\Qab\E*".
            $tail = $last instanceof SequenceNode ? $last->children : [$last];

            return $last === $text ? $node : new SequenceNode([...$children, ...$tail], $node->startPosition, $last->getEndPosition());
        }

        // A quantifier after a comment repeats the item before the comment.
        if ($node instanceof CommentNode) {
            return $node;
        }

        $skipped = $this->state->skipExtendedWhitespace();

        if ($this->stream->match(TokenType::Quantifier)) {
            return $this->quantifiers()->repeat($node, $this->stream->previous());
        }

        if ($skipped > 0) {
            $this->stream->rewind($skipped);
        }

        return $node;
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

                return $this->verbs()->verbNode($token, $startPosition);
            case TokenType::Quantifier:
                break;
            default:
                if (null !== $node = $this->parseSimpleAtom($startPosition)) {
                    return $node;
                }
        }

        if (TokenType::Quantifier === $token->type) {
            $this->quantifiers()->refuseQuantifierWithoutTarget();
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
        $token = $this->stream->current();
        if (TokenType::LiteralEscaped === $token->type && 'k' === $token->value) {
            $this->references()->guardNamedReferenceEscape($token);
        }

        if (null !== $atom = $this->matchAtom($startPosition)) {
            // Before PCRE2 10.37 the digits after a "{" read as text are read
            // as a count too.
            $previous = $this->stream->previous();
            if (TokenType::Literal === $previous->type && '{' === ($this->pattern[$previous->position] ?? '')) {
                $this->quantifiers()->guardOpenCountBeforeTen37($previous);
            }

            // Without UTF mode "x" skips a 0x85 byte inside a character too.
            if ($atom instanceof LiteralNode && $this->state->extendedMode && !$this->unicodeMode && !$this->state->inQuoteMode
                && TokenType::Literal === $previous->type && \strlen($atom->value) > 1 && str_contains($atom->value, "\x85")) {
                return new LiteralNode(str_replace("\x85", '', $atom->value), $atom->getStartPosition(), $atom->getEndPosition(), $atom->isRaw);
            }

            return $atom;
        }

        if ($this->stream->match(TokenType::Dot)) {
            return new DotNode($startPosition, $this->stream->previous()->end());
        }

        if ($this->stream->match(TokenType::GReference)) {
            return $this->references()->parseGReference($startPosition);
        }

        if ($this->stream->match(TokenType::Keep)) {
            return new KeepNode($startPosition, $this->stream->previous()->end());
        }

        return null;
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
        return match ($type) {
            TokenType::Anchor => new AnchorNode($token->value, $startPosition, $token->end()),
            TokenType::Assertion => new AssertionNode($token->value, $startPosition, $token->end()),
            default => $this->references()->backReference($token, $startPosition),
        };
    }

    /**
     * The group a plain "(" opens, the "(" read.
     */
    private function parsePlainGroup(Token $startToken): NodeInterface
    {
        // Under "n" a plain group groups and nothing more, as "(?:...)"
        // does; the compiler gives back the "(" it was written with.
        $captures = !$this->state->noAutoCapture;
        if ($captures) {
            $this->state->captureCount++;
        }

        $expr = $this->parseScopedAlternation();
        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);

        return new GroupNode(
            $expr,
            $captures ? GroupType::Capturing : GroupType::NonCapturing,
            null,
            null,
            $startToken->position,
            $endToken->position + 1,
        );
    }

    /**
     * parses callouts like (?C), (?C1), (?C"name"), (?C"string"), and (?Cname)
     */
    private function parseCallout(): CalloutNode
    {
        return Callout::read($this->stream->previous(), $this->pattern, $this->target);
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
            return $this->verbs()->parsePcreVerbInGroup($startPosition);
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
            return $this->groups()->parseNamedGroup($startPosition, false);
        }

        // 4. Check for standard lookarounds and named groups
        if ($this->stream->matchLiteral('<')) {
            return $this->groups()->parseStandardGroup($startPosition);
        }

        // 5. Check for conditional (?(...)
        $isConditionalWithModifier = null;
        if ($this->stream->match(TokenType::GroupModifierOpen)) {
            $isConditionalWithModifier = true;
        } elseif ($this->stream->match(TokenType::GroupOpen)) {
            $isConditionalWithModifier = false;
        }

        if (null !== $isConditionalWithModifier) {
            return $this->conditions()->conditional($startPosition, $isConditionalWithModifier, $this->state->captureCount);
        }

        // 6. Check for Subroutines
        if (SubroutineParser::mayOpenCall($this->stream) && null !== $call = $this->subroutines()->parseCall($startPosition)) {
            return $call;
        }

        // 7. Check for simple non-capturing, lookaheads, atomic, branch reset
        $simpleGroupModifier = $this->groups()->parseSimpleGroupModifier($startPosition);
        if (null !== $simpleGroupModifier) {
            return $simpleGroupModifier;
        }

        // 8. Inline flags
        return $this->groups()->parseInlineFlags($startPosition);
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
        $flags = str_replace(['x', 'n'], '', $this->flags).($this->state->extendedMode ? 'x' : '').($this->state->noAutoCapture ? 'n' : '');

        // The payload is read for the same target as the pattern around it,
        // its tokens moved to where they stand in the whole pattern.
        $this->partLexer ??= new Lexer($this->target);

        try {
            $stream = $this->partLexer->tokenizePart($this->pattern, $absoluteOffset, \strlen($payload), $flags, $this->state->extendedMoreMode);
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
        $inner->capturesBefore = $this->state->captureCount;
        $inner->depthBefore = $this->recursionDepth;
        $inner->extendedMoreBefore = $this->state->extendedMoreMode;
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
        $this->state->captureCount = $inner->state->captureCount;

        return $pattern->pattern;
    }

    /**
     * Parses Python-style named groups and subroutines like (?P<name>...),
     * (?P>name), and (?P=name). PCRE takes nothing else after "(?P": not
     * "(?P'name'...)", which is "(?'name'...)" without the "P".
     */
    private function parsePythonGroup(int $startPos, int $pPos): NodeInterface
    {
        if ($this->stream->matchLiteral('<')) { // (?P<name>...)
            return $this->groups()->parseNamedGroup($startPos, true, true);
        }

        if ($this->stream->matchLiteral('>')) { // (?P>name) subroutine
            return $this->subroutines()->parsePythonSubroutine($startPos);
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
        $length = $this->unicodeMode ? Utf8::width($this->pattern, $pPos + 1) : 1;
        $position = $this->target->faultOffset($pPos + 1 + $length, $length);

        throw $this->parserException(
            \sprintf('Invalid syntax after (?P at position %d: "<", ">" or "=" is expected.', $position),
            ErrorCode::GroupSyntax,
            $position,
        );
    }

    /**
     * The alternatives of "(?|...)", up to its ")": every branch numbers its
     * groups from the same start, and the group count after it is that of
     * the longest branch.
     */
    private function parseResetAlternation(): NodeInterface
    {
        $extendedMode = $this->state->extendedMode;
        $extendedMoreMode = $this->state->extendedMoreMode;
        $noAutoCapture = $this->state->noAutoCapture;
        $duplicateNames = $this->groupNames->duplicatesAllowed();
        $base = $this->state->captureCount;
        $highest = $base;

        $this->guardRecursionDepth($this->stream->current()->position);
        $this->recursionDepth++;

        try {
            $branchStart = $this->stream->current()->position;
            $branches = [];

            do {
                $this->state->captureCount = $base;
                $branches[] = $this->parseSequence();
                $highest = max($highest, $this->state->captureCount);
            } while ($this->stream->match(TokenType::Alternation));
        } finally {
            $this->recursionDepth--;
            $this->state->extendedMode = $extendedMode;
            $this->state->extendedMoreMode = $extendedMoreMode;
            $this->state->noAutoCapture = $noAutoCapture;
            $this->groupNames->allowDuplicates($duplicateNames);
        }

        $this->state->captureCount = $highest;

        return 1 === \count($branches)
            ? $branches[0]
            : new AlternationNode($branches, $branchStart, end($branches)->getEndPosition());
    }

    private function quantifiers(): QuantifierParser
    {
        return $this->quantifiers ??= new QuantifierParser($this->state);
    }

    private function references(): ReferenceParser
    {
        return $this->references ??= new ReferenceParser($this->state, $this->groupNames);
    }

    /**
     * The reader of the groups a "(?" opens, which calls this parser back
     * for the alternatives inside them.
     */
    private function groups(): GroupParser
    {
        return $this->groups ??= new GroupParser($this->state, $this->groupNames, $this->parseScopedAlternation(...), $this->parseResetAlternation(...));
    }

    private function subroutines(): SubroutineParser
    {
        return $this->subroutines ??= new SubroutineParser($this->state, $this->groupNames);
    }

    /**
     * The reader of verbs, which calls this parser back for the alternatives
     * after a verb and for each body it reads apart.
     */
    private function verbs(): VerbParser
    {
        return $this->verbs ??= new VerbParser($this->state, $this->parseScopedAlternation(...), $this->parseSubPattern(...));
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
                    $parser->capturesBefore = $this->state->captureCount;
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
            fn (int $start, bool $behind): ?GroupNode => $this->groups()->parseLookaround($start, $behind),
            fn (Token $token, int $startPosition): NodeInterface => $this->verbs()->verbNode($token, $startPosition),
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
}
