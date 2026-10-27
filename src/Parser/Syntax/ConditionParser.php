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
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Utf8;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;

/**
 * Reads a conditional, "(?(condition)yes|no)", for the token parser.
 *
 * The condition is the part with most to read: a group number, relative or
 * not, a name in four spellings, a recursion test, "DEFINE", a version, or
 * an assertion, which a callout or a comment may precede and which may be
 * spelled as a verb. PCRE tells them apart by trying each in turn, and
 * refuses what none of them reads at an offset that moved across releases.
 * The rest, the branches, the lookaround that is the condition and the
 * verb, is the grammar of the whole pattern: this reader calls the token
 * parser back for it, through the closures it is given, and shares its
 * ParseState.
 *
 * @internal
 */
final class ConditionParser
{
    private readonly TokenStream $stream;

    private readonly string $pattern;

    private readonly PcreTarget $target;

    /**
     * @param list<string>                        $patternNames  every name the pattern gives a group, once a
     *                                                           first reading met a recursion test written
     *                                                           before the group so named; empty otherwise
     * @param \Closure(): NodeInterface           $branches      reads the alternatives of a group, up to its ")"
     * @param \Closure(int, bool): ?GroupNode     $lookaround    reads the lookahead, or with true the lookbehind,
     *                                                           whose "(?" stands at the offset given, the "<" of a
     *                                                           lookbehind read; null when none follows
     * @param \Closure(Token, int): NodeInterface $verb          reads the node of a "(*...)" token, at the offset given
     * @param \Closure(): int                     $skipTrivia    skips the whitespace and comments of "x", giving
     *                                                           how many tokens it skipped
     * @param \Closure(string): void              $recursionTest is told each "(?(R)" or "(?(R2)" read as a
     *                                                           recursion test for want of a group so named
     */
    public function __construct(
        private readonly ParseState $state,
        private readonly GroupNameReader $groupNames,
        private readonly array $patternNames,
        private readonly \Closure $branches,
        private readonly \Closure $lookaround,
        private readonly \Closure $verb,
        private readonly \Closure $skipTrivia,
        private readonly \Closure $recursionTest,
    ) {
        $this->stream = $state->stream;
        $this->pattern = $state->pattern;
        $this->target = $state->target;
    }

    /**
     * Parses conditional constructs (?(condition)...).
     */
    public function conditional(int $startPosition, bool $isModifier, int $groupsBefore): ConditionalNode|DefineNode
    {
        if ($isModifier) {
            // Inline Lookaround condition
            $conditionStartPos = $this->stream->previous()->position;
            $this->guardCutShortAssertion($conditionStartPos, $this->target->faultOffset($conditionStartPos + 1));

            // "(?(?C1" that no ")" closes, the "(?" read alone: the callout
            // is refused as anywhere else.
            if ($this->stream->checkLiteral('C')) {
                [$fault, $code] = Callout::fault($this->pattern, $conditionStartPos, $this->target) ?? [\strlen($this->pattern), ErrorCode::CalloutUnclosed];

                throw $this->state->error(\sprintf('Invalid callout at position %d: %s.', $fault, Callout::problem($code)), $code, $fault);
            }

            $condition = $this->lookaroundCondition($conditionStartPos);
        } else {
            $condition = $this->readCondition($groupsBefore);

            // "(?(VERSION=10.4": PCRE reads the ")" as part of the version.
            if ($condition instanceof VersionConditionNode && !$this->stream->check(TokenType::GroupClose)) {
                throw $this->versionConditionError($this->versionConditionErrorOffset($condition->startPosition) ?? $this->stream->current()->position, $condition->startPosition);
            }

            $this->stream->consume(TokenType::GroupClose, 'Expected ) after condition', ErrorCode::ConditionalUnclosed);
        }

        return $this->readBranches($startPosition, $condition);
    }

