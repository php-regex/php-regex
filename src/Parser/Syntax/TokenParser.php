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
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\PhpVersionGates;
use PHPRegex\Parser\Text\Ascii;
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
        $this->classes = null;
        $this->conditions = null;
        $this->extendedClasses = null;
        $this->quantifiers = null;
        $this->references = null;
        $this->verbs = null;
        $this->recursionDepth = $this->depthBefore;
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

        return $this->createGroupNode(
            $expr,
            $captures ? GroupType::Capturing : GroupType::NonCapturing,
            $startToken->position,
            $endToken,
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
            return $this->conditions()->conditional($startPosition, $isConditionalWithModifier, $this->state->captureCount);
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
        $length = $this->unicodeMode ? Utf8::width($this->pattern, $pPos + 1) : 1;
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

            return $this->createGroupNode($expr, GroupType::LookbehindPositive, $startPos, $endToken, null, VerbParser::NON_ATOMIC_FLAG);
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

        [$groups, $at] = (new GroupListReader($this->state))->readGroupList($open + 1);

        while (!$this->stream->isAtEnd() && $this->stream->current()->position < $at) {
            $this->stream->advance();
        }

        return $groups;
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

        $wasExtended = $this->state->extendedMode;
        $wasExtendedMore = $this->state->extendedMoreMode;
        $wasNoAutoCapture = $this->state->noAutoCapture;
        $wasAllowingDuplicates = $this->groupNames->duplicatesAllowed();

        if ($modifiers?->turnsOn('J')) {
            $this->groupNames->allowDuplicates(true);
        }
        if ($modifiers?->turnsOff('J')) {
            $this->groupNames->allowDuplicates(false);
        }

        $this->state->extendedMode = $modifiers?->inForce('x', $this->state->extendedMode) ?? $this->state->extendedMode;
        $this->state->extendedMoreMode = $modifiers?->extendedMoreInForce($this->state->extendedMoreMode) ?? $this->state->extendedMoreMode;
        $this->state->noAutoCapture = $modifiers?->inForce('n', $this->state->noAutoCapture) ?? $this->state->noAutoCapture;

        // "(?iz)": the letters are read as far as PCRE knows them.
        if (!$this->stream->check(TokenType::GroupClose) && !$this->stream->checkLiteral(':')) {
            throw $this->unreadableGroupError($startPosition);
        }

        $expr = null;
        if ($this->stream->matchLiteral(':')) {
            $expr = $this->parseScopedAlternation();
            // "(?x:...)" only covers its own group; "(?x)" keeps going.
            $this->state->extendedMode = $wasExtended;
            $this->state->extendedMoreMode = $wasExtendedMore;
            $this->state->noAutoCapture = $wasNoAutoCapture;
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

    private function quantifiers(): QuantifierParser
    {
        return $this->quantifiers ??= new QuantifierParser($this->state);
    }

    private function references(): ReferenceParser
    {
        return $this->references ??= new ReferenceParser($this->state, $this->groupNames);
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
            fn (int $start, bool $behind): ?GroupNode => $this->matchLookaround($start, $behind ? self::LOOKBEHINDS : self::LOOKAHEADS),
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
                ?? ('+' === $sign && (int) $digits + $this->state->captureCount > PcreNumber::MAX ? $end : null);

            return match (true) {
                null !== $tooBig => [$tooBig, ErrorCode::GroupNumberTooBig, 'Group number '.$number.' is too big at position %d: PCRE takes at most 65535.'],
                '' !== $sign && 0 === (int) $digits => [$end, ErrorCode::SubroutineRelativeZero, 'Subroutine call relative reference cannot be zero, at position %d.'],
                '-' === $sign && (int) $digits > $this->state->captureCount => [$end, ErrorCode::SubroutineRelativeMissing, 'Subroutine call relative reference '.$number.' points before the first group, at position %d.'],
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
        $name = $this->groupNames->read(true, ++$this->state->captureCount, !$expectAngle);

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
