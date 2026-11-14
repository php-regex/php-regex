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
     *
     */
    public bool $wideRepeatCounts;

    /**
     * Whether "(*scs:" and "(*scan_substring:" are read.
     *
     */
    public bool $readsScanSubstring;

    /**
     * Whether the Perl extended class "(?[...])" is read.
     *
     */
    public bool $readsExtendedClass;

    /**
     * Whether a class opened by "\E" or "\Q\E" alone, at the end of the
     * pattern, is left open; before, PCRE meets a trailing backslash there.
     *
     */
    public bool $emptyQuoteOpeningClassIsUnclosed;

    /**
     * Whether "(*pla:" and the other alphabetic names of the assertions
     * are read; before, they are verbs PCRE does not know.
     *
     */
    public bool $readsAlphabeticAssertions;

    /**
     * Whether a name is read whole before it is judged; before, a verb name
     * is a run of letters, and a name is refused at the character that
     * takes it past 32.
     *
     */
    public bool $namesReadWhole;

    /**
     * Whether "(*sr:" and the other names of a script run are read.
     *
     */
    public bool $readsScriptRuns;

    /**
     * Whether "(*napla:" and the other names of the non-atomic lookarounds
     * are read.
     *
     */
    public bool $readsNonAtomicAssertions;

    /**
     * Whether "(?*" spells a non-atomic lookahead; before, the "*" follows
     * "(?" where no option letter may.
     *
     */
    public bool $readsNonAtomicShortSyntax;

    /**
     * Whether a backslash and digits past 214748363 is an octal escape and
     * text; before, the number is refused at the digit that takes it there.
     *
     */
    public bool $overflowingNumberIsOctal;

    /**
     * The PHP judged.
     *
     */
    public int $phpVersionId;

    /**
     * Whether an escape a class refuses once it has read it, as "[\B]",
     * "[\N]" or the "\d" of "[a-\d]", is read as its letter under
     * PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, as PCRE2 10.32 reads it: the
     * negation of PcreFeature::BadEscapeInClassStaysRefused.
     *
     */
    public bool $classBadEscapeReadAsLetter;
    /**
     */
    public PcreTarget $target;

    /**
     * @param PcreTarget $target the PHP and PCRE2 judged, which say what a bad escape is
     */
    public function __construct(PcreTarget $target)
    {
        $this->target = $target;
        $this->readsAlphabeticAssertions = $target->supports(PcreFeature::AlphabeticAssertionNames());
        $this->namesReadWhole = $target->supports(PcreFeature::NamesReadWhole());
        $this->readsScriptRuns = $target->supports(PcreFeature::ScriptRuns());
        $this->readsNonAtomicAssertions = $target->supports(PcreFeature::NonAtomicAssertionNames());
        $this->readsNonAtomicShortSyntax = $target->supports(PcreFeature::NonAtomicAssertionShortSyntax());
        $this->overflowingNumberIsOctal = $target->supports(PcreFeature::OverflowingBackslashNumberIsOctal());
        $this->phpVersionId = $target->phpVersionId;
        $this->classBadEscapeReadAsLetter = !$target->supports(PcreFeature::BadEscapeInClassStaysRefused());
        $this->wideRepeatCounts = $target->supports(PcreFeature::OpenAndPaddedRepeatCounts());
        $this->readsScanSubstring = $target->supports(PcreFeature::ScanSubstring());
        $this->readsExtendedClass = $target->supports(PcreFeature::ExtendedCharClass());
        $this->emptyQuoteOpeningClassIsUnclosed = $target->supports(PcreFeature::EmptyQuoteOpeningClassIsUnclosed());
    }
}