    /**
     * Parses "(?(?C1)(?=a)yes|no)": the condition is the callout followed by
     * the assertion, which PCRE requires there. Both are kept, in order, as
     * the condition.
     */
    public function calloutConditional(int $startPosition): NodeInterface
    {
        $callout = Callout::read($this->stream->previous(), $this->pattern, $this->target);

        // PCRE refuses a number past 255 as it reads it, before it looks
        // for the assertion due after the callout.
        if (!$callout->isStringIdentifier && \is_int($callout->identifier) && $callout->identifier > 255) {
            $position = Callout::fault($this->pattern, $callout->startPosition, $this->target)[0] ?? $callout->startPosition;

            throw $this->state->error(\sprintf('Invalid callout at position %d: %s.', $position, Callout::problem(ErrorCode::CalloutOutOfRange)), ErrorCode::CalloutOutOfRange, $position);
        }

        // PCRE skips a comment, an empty "\Q\E" and "x" whitespace there.
        do {
            $this->state->skipEmptyQuotes();
            $skipped = ($this->skipTrivia)();
            if ($this->stream->match(TokenType::CommentOpen)) {
                $this->state->comment();
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
            $assertion = ($this->verb)($verbToken, $verbToken->position);
        } elseif ($this->opensUnclosedVerb()) {
            throw $this->unclosedVerbConditionError($this->stream->current()->position, $this->calloutConditionErrorOffset());
        } elseif (!$this->stream->match(TokenType::GroupModifierOpen)) {
            $position = $this->calloutConditionErrorOffset();

            throw $this->state->error(
                \sprintf('Invalid conditional condition at position %d: a callout in a condition must be followed by an assertion.', $position),
                ErrorCode::ConditionalAssertionExpected,
                $position,
            );
        } else {
            // A group that is no assertion is refused where it starts, as
            // anything else there is.
            $groupStart = $this->stream->previous()->position;
            $this->guardCutShortAssertion($groupStart, $groupStart);
            $assertion = $this->lookaroundCondition($groupStart, $groupStart);
        }

        $condition = new SequenceNode([$callout, $assertion], $callout->getStartPosition(), $assertion->getEndPosition());

        return $this->readBranches($startPosition, $condition);
    }

    /**
     * Parses a conditional whose condition is written as a verb:
     * "(?(*pla:a)yes|no)". PCRE only takes a lookaround there; a verb, an
     * atomic group or a script run is refused.
     */
    public function verbConditional(int $startPosition, Token $verbToken): NodeInterface
    {
        // PCRE stops at the "*", past it from PCRE2 10.47.
        $this->guardVerbCondition($verbToken, $this->target->faultOffset($verbToken->position + 1));

        return $this->readBranches($startPosition, ($this->verb)($verbToken, $verbToken->position));
    }

    /**
     * Parses "(?(?#c)(?=a)yes|no)": PCRE skips the comment, as it skips "x"
     * whitespace and an empty "\Q\E" after it, and wants the assertion
     * next, a callout before it allowed. The comment is not kept.
     */
    public function commentedConditional(int $startPosition): NodeInterface
    {
        do {
            $this->state->skipEmptyQuotes();
            $skipped = ($this->skipTrivia)();
            if ($this->stream->match(TokenType::CommentOpen)) {
                $this->state->comment();
                $skipped++;
            }
        } while ($skipped > 0);

        if ($this->stream->match(TokenType::Callout)) {
            return $this->calloutConditional($startPosition);
        }

        if ($this->stream->match(TokenType::PcreVerb)) {
            return $this->verbConditional($startPosition, $this->stream->previous());
        }

        $this->guardConditionCutShort();
        $position = $this->commentConditionErrorOffset();
        if ($this->opensUnclosedVerb()) {
            throw $this->unclosedVerbConditionError($this->stream->current()->position, $position);
        }

        if ($this->stream->match(TokenType::GroupModifierOpen)) {
            $groupStart = $this->stream->previous()->position;
            $this->guardCutShortAssertion($groupStart, $position);

            return $this->readBranches($startPosition, $this->lookaroundCondition($groupStart));
        }

        throw $this->state->error(
            \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after "(?(".', $position),
            ErrorCode::ConditionalAssertionExpected,
            $position,
        );
    }

    /**
     * @param int $nameStart the offset of the name's first character
     */
    private function guardNameLength(string $name, int $nameStart): void
    {
        $tooLong = $this->groupNames->tooLongAt($nameStart, $nameStart + \strlen($name));
        if (null !== $tooLong) {
            throw $this->groupNames->tooLongError($nameStart, $nameStart + \strlen($name), $tooLong);
        }
    }

    /**
     * Where PCRE refuses what follows the callout of a condition instead of
     * an assertion: where it starts, from PCRE2 10.47; before, a character
     * read as is on its last byte, one in "\Q...\E" included.
     */
    private function calloutConditionErrorOffset(): int
    {
        return $this->target->supports(PcreFeature::CalloutConditionErrorAtItemStart)
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
        if (!$this->target->supports(PcreFeature::ErrorOffsetPastTheFault)) {
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
        return $this->state->unicodeMode && 1 === LibraryPcre::match('/^./su', $token->value, $matches) ? $matches[0] : $token->value[0];
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

        throw $this->state->error(
            \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after "(?(".', $position),
            ErrorCode::ConditionalAssertionExpected,
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

        throw $this->state->error(\sprintf('Missing ")" to close the conditional at position %d.', $position), ErrorCode::GroupUnclosed, $position);
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

        throw $this->state->error(
            \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after "(?(".', $faultAt),
            ErrorCode::ConditionalAssertionExpected,
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

        return $this->alphaNameConditionError($open + 2) ?? $this->state->error(
            \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after "(?(".', $unnamedAt),
            ErrorCode::ConditionalAssertionExpected,
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
        // Before PCRE2 10.33 no name is alphabetic.
        if (!$this->target->supports(PcreFeature::AlphabeticAssertionNames) || 1 !== LibraryPcre::match('/\G[a-z][A-Za-z0-9_]*+/', $this->pattern, $name, 0, $nameStart)) {
            return null;
        }

        $nameEnd = $nameStart + \strlen($name[0]);
        // A name too long is refused as one before anything else.
        $longest = GroupNameReader::maxNameLength($this->target);
        if (\strlen($name[0]) > $longest) {
            return $this->state->error(\sprintf('Verb name too long at position %d: PCRE takes at most %d characters.', $nameEnd, $longest), ErrorCode::GroupNameTooLong, $nameEnd);
        }

        if (':' === ($this->pattern[$nameEnd] ?? '')) {
            // Before PCRE2 10.45, a substring scan is a name PCRE does not know.
            $scan = \in_array($name[0], ['scs', 'scan_substring'], true) && $this->target->supports(PcreFeature::ScanSubstring);

            return PcreVerb::takesArgument($name[0]) || $scan
                ? null
                : $this->state->error(\sprintf('Unknown alphabetic assertion "(*%s:" at position %d.', $name[0], $nameEnd), ErrorCode::VerbInvalid, $nameEnd);
        }

        if ($nameEnd >= \strlen($this->pattern) && $this->target->supports(PcreFeature::AlphaNameAtPatternEndIsUnclosed)) {
            return $this->state->error(\sprintf('Missing ")" to close "(*%s" at position %d.', $name[0], $nameEnd), ErrorCode::GroupUnclosed, $nameEnd);
        }

        // Past the whole character in UTF mode.
        $width = $this->state->unicodeMode ? Utf8::width($this->pattern, $nameEnd) : 1;
        $position = $nameEnd >= \strlen($this->pattern) ? $nameEnd : $this->target->faultOffset($nameEnd + $width, $width);

        return $this->state->error(\sprintf('Unknown alphabetic assertion "(*%s" at position %d: a ":" is expected after the name.', $name[0], $position), ErrorCode::VerbInvalid, $position);
    }

    /**
     * Parses what follows the condition of a conditional: the branches, and
     * the closing parenthesis.
     */
    private function readBranches(int $startPosition, NodeInterface $condition): ConditionalNode|DefineNode
    {
        $yes = ($this->branches)();

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

        // With no "|", the second branch is empty where the first one ends:
        // after a "|" it starts past that end.
        $no ??= new LiteralNode('', $yesBranch->getEndPosition(), $yesBranch->getEndPosition());

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
    private function lookaroundCondition(int $startPosition, ?int $faultPosition = null): NodeInterface
    {
        $lookaround = ($this->lookaround)($startPosition, false);
        if (null !== $lookaround) {
            return $lookaround;
        }

        if ($this->stream->matchLiteral('<')) {
            $lookbehind = ($this->lookaround)($startPosition, true);
            if (null !== $lookbehind) {
                return $lookbehind;
            }
        }

        // Past the "?" from PCRE2 10.47, on it before.
        $position = $faultPosition ?? $this->target->faultOffset($startPosition + 1);

        throw $this->state->error(
            \sprintf('Invalid conditional condition at position %d.', $position),
            ErrorCode::ConditionalAssertionExpected,
            $position,
        );
    }

    /**
     * Parses a DEFINE condition in a conditional construct.
     */
    private function defineCondition(int $startPosition): ?AssertionNode
    {
        $savedPos = $this->stream->getPosition();
        $word = '';
        while ($this->state->isLiteralAlphaToken()) {
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
    private function versionCondition(int $startPosition): ?VersionConditionNode
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
    private function numericCondition(int $startPosition, int $groupsBefore): ?BackrefNode
    {
        // "(?(-1)...)" and "(?(+1)...)" count groups from here; the "+"
        // arrives as a quantifier token.
        $sign = '';
        if ($this->stream->checkLiteral('-')
            || ($this->stream->check(TokenType::Quantifier) && '+' === $this->stream->current()->value)) {
            $sign = $this->stream->current()->value;
            $this->stream->advance();

            if (!$this->state->isLiteralDigitToken()) {
                $this->stream->rewind(1);

                return null;
            }
        }

        if (!$this->state->isLiteralDigitToken()) {
            return null;
        }

        $this->stream->advance();
        $num = (string) ($this->stream->previous()->value.$this->state->consumeWhile(
            static fn (string $c): bool => Ascii::isDigit($c),
        ));

        // PCRE refuses a number past 65535 as it reads it, before the ")".
        $position = RepeatCount::tooBigOffset($num, $startPosition + \strlen($sign), $this->target);
        if (null !== $position) {
            throw $this->state->error(\sprintf('Group number %s%s is too big at position %d: PCRE takes at most 65535.', $sign, $num, $position), ErrorCode::GroupNumberTooBig, $position);
        }

        // "(?(+n)" counts the groups before it too: past 65535 in all, PCRE
        // refuses it where the digits end.
        if ('+' === $sign && (int) $num + $groupsBefore > PcreNumber::MAX) {
            $position = $this->stream->current()->position;

            throw $this->state->error(\sprintf('Group number %s%s is too big at position %d: with the groups before it, it goes past 65535.', $sign, $num, $position), ErrorCode::GroupNumberTooBig, $position);
        }

        // "(?(-n)" counts back over the groups opened so far: PCRE refuses a
        // count past them as it reads it, before any later error.
        if ('-' === $sign && (int) $num > $groupsBefore) {
            $position = $this->stream->current()->position;

            throw $this->state->error(\sprintf('Condition relative reference -%s is outside the range of available capture groups.', $num), ErrorCode::BackrefRelative, $position);
        }

        return new BackrefNode($sign.$num, $startPosition, $this->stream->current()->position);
    }

    /**
     * Parses a named condition in a conditional construct.
     */
    private function namedCondition(int $startPosition): ?BackrefNode
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
    private function recursionCondition(int $startPosition): SubroutineNode|BackrefNode|null
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

            // The name is closed by the ")" of the condition.
            if (!$this->stream->check(TokenType::GroupClose)) {
                $position = $this->stream->current()->position;

                throw $this->state->error(\sprintf('Missing ")" to close the condition name at position %d.', $position), ErrorCode::GroupNameUnterminated, $position);
            }

            return new SubroutineNode('R&'.$name, '', $startPosition, $this->stream->previous()->position);
        }

        // Anything else PCRE reads as a name up to the ")": a sign, as in
        // "(?(R-1)", ends it unterminated. "R" and digits alone is the
        // recursion condition; any other name, "Rx" or "R1a", is looked up.
        $nameError = $this->conditionNameError($startPosition);
        if (null !== $nameError) {
            throw $nameError;
        }

        $digits = $this->state->consumeWhile(static fn (string $c): bool => Ascii::isDigit($c));
        if (!$this->stream->check(TokenType::GroupClose)) {
            $this->stream->setPosition($savedPos);

            return null;
        }

        $name = 'R'.$digits;
        if (\in_array($name, $this->patternNames, true)) {
            return new BackrefNode($name, $startPosition, $this->stream->current()->position);
        }
        ($this->recursionTest)($name);

        if ('' !== $digits) {
            $endPosition = $this->stream->previous()->position;
        }

        return new SubroutineNode($name, '', $startPosition, $endPosition);
    }

    /**
     * Parses a bare name condition in a conditional construct.
     */
    private function bareNameCondition(int $startPosition): ?BackrefNode
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
    private function readCondition(int $groupsBefore): NodeInterface
    {
        $startPosition = $this->stream->current()->position;

        // "(?(" the pattern ends in misses its ")".
        if ($this->stream->isAtEnd() && $startPosition >= \strlen($this->pattern)) {
            throw $this->state->error(\sprintf('Missing ")" to close the conditional at position %d.', $startPosition), ErrorCode::GroupUnclosed, $startPosition);
        }

        $condition = $this->defineCondition($startPosition)
            ?? $this->versionCondition($startPosition)
            ?? $this->numericCondition($startPosition, $groupsBefore)
            ?? $this->namedCondition($startPosition)
            ?? $this->recursionCondition($startPosition);

        if (null !== $condition) {
            return $condition;
        }

        if ($this->stream->matchLiteral('?')) {
            return $this->lookaroundCondition($startPosition);
        }

        // "(?(*" the lexer read as no verb, for want of a name or a ")", is
        // no assertion either: refused past the "(", on it before 10.47.
        if ($this->stream->check(TokenType::Quantifier) && '*' === ($this->pattern[$startPosition] ?? '')) {
            throw $this->unclosedVerbConditionError($startPosition - 1, $this->target->faultOffset($startPosition));
        }

        // "(?(VERSION=10z)", or "(?(VERSIONx)": PCRE reads a version
        // condition before a name, and refuses it where it goes wrong.
        $versionError = $this->versionConditionErrorOffset($startPosition);
        if (null !== $versionError) {
            throw $this->versionConditionError($versionError, $startPosition);
        }

        // Under /u no name starts with a digit of any script, as in "(?(٣)":
        // PCRE refuses the digit, past it from PCRE2 10.47.
        if ($this->state->unicodeMode && $this->groupNames->startsWithDigit($startPosition)) {
            $position = $this->groupNames->invalidNameOffset($startPosition);

            throw $this->state->error(
                \sprintf('Invalid condition name at position %d: a group name must not start with a digit.', $position),
                ErrorCode::GroupNameInvalid,
                $position,
            );
        }

        $bareName = $this->bareNameCondition($startPosition);
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

        throw $this->state->error(
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
        if ($nameEnd === $nameStart || $this->groupNames->startsWithDigit($nameStart)) {
            return null;
        }

        $tooLong = $this->groupNames->tooLongAt($nameStart, $nameEnd);
        if (null !== $tooLong) {
            return $this->groupNames->tooLongError($nameStart, $nameEnd, $tooLong);
        }

        if (')' === ($this->pattern[$nameEnd] ?? '')) {
            return null;
        }

        return $this->state->error(
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
        return VersionCondition::errorOffsetFor($this->pattern, $start, $this->target, $this->state->unicodeMode);
    }

    /**
     * @param int $start where the "VERSION" of the condition starts
     */
    private function versionConditionError(int $position, int $start): ParserException
    {
        // Before PCRE2 10.47, what follows the major number where the ")"
        // belongs leaves the condition open.
        if (!$this->target->supports(PcreFeature::VersionConditionLeftOpenIsVersionError) && VersionCondition::isMajorLeftOpenAt($this->pattern, $start, $position)) {
            return $this->state->error(
                \sprintf('Missing ")" to close the condition at position %d.', $position),
                ErrorCode::ConditionalUnclosed,
                $position,
            );
        }

        return $this->state->error(
            \sprintf('Invalid VERSION condition at position %d: PCRE takes "VERSION=" or "VERSION>=", a major number, an optional ".minor", and ")".', $position),
            ErrorCode::ConditionalVersionSyntax,
            $position,
        );
    }
}
