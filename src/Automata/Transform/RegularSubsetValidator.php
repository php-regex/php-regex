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
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\DotNode;
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
    public const CRLF_DOT_MESSAGE = 'Under (*CRLF) the dot reads a "\r" alone but not one before a "\n", which the automata solver does not read.';

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
        self::assertCrLfRead($regex, $pattern);
        self::assertEmptyMatchesAllowed($regex, $pattern);

        $hir = (new HirTranslator($target))->translate($regex);
        // A target that names no locale but C may run under any: without
        // "u" the tables a class is read under past ASCII are not known.
        $committed = (new HirToNfaTransformer($pattern, HirTranslator::unicodeOf($regex), LocaleBoundClasses::of($regex, $target ?? PcreTarget::runtime())))
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
     * only, the automata every convention but "(*CRLF)". The last convention
     * the options name holds.
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
     * The automata read the lines of every convention but "(*CRLF)", under
     * which a line ends at "\r\n" alone, and the dot and "\N" read a "\r"
     * alone but not one before a "\n": "/(*CRLF)a.\n/" finds no match in
     * "a\r\n". The last convention the options name holds.
     *
     * @throws ComplexityException
     */
    private static function assertCrLfRead(RegexNode $regex, string $pattern): void
    {
        if ('CRLF' !== StartOptions::newline($regex->source ?? '')) {
            return;
        }

        $dot = self::dotOrNotNewline($regex->pattern);
        if (null !== $dot) {
            throw new ComplexityException(self::CRLF_DOT_MESSAGE, $dot->getStartPosition(), $pattern);
        }

        self::assertNewlineConvention($regex, $pattern);
    }

    /**
     * The first dot or "\N" under the node.
     */
    private static function dotOrNotNewline(NodeInterface $node): ?NodeInterface
    {
        if ($node instanceof DotNode || ($node instanceof CharTypeNode && 'N' === $node->value)) {
            return $node;
        }

        foreach ($node->getChildren() as $child) {
            $found = self::dotOrNotNewline($child);
            if (null !== $found) {
                return $found;
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
