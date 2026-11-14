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
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;

/**
 * Reads the groups a "(?" opens for TokenParser, by what follows it: the
 * named groups in their four spellings, the lookarounds, "(?:", "(?>",
 * "(?|", and the option settings "(?i)" and "(?i:...)", which change the
 * options of "x", "xx", "n" and "J" the parser reads with. Where PCRE cannot
 * read what follows the "(?", it says where it stops.
 *
 * The alternatives inside a group are read by the parser this reader is
 * given. It shares the parser's ParseState and group names, and reads one
 * text, the pattern or a body read apart.
 *
 * @internal
 */
final class GroupParser
{
    /**
     */
    private TokenStream $stream;
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
     * The marker a lookaround carries in its flags when it is non-atomic,
     * "(?*...)" or "(*napla:...)": PCRE may backtrack into it. It is the
     * character that spells it in the short form.
     */
    public const NON_ATOMIC_FLAG = '*';

    private const INLINE_FLAG_LETTERS = InlineFlags::LETTERS;

    /**
     * The two lookaheads, by the character that introduces them.
     *
     * @var array<string, GroupType>|null
     */
    private static ?array $LOOKAHEADS = null;

    /**
     * @return array<string, GroupType>
     */
    private static function LOOKAHEADS(): array
    {
        return self::$LOOKAHEADS ??= [
            '=' => GroupType::LookaheadPositive(),
            '!' => GroupType::LookaheadNegative(),
        ];
    }

    /**
     * The two lookbehinds, which follow a "<".
     *
     * @var array<string, GroupType>|null
     */
    private static ?array $LOOKBEHINDS = null;

    /**
     * @return array<string, GroupType>
     */
    private static function LOOKBEHINDS(): array
    {
        return self::$LOOKBEHINDS ??= [
            '=' => GroupType::LookbehindPositive(),
            '!' => GroupType::LookbehindNegative(),
        ];
    }

    /**
     */
    private GroupNameReader $groupNames;

    /**
     * @var \Closure(): NodeInterface
     *
     */
    private \Closure $branches;

    /**
     * @var \Closure(): NodeInterface
     *
     */
    private \Closure $resetBranches;

    /**
     * @param \Closure(): NodeInterface $branches      reads the alternatives of a group, up to its ")"
     * @param \Closure(): NodeInterface $resetBranches reads the alternatives of "(?|", up to its ")", each
     *                                                 numbering its groups from the same start
     */
    public function __construct(
        ParseState $state,
        GroupNameReader $groupNames,
        \Closure $branches,
        \Closure $resetBranches
    ) {
        $this->state = $state;
        $this->groupNames = $groupNames;
        $this->branches = $branches;
        $this->resetBranches = $resetBranches;
        $this->stream = $state->stream;
        $this->pattern = $state->pattern;
        $this->target = $state->target;
    }

    /**
     * Parses standard groups like (?<=...), (?<!...), and (?<name>...).
     */
    public function parseStandardGroup(int $startPos): NodeInterface
    {
        // "(?<*...)" is the non-atomic lookbehind; the "*" arrives as a
        // quantifier token. In "(?<*+" or "(?<*?" the "+" or "?" that came
        // with it repeats nothing. Before PCRE2 10.35 the "*" is where a
        // group name is expected.
        if ($this->stream->check(TokenType::Quantifier()) && 0 === strncmp($this->stream->current()->value, '*', strlen('*')) && !$this->supports(PcreFeature::NonAtomicAssertionShortSyntax())) {
            $position = $this->stream->current()->position;

            throw $this->state->error(\sprintf('Expected group name at position %d: PCRE2 10.35 added "(?<*" for "(*naplb:"%s.', $position, $this->supports(PcreFeature::NonAtomicAssertionNames()) ? '; write "(*naplb:" instead' : ''), ErrorCode::GroupNameExpected(), $position);
        }

        if ($this->stream->check(TokenType::Quantifier()) && \in_array($this->stream->current()->value, ['*+', '*?'], true)) {
            $token = $this->stream->current();
            $position = $this->target->faultOffset($token->position + \strlen($token->value));

            throw $this->state->error(\sprintf('Quantifier without target at position %d.', $position), ErrorCode::QuantifierNothingToRepeat(), $position);
        }

        if ($this->stream->check(TokenType::Quantifier()) && '*' === $this->stream->current()->value) {
            $this->stream->advance();
            $expr = ($this->branches)();
            $endToken = $this->stream->consume(TokenType::GroupClose(), 'Expected )', ErrorCode::GroupUnclosed());

            return $this->createGroupNode($expr, GroupType::LookbehindPositive(), $startPos, $endToken, null, self::NON_ATOMIC_FLAG);
        }

        // "(?<=...)" and "(?<!...)" are lookbehinds; anything else after the
        // "<" is the name of a group.
        return $this->matchLookaround($startPos, self::LOOKBEHINDS())
            ?? $this->parseNamedGroup($startPos, true);
    }

