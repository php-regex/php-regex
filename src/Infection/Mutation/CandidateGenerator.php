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

namespace PHPRegex\Infection\Mutation;

use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;

/**
 * The mutated patterns a family writes for a pattern, before anything is
 * compiled or proven: each one the original text with one span rewritten,
 * so a pattern laid out under /x keeps its layout. They come in the order of
 * the tree, then in the order of the family's operators; the original and
 * a pattern written twice are left out.
 *
 * @internal
 */
final class CandidateGenerator
{
    /**
     * The bytes PHP skips before the delimiter: those isspace() accepts.
     */
    public const LEADING_SPACE = " \t\n\r\v\f";
    /**
     * The metacharacters RegexEscape takes the backslash from.
     */
    private const UNESCAPED = ['.', '$', '^', '|', '?', '*', '+', '{'];

    /**
     * What a range end may not become inside a class without turning into
     * syntax.
     */
    private const CLASS_SYNTAX = [']', '\\', '^', '-', '['];

    private const SWAPPED_TYPES = ['d' => 'D', 'D' => 'd', 'w' => 'W', 'W' => 'w', 's' => 'S', 'S' => 's'];

    /**
     * The bytes PHP skips among the modifiers.
     */
    private const MODIFIER_SPACE = [' ', "\n", "\r"];

    /**
     * The white space /x lets stand between the parts of a pattern.
     */
    private const EXTENDED_SPACE = " \t\n\r\v\f";

    private readonly RegexParser $parser;

    public function __construct(?RegexParser $parser = null)
    {
        $this->parser = $parser ?? RegexParser::create();
    }

    /**
     * @throws ExceptionInterface when the pattern does not parse
     *
     * @return list<string>
     */
    public function candidates(Family $family, string $pattern): array
    {
        $candidates = [];
        foreach ($this->groups($family, $pattern) as $group) {
            array_push($candidates, ...$group);
        }

        return $candidates;
    }

