<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser\Analysis;

use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Node\SequenceNode;

/**
 * Collects PCRE-aware group numbering metadata, including branch reset groups.
 */
final class GroupNumberingCollector
{
    private int $nextGroupNumber = 1;

    private int $maxGroupNumber = 0;

    /**
     * @var array<int>
     */
    private array $captureSequence = [];

    /**
     * @var array<string, array<int>>
     */
    private array $namedGroups = [];

    public function collect(RegexNode $node): GroupNumbering
    {
        $this->nextGroupNumber = 1;
        $this->maxGroupNumber = 0;
        $this->captureSequence = [];
        $this->namedGroups = [];

        $this->collectNode($node->pattern);

        foreach ($this->namedGroups as $name => $numbers) {
            $this->namedGroups[$name] = array_values(array_unique($numbers));
        }

        return new GroupNumbering($this->maxGroupNumber, $this->captureSequence, $this->namedGroups);
    }

    private function collectNode(NodeInterface $node): void
    {
        if ($node instanceof GroupNode) {
            if (GroupType::BranchReset === $node->type) {
                $this->collectBranchReset($node);

                return;
            }

            if (GroupType::Capturing === $node->type || GroupType::Named === $node->type) {
                $this->registerCapturingGroup($node);
            }

            $this->collectNode($node->child);

            return;
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alt) {
                $this->collectNode($alt);
            }

            return;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $this->collectNode($child);
            }

            return;
        }

        if ($node instanceof QuantifierNode) {
            $this->collectNode($node->node);

            return;
        }

        if ($node instanceof ConditionalNode) {
            $this->collectNode($node->condition);
            $this->collectNode($node->yes);
            $this->collectNode($node->no);

            return;
        }

        if ($node instanceof DefineNode) {
            $this->collectNode($node->content);

            return;
        }

        if ($node instanceof ScriptRunNode) {
            if (null !== $node->content) {
                $this->collectNode($node->content);
            }

            return;
        }

        if ($node instanceof CharClassNode) {
            $this->collectNode($node->expression);

            return;
        }

        if ($node instanceof RangeNode) {
            $this->collectNode($node->start);
            $this->collectNode($node->end);
        }
    }

    private function registerCapturingGroup(GroupNode $node): void
    {
        $number = $this->nextGroupNumber++;
        $this->captureSequence[] = $number;
        $this->maxGroupNumber = max($this->maxGroupNumber, $number);

        if (GroupType::Named === $node->type && null !== $node->name) {
            $this->namedGroups[$node->name][] = $number;
        }
    }

    private function collectBranchReset(GroupNode $node): void
    {
        $base = $this->nextGroupNumber;
        $maxExtra = 0;

        $alternatives = $node->child instanceof AlternationNode
            ? $node->child->alternatives
            : [$node->child];

        foreach ($alternatives as $alt) {
            $this->nextGroupNumber = $base;
            $this->collectNode($alt);
            $maxExtra = max($maxExtra, $this->nextGroupNumber - $base);
        }

        $this->nextGroupNumber = $base + $maxExtra;
        $this->maxGroupNumber = max($this->maxGroupNumber, $this->nextGroupNumber - 1);
    }
}