    /**
     * Parses simple group modifiers like (:...), (=...), (!...), (>...), (?|...).
     */
    public function parseSimpleGroupModifier(int $startPosition): ?GroupNode
    {
        if ($this->stream->matchLiteral(':')) {
            return $this->parseSimpleGroup($startPosition, GroupType::NonCapturing());
        }

        if ($this->stream->matchLiteral('=')) {
            return $this->parseSimpleGroup($startPosition, GroupType::LookaheadPositive());
        }

        if ($this->stream->matchLiteral('!')) {
            return $this->parseSimpleGroup($startPosition, GroupType::LookaheadNegative());
        }

        if ($this->stream->matchLiteral('>')) {
            return $this->parseSimpleGroup($startPosition, GroupType::Atomic());
        }

        if ($this->stream->match(TokenType::Alternation())) {
            return $this->parseBranchReset($startPosition);
        }

        return null;
    }

    /**
     * Read the lookahead, or with $behind the lookbehind, whose "(?" stands
     * at $startPosition, the "<" of a lookbehind read: null when none
     * follows.
     */
    public function parseLookaround(int $startPosition, bool $behind): ?GroupNode
    {
        return $this->matchLookaround($startPosition, $behind ? self::LOOKBEHINDS() : self::LOOKAHEADS());
    }

    /**
     * Read a named group, whichever of the four ways the pattern names it.
     *
     * @param bool $expectAngle  true for "(?<name>" and "(?P<name>", where a
     *                           ">" closes the name
     * @param bool $pythonSyntax true for the "(?P...)" spellings, which are
     *                           written back out as they were read
     */
    public function parseNamedGroup(int $startPosition, bool $expectAngle, bool $pythonSyntax = false): GroupNode
    {
        $name = $this->groupNames->read(true, ++$this->state->captureCount, !$expectAngle);

        if ($expectAngle) {
            $this->stream->consumeLiteral('>', 'Expected > after group name', ErrorCode::GroupNameUnterminated());
        }

        $expr = ($this->branches)();
        $endToken = $this->stream->consume(TokenType::GroupClose(), 'Expected )', ErrorCode::GroupUnclosed());

        return $this->createGroupNode(
            $expr,
            GroupType::Named(),
            $startPosition,
            $endToken,
            $name,
            null,
            $pythonSyntax,
        );
    }

