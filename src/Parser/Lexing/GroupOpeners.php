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

namespace PHPRegex\Parser\Lexing;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\Syntax\PcreVerb;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenType;

/**
 * The openers the token regex cannot read at a "(": the body of an
 * alphabetic assertion or a script run, which the lexer reads ahead to its
 * ")" or reads in place, and "(*" or "(?C" that no ")" closes, which the
 * token regex would search the rest of the text for at each one.
 *
 * It reads where the lexer's state stands and moves it, opening on it the
 * group a body read in place stands in; where the bodies end is kept on
 * that state, for the whole pattern.
 *
 * @internal
 */
final class GroupOpeners
{
    /**
     * Reads the body of an alphabetic assertion or a script run the "(" at
     * the cursor opens, "(*pla:" or "(?*": one token with its whole body;
     * its opener alone when the body is read in place or never closes, a
     * token whose value is the opener without its "(" and first character,
     * "pla:" or "*", the body then read as any text is. PCRE reads a body
     * that never closes to the end of the pattern, and stops on the first
     * error in it, or on the ")" missing at the end. False when the "("
     * opens no body; when it opens one that is never read, as a name PCRE
     * refuses, and the body does not close, the "(" is left to the token
     * regex too.
     *
     * @param list<Token> $tokens
     */
    public static function readBody(LexerState $state, array &$tokens): bool
    {
        $next = $state->pattern[$state->position + 1] ?? '';

        return ('*' === $next || '?' === $next)
            && 1 === LibraryPcre::match('/\G\((?:\?\*|\*[a-z_]++:)/', $state->pattern, $opener, 0, $state->position)
            && self::readOpenedBody($state, $opener[0], $tokens);
    }

    /**
     * The opener the token regex reads at the "(" of the cursor, "(" when
     * "*" follows it, "(?" when "C" follows that, if no ")" closes it: "("
     * then "*", or "(?" then text, as the token regex reads them, without
     * searching the rest of the text for a ")" at each one; "(**" that no
     * ")" closes is read so too, "(" then "*". Null when the token regex
     * reads it, "(**" with a ")" after it included. Either way the parser
     * refuses "(**" where PCRE meets it, after what comes before.
     */
    public static function unclosedOpener(LexerState $state): ?string
    {
        $next = $state->pattern[$state->position + 1] ?? '';
        $opener = match (true) {
            '*' === $next => '(',
            '?' === $next && 'C' === ($state->pattern[$state->position + 2] ?? '') => '(?',
            default => null,
        };

        return null === $opener || $state->text->follows(')', $state->position + \strlen($opener) + 1) ? null : $opener;
    }

