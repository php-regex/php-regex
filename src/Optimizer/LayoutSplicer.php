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

namespace PHPRegex\Optimizer;

use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\Delimiters;

/**
 * A rewritten tree written into the text of the tree it was rewritten from:
 * the subtrees the rewrite kept are the original's own objects, so only the
 * others are printed again, each over the span of what it replaces. The
 * newlines, indents and comments of a pattern under /x stay as the author
 * laid them out.
 *
 * @internal
 */
final class LayoutSplicer
{
    public function __construct(private readonly RegexParser $parser) {}

    /**
     * The rewrite as the original's text with the changed parts replaced,
     * or null when there is no text to write into or the text does not read
     * back as the rewrite.
     */
    public function splice(RegexNode $original, RegexNode $rewritten): ?string
    {
        if (null === $original->source) {
            return null;
        }

        $edits = [];
        $this->diff($original->pattern, $rewritten->pattern, $original, $edits);

        $body = $original->source;
        // From the last edit back, so that the offsets before it hold.
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$start, $end, $text]) {
            $body = substr_replace($body, $text, $start, $end - $start);
        }

        $spliced = $original->delimiter.$body.Delimiters::closing($original->delimiter).$rewritten->flags;

        try {
            $readBack = $this->parser->parse($spliced);
        } catch (RegexException) {
            return null;
        }

        return self::canonical($readBack) === self::canonical($rewritten) ? $spliced : null;
    }

    /**
     * Collects the edits that turn the text of $before into $after: into the
     * children when both nodes are the same shell, over the whole node
     * otherwise.
     *
     * @param list<array{int, int, string}> $edits
     */
    private function diff(NodeInterface $before, NodeInterface $after, RegexNode $context, array &$edits): void
    {
        if ($before === $after) {
            return;
        }

        $text = $this->print($after, $context);
        if ($this->print($before, $context) === $text) {
            return;
        }

        $inner = [];
        $sameShell = match (true) {
            $before instanceof SequenceNode && $after instanceof SequenceNode => $this->diffList($before->children, $after->children, $context, $inner),
            $before instanceof AlternationNode && $after instanceof AlternationNode => $this->diffList($before->alternatives, $after->alternatives, $context, $inner),
            $before instanceof GroupNode && $after instanceof GroupNode => $before->type === $after->type
                && $before->name === $after->name
                && $before->flags === $after->flags
                && $before->usePythonSyntax === $after->usePythonSyntax
                && $this->diffPairs([$before->child], [$after->child], $context, $inner),
            $before instanceof QuantifierNode && $after instanceof QuantifierNode => $before->quantifier === $after->quantifier
                && $before->type === $after->type
                && $this->diffPairs([$before->node], [$after->node], $context, $inner),
            $before instanceof ConditionalNode && $after instanceof ConditionalNode => $this->diffPairs(
                [$before->condition, $before->yes, $before->no],
                [$after->condition, $after->yes, $after->no],
                $context,
                $inner,
            ),
            default => false,
        };

        if ($sameShell) {
            array_push($edits, ...$inner);

            return;
        }

        $edits[] = [$before->getStartPosition(), $before->getEndPosition(), $text];
    }

    /**
     * Pairs two lists on the nodes the rewrite kept, and rewrites the runs
     * between them: pair by pair when both runs are as long, through
     * diffRun() otherwise. False when a run is new where the text has
     * nothing to mark its place.
     *
     * @param array<NodeInterface>          $before
     * @param array<NodeInterface>          $after
     * @param list<array{int, int, string}> $edits
     */
    private function diffList(array $before, array $after, RegexNode $context, array &$edits): bool
    {
        $before = array_values($before);
        $after = array_values($after);
        $countBefore = \count($before);
        $countAfter = \count($after);
        $i = 0;
        $j = 0;

        while ($i < $countBefore || $j < $countAfter) {
            [$nextI, $nextJ] = self::nextKept($before, $after, $i, $j);
            $runBefore = \array_slice($before, $i, $nextI - $i);
            $runAfter = \array_slice($after, $j, $nextJ - $j);

            if (\count($runBefore) === \count($runAfter)) {
                $this->diffPairs($runBefore, $runAfter, $context, $edits);
            } elseif (!$this->diffRun($runBefore, $runAfter, $context, $edits)) {
                return false;
            }

            $i = $nextI + 1;
            $j = $nextJ + 1;
        }

        return true;
    }

    /**
     * Rewrites a run whose nodes the rewrite merged: a literal it joined
     * ("a", "b" for "ab") is split back into one node per character, so the
     * layout between them is kept and only what changed is written again.
     * When the counts still differ, the run is written again as one span.
     * False when the run is new where the text has nothing to mark its place.
     *
     * @param list<NodeInterface>           $before
     * @param list<NodeInterface>           $after
     * @param list<array{int, int, string}> $edits
     */
    private function diffRun(array $before, array $after, RegexNode $context, array &$edits): bool
    {
        if ([] === $before) {
            return false;
        }

        $split = [];
        foreach ($after as $node) {
            array_push($split, ...self::characters($node));
        }

        if (\count($before) === \count($split)) {
            return $this->diffPairs($before, $split, $context, $edits);
        }

        $text = '';
        foreach ($after as $node) {
            $text .= $this->print($node, $context);
        }
        $edits[] = [$before[0]->getStartPosition(), $before[\count($before) - 1]->getEndPosition(), $text];

        return true;
    }

    /**
     * A literal as one node per character, any other node as itself.
     *
     * @return list<NodeInterface>
     */
    private static function characters(NodeInterface $node): array
    {
        if (!$node instanceof LiteralNode || $node->isRaw) {
            return [$node];
        }

        // As the parser reads them: a valid UTF-8 character whole, any
        // other byte on its own.
        return array_map(
            static fn (string $character): LiteralNode => new LiteralNode($character, $node->startPosition, $node->endPosition),
            mb_str_split($node->value, 1, 'UTF-8'),
        );
    }

    /**
     * @param list<NodeInterface>           $before
     * @param list<NodeInterface>           $after
     * @param list<array{int, int, string}> $edits
     */
    private function diffPairs(array $before, array $after, RegexNode $context, array &$edits): bool
    {
        foreach ($before as $index => $node) {
            $this->diff($node, $after[$index], $context, $edits);
        }

        return true;
    }

    /**
     * Where the next node of $after that the rewrite kept from $before
     * stands in both lists, or both ends when there is none.
     *
     * @param list<NodeInterface> $before
     * @param list<NodeInterface> $after
     *
     * @return array{int, int}
     */
    private static function nextKept(array $before, array $after, int $i, int $j): array
    {
        for ($countAfter = \count($after); $j < $countAfter; $j++) {
            for ($k = $i, $countBefore = \count($before); $k < $countBefore; $k++) {
                if ($before[$k] === $after[$j]) {
                    return [$k, $j];
                }
            }
        }

        return [\count($before), \count($after)];
    }

    /**
     * One node on its own, escaped for the pattern's delimiter and flags.
     */
    private function print(NodeInterface $node, RegexNode $context): string
    {
        $printed = (new RegexNode($node, $context->flags, $context->delimiter, 0, 0))->accept(PatternPrinter::asWritten());
        $closing = Delimiters::closing($context->delimiter).$context->flags;

        return substr($printed, \strlen($context->delimiter), -\strlen($closing));
    }

    /**
     * The form two trees compare in, whatever their spelling or layout.
     */
    private static function canonical(RegexNode $node): string
    {
        return $node->accept(PatternPrinter::normalized());
    }
}
