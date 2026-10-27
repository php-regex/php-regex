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

use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\PatternPrinter;

/**
 * What every part of Rewriter shares about the pattern it rewrites.
 *
 * Rewriter walks the tree; ClassRewrites, RepeatRewrites,
 * AlternationRewrites, PossessiveRewrites and FlagRewrites each rewrite a
 * family of constructs for it. The modes the flags of the pattern set are
 * kept here, read again from each pattern visited whole, with the kinds of
 * rewrite made so far, which every part records, and the printer that
 * spells a node to compare it with another. A tree visited without its
 * pattern reads the modes of no flag.
 *
 * @internal
 */
final class RewriteState
{
    /**
     * Whether a character is a UTF-8 sequence: "u".
     */
    public bool $unicodeMode = false;

    /**
     * Whether "\d", "\s" and "\w" read Unicode properties: "/u", or
     * "(*UCP)".
     */
    public bool $unicodeProperties = false;

    /**
     * Whether "U" makes a plain quantifier lazy and "??" greedy.
     */
    public bool $lazyByDefault = false;

    /**
     * Whether an inline flag stands anywhere in the pattern visited.
     */
    public bool $inlineFlags = false;

    /**
     * The character sets of the nodes, read under the flags of the pattern.
     */
    public CharSetAnalyzer $charSetAnalyzer;

    /**
     * The kinds of rewrite made, in the order first made.
     *
     * @var array<string, true>
     */
    private array $changes = [];

    private ?PatternPrinter $printer = null;

    public function __construct()
    {
        $this->charSetAnalyzer = new CharSetAnalyzer();
    }

    /**
     * Reads the modes of a pattern about to be rewritten, and forgets the
     * rewrites made on the one before.
     *
     * @param bool $inlineFlags whether an inline flag stands anywhere in it
     */
    public function start(RegexNode $node, bool $inlineFlags): void
    {
        $this->unicodeMode = str_contains($node->flags, 'u');
        $this->unicodeProperties = UnicodeProperties::on($node);
        $this->charSetAnalyzer = new CharSetAnalyzer($node->flags);
        $this->lazyByDefault = str_contains($node->flags, 'U');
        $this->inlineFlags = $inlineFlags;
        $this->changes = [];
    }

    /**
     * Records a kind of rewrite made.
     */
    public function changed(string $change): void
    {
        $this->changes[$change] = true;
    }

    /**
     * The kinds of rewrite made, each once, in the order first made.
     *
     * @return list<string>
     */
    public function changes(): array
    {
        return array_keys($this->changes);
    }

    /**
     * The node as the pattern writes it, to tell two nodes apart.
     */
    public function spelling(NodeInterface $node): string
    {
        $this->printer ??= PatternPrinter::asWritten();
        $this->printer->resetState();

        return $node->accept($this->printer);
    }
}
