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
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;

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
        $this->assertNewlineConvention($regex);

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
        // Flags the tree already reads, or that change nothing a language says.
        $allowed = ['i', 's', 'u', 'D', 'm', 'x', 'U', 'n', 'J', 'S', 'X', 'r'];
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
     * "$", "\Z" and the multiline anchors read where lines end, which a start
     * option such as "(*CRLF)" moves: the solver reads the newline "\n" only.
     *
     * @throws ComplexityException
     */
    private function assertNewlineConvention(RegexNode $regex): void
    {
        $source = $regex->source ?? '';
        if (1 !== LibraryPcre::match('/\A(?:\(\*[A-Z_]++(?:=\d++)?\))*?\(\*(?:CR|CRLF|ANYCRLF|ANY|NUL)\)/', $source)) {
            return;
        }

        if (str_contains($regex->flags, 'm') || null !== self::lineAnchor($regex->pattern)) {
            throw new ComplexityException('A newline convention other than "\n" moves where "$", "\Z" and the multiline anchors stand, which the automata solver does not read.', 0, $this->pattern);
        }
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