    /**
     * The candidates grouped by the node they rewrite, in the same order:
     * two mutants of one node are the ones that may turn out alike, as
     * "a+a*" gives "a+a+" and "a+a" from its "*". A pattern written twice
     * stays in the first group that wrote it. Each candidate is written when
     * it is asked for: a long pattern never holds its candidates at once.
     *
     * @throws ExceptionInterface when the pattern does not parse, before the first group
     *
     * @return \Generator<int, \Generator<int, string>>
     */
    public function groups(Family $family, string $pattern): \Generator
    {
        $ast = $this->parser->parse($pattern);
        $source = (string) $ast->source;
        $bodyStart = \strlen($pattern) - \strlen(ltrim($pattern, self::LEADING_SPACE)) + 1;
        $head = substr($pattern, 0, $bodyStart);
        // The parser reads the body after the same white space: the tail
        // is the closing delimiter and the modifiers, as written.
        $tail = substr($pattern, $bodyStart + \strlen($source));

        if (Family::Flag === $family) {
            $removals = [];
            for ($i = 1, $count = \strlen($tail); $i < $count; $i++) {
                if ('u' !== $tail[$i] && !\in_array($tail[$i], self::MODIFIER_SPACE, true)) {
                    $removals[] = [$i, $i + 1, ''];
                }
            }
            $nodes = [$removals];
        } else {
            $nodes = $this->edits($family, $ast, $source);
        }

        // Each pattern written is remembered by its hash, not its text.
        $seen = [hash('xxh128', $pattern) => true];
        foreach ($nodes as $edits) {
            yield (static function () use ($edits, $family, $head, $source, $tail, &$seen): \Generator {
                foreach ($edits as [$start, $end, $text]) {
                    $candidate = Family::Flag === $family
                        ? $head.$source.substr_replace($tail, $text, $start, $end - $start)
                        : $head.substr_replace($source, $text, $start, $end - $start).$tail;
                    $key = hash('xxh128', $candidate);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;

                        yield $candidate;
                    }
                }
            })();
        }
    }

    /**
     * The span rewrites of the family over the body, one list per node, in
     * tree order.
     *
     * @return list<non-empty-list<array{int, int, string}>>
     */
    private function edits(Family $family, RegexNode $ast, string $source): array
    {
        $edits = [];
        $this->walk($ast->pattern, null, false, static function (NodeInterface $node, ?NodeInterface $parent, bool $inClass) use ($family, $source, &$edits): void {
            $nodeEdits = match ($family) {
                Family::Quantifier => self::quantifierEdits($node, $source),
                Family::Anchor => self::anchorEdits($node),
                Family::CharClass => self::classEdits($node, $source),
                Family::Alternation => $parent instanceof CharClassNode ? [] : self::alternationEdits($node, $source),
                Family::Escape => $inClass ? [] : self::escapeEdits($node, $source),
                // Unreachable: groups() writes the flag mutants without
                // walking the tree.
                Family::Flag => [],
            };
            if ([] !== $nodeEdits) {
                $edits[] = $nodeEdits;
            }
        });

        return $edits;
    }

    /**
     * @param \Closure(NodeInterface, ?NodeInterface, bool): void $visit
     */
    private function walk(NodeInterface $node, ?NodeInterface $parent, bool $inClass, \Closure $visit): void
    {
        $visit($node, $parent, $inClass);

        $inClass = $inClass || $node instanceof CharClassNode;
        foreach ($node->getChildren() as $child) {
            $this->walk($child, $node, $inClass, $visit);
        }
    }

    /**
     * @return list<array{int, int, string}>
     */
    private static function quantifierEdits(NodeInterface $node, string $source): array
    {
        if (!$node instanceof QuantifierNode || null === $node->bounds) {
            return [];
        }

        $mode = match ($node->type) {
            QuantifierType::Lazy => '?',
            QuantifierType::Possessive => '+',
            QuantifierType::Greedy => '',
        };
        $base = $node->quantifier;
        $end = $node->getEndPosition();
        $start = $end - \strlen($base) - \strlen($mode);
        // Under /x white space may stand between the atom and its repeat:
        // it stays where it is.
        $gap = substr($source, $node->node->getEndPosition(), $start - $node->node->getEndPosition());
        if (substr($source, $start, $end - $start) !== $base.$mode || '' !== trim($gap, self::EXTENDED_SPACE)) {
            return [];
        }

        $texts = [];
        if ('+' === $base || '*' === $base) {
            $texts[] = ('+' === $base ? '*' : '+').$mode;
        }
        if (str_starts_with($base, '{')) {
            foreach (self::movedBounds($node->bounds, str_contains($base, ',')) as $bounds) {
                $texts[] = $bounds.$mode;
            }
        }
        $texts[] = '';
        $texts[] = QuantifierType::Greedy === $node->type ? $base.'?' : $base;

        return array_map(static fn (string $text): array => [$start, $end, $text], $texts);
    }

    /**
     * The bounds with one of them moved by one, written "{n}" for an exact
     * count, "{m,}" for an open one, "{m,n}" for every other.
     *
     * @return list<string>
     */
    private static function movedBounds(QuantifierBounds $bounds, bool $ranged): array
    {
        $min = $bounds->min;
        $max = $bounds->max;
        $moved = [];
        if (!$ranged) {
            if ($min > 0) {
                $moved[] = '{'.($min - 1).'}';
            }
            $moved[] = '{'.($min + 1).'}';

            return $moved;
        }

        if (null === $max) {
            $moved[] = '{'.($min + 1).',}';
            if ($min > 0) {
                $moved[] = '{'.($min - 1).',}';
            }

            return $moved;
        }

        if ($min < $max) {
            $moved[] = '{'.($min + 1).','.$max.'}';
        }
        if ($min > 0) {
            $moved[] = '{'.($min - 1).','.$max.'}';
        }
        if ($max > $min) {
            $moved[] = '{'.$min.','.($max - 1).'}';
        }
        $moved[] = '{'.$min.','.($max + 1).'}';

        return $moved;
    }

    /**
     * @return list<array{int, int, string}>
     */
    private static function anchorEdits(NodeInterface $node): array
    {
        $start = $node->getStartPosition();
        $end = $node->getEndPosition();

        if ($node instanceof AnchorNode && \in_array($node->value, ['^', '$'], true)) {
            return '$' === $node->value ? [[$start, $end, ''], [$start, $end, '\z']] : [[$start, $end, '']];
        }
        if ($node instanceof AssertionNode && \in_array($node->value, ['A', 'z', 'Z', 'b', 'B'], true)) {
            return 'z' === $node->value ? [[$start, $end, ''], [$start, $end, '$']] : [[$start, $end, '']];
        }

        return [];
    }

    /**
     * @return list<array{int, int, string}>
     */
    private static function classEdits(NodeInterface $node, string $source): array
    {
        $start = $node->getStartPosition();
        $end = $node->getEndPosition();

        if ($node instanceof CharClassNode) {
            if ('[' !== ($source[$start] ?? '')) {
                // Unreachable from a parsed pattern: a class node starts at
                // its bracket. Kept so another spelling is never cut.
                return [];
            }
            $edits = [$node->isNegated ? [$start + 1, $start + 2, ''] : [$start + 1, $start + 1, '^']];
            // A class of one member holds it alone; of more, an alternation.
            if ($node->expression instanceof AlternationNode) {
                foreach ($node->expression->alternatives as $member) {
                    $edits[] = [$member->getStartPosition(), $member->getEndPosition(), ''];
                }
            }

            return $edits;
        }

        if ($node instanceof RangeNode) {
            $low = self::plainCharacter($node->start, $source);
            $high = self::plainCharacter($node->end, $source);
            if (null === $low || null === $high || \ord($low) >= \ord($high)) {
                return [];
            }

            $edits = [];
            $raised = \chr(\ord($low) + 1);
            if (!\in_array($raised, self::CLASS_SYNTAX, true)) {
                $edits[] = [$node->start->getStartPosition(), $node->start->getEndPosition(), $raised];
            }
            $lowered = \chr(\ord($high) - 1);
            if (!\in_array($lowered, self::CLASS_SYNTAX, true)) {
                $edits[] = [$node->end->getStartPosition(), $node->end->getEndPosition(), $lowered];
            }

            return $edits;
        }

        if ($node instanceof CharTypeNode && isset(self::SWAPPED_TYPES[$node->value]) && substr($source, $start, $end - $start) === '\\'.$node->value) {
            return [[$start, $end, '\\'.self::SWAPPED_TYPES[$node->value]]];
        }

        return [];
    }

    /**
     * The character a range end is written as when it is one printable
     * ASCII character, unescaped; null for any other spelling.
     */
    private static function plainCharacter(NodeInterface $end, string $source): ?string
    {
        $text = substr($source, $end->getStartPosition(), $end->getEndPosition() - $end->getStartPosition());

        return $end instanceof LiteralNode && 1 === \strlen($text) && \ord($text) >= 0x20 && \ord($text) < 0x7F ? $text : null;
    }

    /**
     * @return list<array{int, int, string}>
     */
    private static function alternationEdits(NodeInterface $node, string $source): array
    {
        if (!$node instanceof AlternationNode) {
            return [];
        }

        $branches = $node->alternatives;
        $count = \count($branches);
        // The bar between two branches, with what may stand around it and
        // means nothing: the white space of /x, an "\E" closing no "\Q". A
        // swap writes it back as it was.
        $separators = [];
        for ($i = 0; $i < $count - 1; $i++) {
            $separators[$i] = substr($source, $branches[$i]->getEndPosition(), $branches[$i + 1]->getStartPosition() - $branches[$i]->getEndPosition());
            if ('|' !== trim(str_replace(['\Q\E', '\E'], '', $separators[$i]), self::EXTENDED_SPACE)) {
                // Not met by any pattern the tests know: a branch holds its
                // own comments and quoted text. Another spelling is left
                // unmutated rather than cut.
                return [];
            }
        }

        $text = static fn (NodeInterface $branch): string => substr($source, $branch->getStartPosition(), $branch->getEndPosition() - $branch->getStartPosition());

        $edits = [];
        foreach ($branches as $i => $branch) {
            $edits[] = 0 === $i
                ? [$branch->getStartPosition(), $branches[1]->getStartPosition(), '']
                : [$branches[$i - 1]->getEndPosition(), $branch->getEndPosition(), ''];
        }
        for ($i = 0; $i < $count - 1; $i++) {
            $edits[] = [$branches[$i]->getStartPosition(), $branches[$i + 1]->getEndPosition(), $text($branches[$i + 1]).$separators[$i].$text($branches[$i])];
        }

        return $edits;
    }

    /**
     * @return list<array{int, int, string}>
     */
    private static function escapeEdits(NodeInterface $node, string $source): array
    {
        if (!$node instanceof LiteralNode || 2 !== $node->getEndPosition() - $node->getStartPosition()) {
            return [];
        }

        $start = $node->getStartPosition();
        if ('\\'.$node->value !== substr($source, $start, 2) || !\in_array($node->value, self::UNESCAPED, true)) {
            return [];
        }

        return [[$start, $start + 2, $node->value]];
    }
}