    /**
     * Reads the alphabetic assertion or script run the cursor opens with
     * $opener, "(*pla:" or "(?*". An opener whose body is never read, as a
     * name PCRE refuses, is left to the token regex when the body does not
     * close.
     *
     * @param list<Token> $tokens
     */
    private static function readOpenedBody(LexerState $state, string $opener, array &$tokens): bool
    {
        $syntax = $state->text->syntax;

        // Before PCRE2 10.35 "(?*" is no group: no option letter is "*".
        if ('(?*' === $opener && !$syntax->readsNonAtomicShortSyntax) {
            $state->refusedTokenStart = $state->position;

            throw LexerException::withContext(
                \sprintf('Invalid group modifier syntax at position %d: PCRE2 10.35 added "(?*" for "(*napla:"%s.', $state->position + 2, $syntax->readsNonAtomicAssertions ? '; write "(*napla:" instead' : ''),
                ErrorCode::GroupSyntax,
                $state->position + 2,
                $state->pattern,
            );
        }

        $name = substr($opener, 2, -1);
        $why = match (true) {
            PcreVerb::isAlphabeticAssertion($name) => $syntax->readsAlphabeticAssertions ? null
                : \sprintf('Unknown alphabetic assertion "(*%s:": PCRE2 10.33 added the alphabetic names of the assertions; write "%s" instead.', $name, PcreVerb::classicOpener($name)),
            PcreVerb::isNonAtomicAssertion($name) => $syntax->readsNonAtomicAssertions ? null
                : \sprintf('Unknown alphabetic assertion "(*%s:": PCRE2 10.34 added the non-atomic lookarounds.', $name),
            PcreVerb::namesScriptRun($name) => $syntax->readsScriptRuns ? null
                : \sprintf('Unknown verb "(*%s:": PCRE2 10.33 added script runs.', $name),
            // Before PCRE2 10.33 no verb has a lowercase name.
            default => $syntax->readsAlphabeticAssertions ? null : \sprintf('Invalid or unsupported PCRE verb: "%s".', $name),
        };
        // Where a name is due, as after "(?<", PCRE wants it on the "(" and
        // reads no verb: the parser refuses it there.
        if (null !== $why && !VerbNames::followsNameOpener($state->text, $state->position)) {
            // Before PCRE2 10.33 no name is alphabetic: as a condition PCRE
            // wants a group name on its "*", elsewhere a verb name ends with
            // its run of letters, and is not one PCRE knows. From 10.33 a
            // name it does not know yet is refused where it ends.
            $start = $state->position;
            $at = VerbNames::nothingReadBefore($state->text, $start, $state->extendedMode);
            $before = array_values(array_filter($tokens, static fn (Token $token): bool => $token->position < $at));
            if (!$syntax->readsAlphabeticAssertions && VerbNames::opensCondition($before, $at)) {
                // After a callout it wants an assertion, on the "(", what it
                // reads as nothing between them aside.
                $last = $before[\count($before) - 1] ?? null;
                if (null !== $last && TokenType::Callout === $last->type) {
                    throw LexerException::withContext(
                        \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after the callout.', $start),
                        ErrorCode::ConditionalAssertionExpected,
                        $start,
                        $state->pattern,
                    );
                }

                if ($at === $start) {
                    throw VerbNames::conditionWantsAName($start + 1, $state->pattern);
                }
            }

            if (!$syntax->namesReadWhole) {
                VerbNames::refuseLongLetterRun($state->text, strspn($name, 'abcdefghijklmnopqrstuvwxyz'), $start);
            }

            throw LexerException::withContext(
                $why,
                ErrorCode::VerbInvalid,
                $start + 2 + ($syntax->readsAlphabeticAssertions ? \strlen($name) : strspn($name, 'abcdefghijklmnopqrstuvwxyz')),
                $state->pattern,
            );
        }

        $inPlace = self::readsBodyInPlace($state, $opener, $tokens);
        $end = $state->bodiesInPlace && $inPlace ? null
            : ($state->bodies ??= new BodyEnds())->assertionEnd($state->text, $state->position, $opener, $state->extendedMode, $state->extendedMoreMode);
        $start = $state->position;
        if (null !== $end) {
            // The body is copied once, into the value of its token: the name
            // is judged on the characters up to the first one no name holds,
            // which are all a verb name is judged on.
            VerbNames::refuse($state->text, substr($state->pattern, $start + 2, strspn($state->pattern, PcreVerb::WORD_NAME, $start + 2) + 1), $start, $tokens, $state->extendedMode);
            $state->position = $end;
            $tokens[] = new Token(TokenType::PcreVerb, substr($state->pattern, $start + 2, $end - $start - 3), $start, $end - $start);

            return true;
        }

        // "(*scs:" that no ")" closes: PCRE reads its list of groups, then
        // its body in place, as the body of any group.
        $opened = $opener;
        if (!$inPlace) {
            $list = self::scanSubstringList($state, $name, $tokens);
            if (null === $list) {
                return false;
            }
            $opened .= $list;
        }

        if (!$state->bodiesInPlace) {
            $state->unclosedBody ??= $opener;
        }

        // The opener opens a group: an option set in the body holds up to
        // its ")".
        $state->extendedModeStack[] = [$state->extendedMode, $state->extendedMoreMode];
        $state->position += \strlen($opened);
        $tokens[] = new Token(TokenType::PcreVerb, substr($opened, 2), $start, \strlen($opened));

        return true;
    }

    /**
     * The list of groups of the "(*scs:" or "(*scan_substring:" the cursor
     * opens, "(1,<n>)", which PCRE reads before the body; null where the
     * name opens no substring scan, as the condition of "(?(" or where a
     * group name is due.
     *
     * @param list<Token> $tokens
     */
    private static function scanSubstringList(LexerState $state, string $name, array $tokens): ?string
    {
        $syntax = $state->text->syntax;
        if (!$syntax->readsScanSubstring || !\in_array($name, ['scs', 'scan_substring'], true)
            || VerbNames::opensCondition($tokens, $state->position) || VerbNames::followsNameOpener($state->text, $state->position)) {
            return null;
        }

        $listOpen = $state->position + 3 + \strlen($name);
        $fault = PcreVerb::groupListFault($state->pattern, $listOpen, $syntax->target, $state->text->utf);
        if (null !== $fault) {
            if (!$syntax->target->supports(PcreFeature::GroupListErrors)) {
                $fault = PcreVerb::groupListFaultBefore1047($fault, $state->length);
            }

            throw LexerException::withContext($fault[2], $fault[1], $fault[0], $state->pattern);
        }

        // A list read without a fault ends at its first ")".
        $listEnd = (int) strpos($state->pattern, ')', $listOpen);

        return substr($state->pattern, $listOpen, $listEnd + 1 - $listOpen);
    }

    /**
     * Whether PCRE reads the body $opener opens at the cursor as the body of
     * a group: the body of an assertion or a script run, but not as the
     * condition of "(?(", which takes a lookahead or lookbehind only, not
     * "(?*" nor "(*atomic:".
     *
     * @param list<Token> $tokens
     */
    private static function readsBodyInPlace(LexerState $state, string $opener, array $tokens): bool
    {
        $name = '(?*' === $opener ? 'napla' : substr($opener, 2, -1);

        return PcreVerb::takesArgument($name)
            && (PcreVerb::isLookaround($name) || !VerbNames::opensCondition($tokens, $state->position));
    }
}
