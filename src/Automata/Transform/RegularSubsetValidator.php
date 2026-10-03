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
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * The gate in front of the automata solver: the flags it reads, the
 * anchors where they still carry a meaning, and the constructs the
 * normalized form carries that a pure language cannot say.
 *
 * @internal
 */
final class RegularSubsetValidator
{
    private string $pattern = '';

    /**
     * Whether the solver can answer for the pattern, and the normalized
     * form it can answer from.
     *
     * @throws ComplexityException
     */
    public function assertSupported(RegexNode $regex, string $pattern, SolverOptions $options): Hir
    {
        $this->pattern = $pattern;
        $this->assertSupportedFlags($regex->flags);
        $this->assertAnchorsReadable($regex->pattern, $options);

        $hir = (new HirTranslator())->translate($regex);
        (new HirToNfaTransformer($pattern, HirTranslator::unicodeOf($regex)))
            ->assertTranslatable($hir, $options);

        return $hir;
    }

    /**
     * @throws ComplexityException
     */
    private function assertSupportedFlags(string $flags): void
    {
        $unsupported = [];
        $allowed = ['i', 's', 'u', 'D'];
        foreach (\str_split($flags) as $flag) {
            if (!\in_array($flag, $allowed, true)) {
                $unsupported[] = $flag;
            }
        }

        if ([] !== $unsupported) {
            throw new ComplexityException('Unsupported regex flags for automata: '.\implode(', ', $unsupported).'.');
        }
    }

    /**
     * Anchors compile to nothing, which only tells the truth where they
     * carry no meaning: at the edges of an alternative. A whole-string
     * match starts at the start of the subject and ends at its end, so an
     * anchor at an edge says nothing; anywhere else it changes what the
     * pattern matches — "/a^b/" matches nothing at all — and dropping it
     * would hand back a confidently wrong answer, so the pattern is
     * refused instead.
     *
     * @throws ComplexityException
     */
    private function assertAnchorsReadable(NodeInterface $node, SolverOptions $options): void
    {
        $mode = MatchMode::Full === $options->matchMode ? 'full' : 'partial';
        $alternatives = $node instanceof AlternationNode ? $node->alternatives : [$node];

        foreach ($alternatives as $alternative) {
            $sequence = $alternative instanceof SequenceNode ? $alternative->children : [$alternative];
            if ([] === $sequence) {
                continue;
            }

            $lastIndex = \count($sequence) - 1;
            foreach ($sequence as $index => $child) {
                if ($child instanceof AnchorNode || $child instanceof AssertionNode) {
                    $this->assertAnchorReadable($child, 0 === $index, $lastIndex === $index, $mode);

                    continue;
                }

                $this->assertNoAnchorInside($child, $mode);
            }
        }
    }

    /**
     * @throws ComplexityException
     */
    private function assertAnchorReadable(AnchorNode|AssertionNode $node, bool $atStart, bool $atEnd, string $mode): void
    {
        $value = $node->value;

        if ('^' === $value || '$' === $value) {
            $readable = '^' === $value ? $atStart : $atEnd;
            if (!$readable) {
                throw new ComplexityException(
                    \sprintf('Anchors in %s match mode must appear at the start or end of each alternative.', $mode),
                    $node->getStartPosition(),
                    $this->pattern,
                );
            }

            return;
        }

        // "\A" reads as "^" at the start, "\z" and "\Z" as "$" at the end:
        // the same edges, refused with the ladder's one message elsewhere.
        // Every other condition — "\b", "\B", "\G" — reads where the match
        // stands, wherever it is written.
        $readable = match ($value) {
            'A' => $atStart,
            'z', 'Z' => $atEnd,
            default => false,
        };

        if (!$readable) {
            throw new ComplexityException(
                HirToNfaTransformer::ASSERTION_MESSAGE,
                $node->getStartPosition(),
                $this->pattern,
            );
        }
    }

    /**
     * An anchor inside a group or under a quantifier follows what the
     * group matched, not the subject: the normalized form flattens the
     * group away, so the check runs before the translation, where the
     * barrier still stands.
     *
     * @throws ComplexityException
     */
    private function assertNoAnchorInside(NodeInterface $node, string $mode): void
    {
        $anchor = $this->anchorInside($node);
        if (null === $anchor) {
            return;
        }

        $message = $anchor instanceof AnchorNode && ('^' === $anchor->value || '$' === $anchor->value)
            ? \sprintf('Nested anchors are not supported in %s match mode.', $mode)
            : HirToNfaTransformer::ASSERTION_MESSAGE;

        throw new ComplexityException($message, $anchor->getStartPosition(), $this->pattern);
    }

    private function anchorInside(NodeInterface $node): AnchorNode|AssertionNode|null
    {
        if ($node instanceof AnchorNode || $node instanceof AssertionNode) {
            return $node;
        }

        foreach ($node->getChildren() as $child) {
            $anchor = $this->anchorInside($child);
            if (null !== $anchor) {
                return $anchor;
            }
        }

        return null;
    }
}