    /**
     * Parses inline flags and optional sub-expressions (?(?flags:...)).
     */
    public function parseInlineFlags(int $startPosition): NodeInterface
    {
        $flags = $this->readModifierLetters();

        // "(?^" turns every other modifier off already, so PCRE refuses a
        // "-" after it, and reports it past the hyphen (on it before 10.47).
        if (0 === strncmp($flags, '^', strlen('^')) && false !== strpos($flags, '-')) {
            $position = $this->target->faultOffset($startPosition + 2 + (int) strpos($flags, '-') + 1);

            throw $this->state->error(\sprintf('Invalid hyphen in option setting at position %d: "(?^" cannot turn modifiers off.', $position), ErrorCode::GroupOptionHyphen(), $position);
        }

        $letters = self::INLINE_FLAG_LETTERS.($this->supports(PcreFeature::CaselessRestrictModifier()) ? 'r' : '');
        // The ASCII options were only read where PCRE2 takes them.
        $tracked = InlineFlags::withoutAsciiOptions($flags);
        $modifiers = InlineFlags::read($tracked, $letters);

        // "(?)", "(?-)" and "(?-:...)" set nothing, and PCRE takes them as
        // such, as "(?a)" or "(?-aD)" set nothing this library tracks; "(?A:"
        // or "(?{" is no option setting at all.
        $setsNothing = ('' === $flags && $this->stream->check(TokenType::GroupClose()))
            || ('-' === $flags && ($this->stream->check(TokenType::GroupClose()) || $this->stream->checkLiteral(':')))
            || ($tracked !== $flags && \in_array($tracked, ['', '-'], true));

        if (null === $modifiers && !$setsNothing) {
            throw $this->unreadableGroupError($startPosition);
        }

        $wasExtended = $this->state->extendedMode;
        $wasExtendedMore = $this->state->extendedMoreMode;
        $wasNoAutoCapture = $this->state->noAutoCapture;
        $wasAllowingDuplicates = $this->groupNames->duplicatesAllowed();

        if (($nullsafeVariable1 = $modifiers) ? $nullsafeVariable1->turnsOn('J') : null) {
            $this->groupNames->allowDuplicates(true);
        }
        if (($nullsafeVariable2 = $modifiers) ? $nullsafeVariable2->turnsOff('J') : null) {
            $this->groupNames->allowDuplicates(false);
        }

        $this->state->extendedMode = (($nullsafeVariable3 = $modifiers) ? $nullsafeVariable3->inForce('x', $this->state->extendedMode) : null) ?? $this->state->extendedMode;
        $this->state->extendedMoreMode = (($nullsafeVariable4 = $modifiers) ? $nullsafeVariable4->extendedMoreInForce($this->state->extendedMoreMode) : null) ?? $this->state->extendedMoreMode;
        $this->state->noAutoCapture = (($nullsafeVariable5 = $modifiers) ? $nullsafeVariable5->inForce('n', $this->state->noAutoCapture) : null) ?? $this->state->noAutoCapture;

        // "(?iz)": the letters are read as far as PCRE knows them.
        if (!$this->stream->check(TokenType::GroupClose()) && !$this->stream->checkLiteral(':')) {
            throw $this->unreadableGroupError($startPosition);
        }

        $expr = null;
        if ($this->stream->matchLiteral(':')) {
            $expr = ($this->branches)();
            // "(?x:...)" only covers its own group; "(?x)" keeps going.
            $this->state->extendedMode = $wasExtended;
            $this->state->extendedMoreMode = $wasExtendedMore;
            $this->state->noAutoCapture = $wasNoAutoCapture;
            $this->groupNames->allowDuplicates($wasAllowingDuplicates);
        }

        $endToken = $this->stream->consume(TokenType::GroupClose(), 'Expected )', ErrorCode::GroupUnclosed());
        $closeAt = $this->stream->previous()->position;
        $expr ??= new LiteralNode('', $closeAt, $closeAt);

        return $this->createGroupNode(
            $expr,
            GroupType::InlineFlags(),
            $startPosition,
            $endToken,
            null,
            $flags,
        );
    }

