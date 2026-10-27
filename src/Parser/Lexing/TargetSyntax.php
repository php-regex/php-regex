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

use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;

/**
 * The syntax the PHP and PCRE2 judged read, as the lexer asks it: which
 * names, groups and escapes they know, asked of the target once per lexer
 * rather than at each token.
 *
 * @internal
 */
final class TargetSyntax
{
    /**
     * Whether the pattern is read by PCRE2 10.43 or newer, where "{,2}" and
     * counts padded with spaces, "{ 2 }", repeat. Before, those braces are
     * literal text.
     */
    public readonly bool $wideRepeatCounts;

    /**
     * Whether "(*scs:" and "(*scan_substring:" are read.
     */
    public readonly bool $readsScanSubstring;

    /**
     * Whether the Perl extended class "(?[...])" is read.
     */
    public readonly bool $readsExtendedClass;

    /**
     * Whether a class opened by "\E" or "\Q\E" alone, at the end of the
     * pattern, is left open; before, PCRE meets a trailing backslash there.
     */
    public readonly bool $emptyQuoteOpeningClassIsUnclosed;

    /**
     * Whether "(*pla:" and the other alphabetic names of the assertions
     * are read; before, they are verbs PCRE does not know.
     */
    public readonly bool $readsAlphabeticAssertions;

    /**
     * Whether a name is read whole before it is judged; before, a verb name
     * is a run of letters, and a name is refused at the character that
     * takes it past 32.
     */
    public readonly bool $namesReadWhole;

    /**
     * Whether "(*sr:" and the other names of a script run are read.
     */
    public readonly bool $readsScriptRuns;

    /**
     * Whether "(*napla:" and the other names of the non-atomic lookarounds
     * are read.
     */
    public readonly bool $readsNonAtomicAssertions;

    /**
     * Whether "(?*" spells a non-atomic lookahead; before, the "*" follows
     * "(?" where no option letter may.
     */
    public readonly bool $readsNonAtomicShortSyntax;

    /**
     * Whether a backslash and digits past 214748363 is an octal escape and
     * text; before, the number is refused at the digit that takes it there.
     */
    public readonly bool $overflowingNumberIsOctal;

    /**
     * The PHP judged.
     */
    public readonly int $phpVersionId;

    /**
     * Whether an escape a class refuses once it has read it, as "[\B]",
     * "[\N]" or the "\d" of "[a-\d]", is read as its letter under
     * PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, as PCRE2 10.32 reads it: the
     * negation of PcreFeature::BadEscapeInClassStaysRefused.
     */
    public readonly bool $classBadEscapeReadAsLetter;

    /**
     * @param PcreTarget $target the PHP and PCRE2 judged, which say what a bad escape is
     */
    public function __construct(public readonly PcreTarget $target)
    {
        $this->readsAlphabeticAssertions = $target->supports(PcreFeature::AlphabeticAssertionNames);
        $this->namesReadWhole = $target->supports(PcreFeature::NamesReadWhole);
        $this->readsScriptRuns = $target->supports(PcreFeature::ScriptRuns);
        $this->readsNonAtomicAssertions = $target->supports(PcreFeature::NonAtomicAssertionNames);
        $this->readsNonAtomicShortSyntax = $target->supports(PcreFeature::NonAtomicAssertionShortSyntax);
        $this->overflowingNumberIsOctal = $target->supports(PcreFeature::OverflowingBackslashNumberIsOctal);
        $this->phpVersionId = $target->phpVersionId;
        $this->classBadEscapeReadAsLetter = !$target->supports(PcreFeature::BadEscapeInClassStaysRefused);
        $this->wideRepeatCounts = $target->supports(PcreFeature::OpenAndPaddedRepeatCounts);
        $this->readsScanSubstring = $target->supports(PcreFeature::ScanSubstring);
        $this->readsExtendedClass = $target->supports(PcreFeature::ExtendedCharClass);
        $this->emptyQuoteOpeningClassIsUnclosed = $target->supports(PcreFeature::EmptyQuoteOpeningClassIsUnclosed);
    }
}
