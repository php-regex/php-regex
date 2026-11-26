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

namespace PHPRegex\Automata\Transform;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\Hir\LiteralHir;
use PHPRegex\Parser\Hir\LookHir;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\InlineFlags;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Bytes;

/**
 * The gate in front of the automata solver: the flags it reads, the
 * anchors where they still carry a meaning, and the constructs the
 * normalized form carries that a pure language cannot say.
 *
 * @internal
 */
final class RegularSubsetValidator
{
    public const CRLF_DOT_MESSAGE = 'Under (*CRLF) the dot reads a "\r" alone but not one before a "\n", which the automata solver does not read inside a lookaround.';

    /**
     * Whether the solver can answer for the pattern, and the normalized
     * form it can answer from.
     *
     * @param PcreTarget|null $target the PHP and PCRE2 the pattern is read for,
     *                                the running engine when null
     *
     * @throws ComplexityException
     */
    public function assertSupported(RegexNode $regex, string $pattern, SolverOptions $options, ?PcreTarget $target = null): Hir
    {
        $this->assertSupportedFlags($regex->flags);
        self::assertEmptyMatchesAllowed($regex, $pattern);

        // The language reads a repeat as written, where PCRE2 makes one of
        // the byte 0xFF or of "\w" possessive beside the other as if 0xFF
        // were no word character.
        $tables = $target ?? PcreTarget::runtime();
        $lastByte = AutoPossession::repeatMadePossessiveBesideLastByte($regex, $tables);
        if (null !== $lastByte) {
            throw new ComplexityException(HirToNfaTransformer::LAST_BYTE_MESSAGE, $lastByte->getStartPosition(), $pattern);
        }

        // So does it a repeat of the dot before "\R", and one of "\R" before
        // a dot, as if "\R" took no character the dot takes.
        $besideNewlineSequence = NewlineSequencePossession::repeat($regex);
        if (null !== $besideNewlineSequence) {
            throw new ComplexityException(HirToNfaTransformer::NEWLINE_SEQUENCE_MESSAGE, $besideNewlineSequence->getStartPosition(), $pattern);
        }

        $translator = new HirTranslator($target);
        $hir = $translator->translate($regex);

        // Under "(*CRLF)" a dot is read through a lookahead, for the "\n"
        // of the newline it stops before: inside a lookaround it would be
        // a lookahead inside another, and a lookbehind's would read past
        // where the lookbehind stands.
        $newline = $translator->crLfNewline();
        $dot = null === $newline ? null : self::crLfDot($hir, $newline, false);
        if (null !== $dot) {
            throw new ComplexityException(self::CRLF_DOT_MESSAGE, $dot->startPosition, $pattern);
        }

        // A target that names no locale but C may run under any: without
        // "u" the tables a class is read under past ASCII are not known.
        $committed = (new HirToNfaTransformer($pattern, HirTranslator::unicodeOf($regex), LocaleBoundClasses::of($regex, $tables)))
            ->assertTranslatable($hir, $options);

        // The language reads a proven commitment as written, where PCRE2
        // makes a repeat possessive before a group it may skip.
        $skipped = $committed ? AutoPossession::repeatBeforeSkippableGroup($regex->pattern) : null;
        if (null !== $skipped) {
            throw new ComplexityException(HirToNfaTransformer::SKIPPED_GROUP_MESSAGE, $skipped->getStartPosition(), $pattern);
        }

        return $hir;
    }

    /**
     * "$", "\Z" and the multiline anchors read where lines end, which a start
     * option such as "(*CR)" moves: the match solver reads the newline "\n"
     * only, the automata every convention. The last convention the options
     * name holds.
     *
     * @internal the match solver asks it
     *
     * @throws ComplexityException
     */
    public static function assertNewlineConvention(RegexNode $regex, string $pattern): void
    {
        if ('LF' === StartOptions::newline($regex->source ?? '')) {
            return;
        }

        if (str_contains($regex->flags, 'm') || self::turnsMultilineOn($regex->pattern) || null !== self::lineAnchor($regex->pattern)) {
            throw new ComplexityException('A newline convention other than "\n" moves where "$", "\Z" and the multiline anchors stand, which the automata solver does not read.', 0, $pattern);
        }
    }

    /**
     * "(*NOTEMPTY)" and "(*NOTEMPTY_ATSTART)" refuse an empty match where
     * the engine runs without the JIT; PHP's JIT ignores them, so the answer
     * moves with pcre.jit: "/(*NOTEMPTY)a?/" finds no match in "b" without
     * it and "" with it.
     *
     * @internal the match solver asks it too
     *
     * @throws ComplexityException
     */
    public static function assertEmptyMatchesAllowed(RegexNode $regex, string $pattern): void
    {
        $options = StartOptions::items($regex->source ?? '');
        if (\in_array('NOTEMPTY', $options, true) || \in_array('NOTEMPTY_ATSTART', $options, true)) {
            throw new ComplexityException('(*NOTEMPTY) and (*NOTEMPTY_ATSTART) refuse an empty match without the JIT and are ignored with it, which the automata solver does not read.', 0, $pattern);
        }
    }

    /**
     * The first dot or "\N" the form reads under "(*CRLF)" inside a
     * lookaround, as the lookahead that stands for it: the one whose body
     * is the newline the translator gave every such dot (see
     * HirTranslator::crLfNewline()). A dot that takes every character,
     * under s, is a set like any other, and is not one. Asked as if from
     * inside a lookaround, it gives the first such dot wherever it stands.
     *
     * @internal the match solver asks it for every such dot
     */
    public static function crLfDot(Hir $hir, LiteralHir $newline, bool $inALookaround): ?LookHir
    {
        if ($hir instanceof LookHir) {
            if ($newline === $hir->body) {
                return $inALookaround ? $hir : null;
            }

            $inALookaround = true;
        }

        foreach ($hir->children() as $child) {
            $dot = self::crLfDot($child, $newline, $inALookaround);
            if (null !== $dot) {
                return $dot;
            }
        }

        return null;
    }

    /**
     * @throws ComplexityException
     */
    private function assertSupportedFlags(string $flags): void
    {
        $unsupported = [];
        // Flags the tree already reads, or that change nothing a language says.
        $allowed = ['i', 's', 'u', 'D', 'm', 'x', 'U', 'n', 'J', 'S', 'X', 'r'];
        foreach (Bytes::split($flags) as $flag) {
            if (!\in_array($flag, $allowed, true)) {
                $unsupported[] = $flag;
            }
        }

        if ([] !== $unsupported) {
            throw new ComplexityException('Unsupported regex flags for automata: '.\implode(', ', $unsupported).'.');
        }
    }

    /**
     * Whether a group turns "m" on, as "(?m)" or "(?m:...)" do: "^" then
     * also holds after a newline.
     */
    private static function turnsMultilineOn(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && null !== $node->flags) {
            $flags = InlineFlags::read(InlineFlags::withoutAsciiOptions($node->flags), InlineFlags::LETTERS.'r');
            if (null !== $flags && str_contains($flags->set, 'm')) {
                return true;
            }
        }

        foreach ($node->getChildren() as $child) {
            if (self::turnsMultilineOn($child)) {
                return true;
            }
        }

        return false;
    }

    private static function lineAnchor(NodeInterface $node): AnchorNode|AssertionNode|null
    {
        if (($node instanceof AnchorNode && '$' === $node->value) || ($node instanceof AssertionNode && 'Z' === $node->value)) {
            return $node;
        }

        foreach ($node->getChildren() as $child) {
            $anchor = self::lineAnchor($child);
            if (null !== $anchor) {
                return $anchor;
            }
        }

        return null;
    }
}