    /**
     * Parses "(?|...)", where every branch numbers its groups from the same
     * start, and the group count after it is that of the longest branch.
     */
    private function parseBranchReset(int $startPosition): GroupNode
    {
        $expr = ($this->resetBranches)();
        $endToken = $this->stream->consume(TokenType::GroupClose(), 'Expected )', ErrorCode::GroupUnclosed());

        return $this->createGroupNode($expr, GroupType::BranchReset(), $startPosition, $endToken);
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

        if ($this->stream->check(TokenType::Anchor()) && '^' === $this->stream->current()->value) {
            $letters = '^';
            $this->stream->advance();
        }

        $accepted = self::INLINE_FLAG_LETTERS.($this->supports(PcreFeature::CaselessRestrictModifier()) ? 'r' : '');

        // From PCRE2 10.43, "a" may take one of "D", "S", "W", "P", "T".
        $ascii = $this->supports(PcreFeature::AsciiOptions());
        $afterA = false;

        return $letters.$this->state->consumeWhile(
            static function (string $c) use ($accepted, $ascii, &$afterA): bool {
                if ($afterA && false !== strpos('DSWPT', $c)) {
                    $afterA = false;

                    return true;
                }

                $afterA = $ascii && 'a' === $c;

                return $afterA || '-' === $c || false !== strpos($accepted, $c);
            },
        );
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

        return $this->state->error(\sprintf($message, $position), $code, $position);
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
        $syntax = [ErrorCode::GroupSyntax(), 'Invalid group modifier syntax at position %d.'];
        $unclosed = [ErrorCode::GroupUnclosed(), 'Missing ")" to close the group at position %d.'];

        // Tokens with no pattern text behind them: nothing to read.
        if ($position > $length) {
            return array_merge([$length], $syntax);
        }

        // "(?[" is a Perl extended class, which PCRE2 reads from 10.45 only;
        // before, it refuses the "[".
        if ('[' === $char) {
            return array_merge([$position], $syntax);
        }

        // "(?R" calls the whole pattern, and a ")" has to follow.
        if ('R' === $char) {
            return [$position + 1, ErrorCode::SubroutineInvalidSyntax(), '"(?R" must be followed by ")", at position %d.'];
        }

        // "(?1", "(?-1", "(?+1": a call, which ends after its number, and is
        // refused while its number is read when PCRE cannot take it.
        if (1 === LibraryPcre::match('/\G([+-]?)(\d++)/', $pattern, $matches, 0, $position)) {
            [$number, $sign, $digits] = $matches;
            $end = $position + \strlen($number);
            $tooBig = RepeatCount::tooBigOffset($digits, $position + \strlen($sign), $this->target)
                ?? ('+' === $sign && (int) $digits + $this->state->captureCount > PcreNumber::MAX ? $end : null);

            switch (true) {
                case true === (null !== $tooBig):
                    return [$tooBig, ErrorCode::GroupNumberTooBig(), 'Group number '.$number.' is too big at position %d: PCRE takes at most 65535.'];
                case true === ('' !== $sign && 0 === (int) $digits):
                    return [$end, ErrorCode::SubroutineRelativeZero(), 'Subroutine call relative reference cannot be zero, at position %d.'];
                case true === ('-' === $sign && (int) $digits > $this->state->captureCount):
                    return [$end, ErrorCode::SubroutineRelativeMissing(), 'Subroutine call relative reference '.$number.' points before the first group, at position %d.'];
                default:
                    return [$end, ErrorCode::GroupUnclosed(), 'Missing ")" to close the subroutine call at position %d.'];
            }
        }

        // "(?+" without a number: past the character after the "+", from
        // PCRE2 10.47; on the "+" before.
        if ('+' === $char) {
            $fault = $this->target->faultOffset(min($position + 2, $length), min($position + 2, $length) - $position);

            return $position + 1 >= $length
                ? array_merge([$fault], $unclosed)
                : [$fault, ErrorCode::SubroutineInvalidSyntax(), 'A digit is expected after "(?+", at position %d.'];
        }

        if ('C' === $char) {
            [$fault, $code] = Callout::fault($this->pattern, $start, $this->target) ?? [$start, ErrorCode::GroupSyntax()];

            return [$fault, $code, 'Invalid callout at position %d: '.str_replace('%', '%%', Callout::problem($code)).'.'];
        }

        // Option letters: PCRE stops past the first one it does not know,
        // past a "-" it cannot take, or at the end of the pattern.
        $letters = self::INLINE_FLAG_LETTERS.($this->supports(PcreFeature::CaselessRestrictModifier()) ? 'r' : '');
        $hyphenAllowed = true;
        if ('^' === $char) {
            $hyphenAllowed = false;
            ++$position;
        }

        while ($position < $length && ')' !== $pattern[$position] && ':' !== $pattern[$position]) {
            $char = $pattern[$position++];

            if ('-' === $char) {
                if (!$hyphenAllowed) {
                    return [$this->target->faultOffset($position), ErrorCode::GroupOptionHyphen(), 'Invalid hyphen in option setting at position %d: modifiers are turned off once, after a single "-" that no "^" precedes.'];
                }

                $hyphenAllowed = false;

                continue;
            }

            // An ASCII option, "a", takes at most one class letter along.
            if ('a' === $char && $this->supports(PcreFeature::AsciiOptions())) {
                $position += (int) ($position < $length && false !== strpos('DSWPT', $pattern[$position]));

                continue;
            }

            if (false === strpos($letters, $char)) {
                return array_merge([$this->target->faultOffset($position)], $syntax);
            }
        }

        return $position < $length ? array_merge([$start], $syntax) : array_merge([$length], $unclosed);
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
        bool $usePythonSyntax = false
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
     * Parses a simple group: alternation content followed by closing paren.
     * Used for non-capturing groups, lookaheads, atomic groups, etc.
     */
    private function parseSimpleGroup(int $startPosition, GroupType $type): GroupNode
    {
        $expr = ($this->branches)();
        $endToken = $this->stream->consume(TokenType::GroupClose(), 'Expected )', ErrorCode::GroupUnclosed());

        return $this->createGroupNode($expr, $type, $startPosition, $endToken);
    }

    private function supports(PcreFeature $feature): bool
    {
        return $this->target->supports($feature);
    }
}
