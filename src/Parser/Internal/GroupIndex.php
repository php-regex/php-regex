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

namespace PHPRegex\Parser\Internal;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;

/**
 * The capturing groups of a pattern, numbered as PCRE numbers them, branch
 * resets included, and where each call or reference sits in that count, so
 * "(?-1)" or "\g{+1}" can be resolved to the groups it names.
 *
 * Nodes are keyed by object id: the index holds for the tree it was built
 * from, while that tree lives.
 *
 * @internal
 */
final class GroupIndex
{
    private const GROUP_NAME = '[_\p{L}][_\p{L}\p{Nd}]*+';

    /**
     * @var array<int, list<GroupNode>>
     */
    private array $byNumber = [];

    /**
     * @var array<string, list<GroupNode>>
     */
    private array $byName = [];

    /**
     * @var array<int, int> the number of each capturing group, by node
     */
    private array $numberOf = [];

    /**
     * @var array<int, int> the number the next group would take where each
     *                      call, reference or "(*scs:" sits, by node
     */
    private array $nextNumberAt = [];

    /**
     * @var array<int, int> how many capturing groups open before each call
     *                      or reference, by node
     */
    private array $captureIndexAt = [];

    private int $capturesIndexed = 0;

    /**
     * @var array<int, true> the groups a branch reset holds, by node
     */
    private array $inBranchReset = [];

    private bool $hasBranchReset = false;

    private function __construct() {}

    public static function of(NodeInterface $pattern): self
    {
        $index = new self();
        $nextGroupNumber = 1;
        $index->index($pattern, $nextGroupNumber);

        return $index;
    }

    public static function empty(): self
    {
        return new self();
    }

    public function hasBranchReset(): bool
    {
        return $this->hasBranchReset;
    }

    public function isInBranchReset(GroupNode $group): bool
    {
        return isset($this->inBranchReset[spl_object_id($group)]);
    }

    /**
     * The number the next group would take where the node sits, or null
     * when the node is not a call, a reference or a "(*scs:" of the tree.
     */
    public function nextNumberAt(NodeInterface $node): ?int
    {
        return $this->nextNumberAt[spl_object_id($node)] ?? null;
    }

    /**
     * How many capturing groups open before the call or reference.
     */
    public function captureIndexAt(NodeInterface $node): ?int
    {
        return $this->captureIndexAt[spl_object_id($node)] ?? null;
    }

    /**
     * The numbers of the groups bearing the name, ascending.
     *
     * @return list<int>
     */
    public function numbersNamed(string $name): array
    {
        $numbers = [];
        foreach ($this->byName[$name] ?? [] as $group) {
            $numbers[] = $this->numberOf[spl_object_id($group)];
        }
        // sort() numbers the list anew.
        $numbers = array_unique($numbers);
        sort($numbers);

        return $numbers;
    }

    /**
     * The groups a call runs: the first group bearing its number or name.
     *
     * @return list<GroupNode>
     */
    public function groupsCalledBy(SubroutineNode $node): array
    {
        $reference = $node->reference;

        if (1 === LibraryPcre::match('/^[+-]\d++$/', $reference)) {
            $next = $this->nextNumberAt($node);
            $offset = (int) $reference;

            // "(?+0)" names no group: PCRE refuses it. Every call of a parsed
            // tree is indexed; a node of another tree is not.
            if (null === $next || 0 === $offset) {
                return [];
            }

            // A call measures the first group bearing the number, as PCRE
            // does in a branch reset.
            return \array_slice($this->byNumber[$offset < 0 ? $next + $offset : $next + $offset - 1] ?? [], 0, 1);
        }

        if (1 === LibraryPcre::match('/^\d++$/', $reference)) {
            return \array_slice($this->byNumber[(int) $reference] ?? [], 0, 1);
        }

        // A name, called once whichever group bears it first.
        return \array_slice($this->byName[$reference] ?? [], 0, 1);
    }

    /**
     * The groups a back reference written with a backslash may point to.
     *
     * @return list<GroupNode>
     */
    public function groupsReferencedBy(BackrefNode $node): array
    {
        $ref = $node->ref;

        // "\g'1'" calls the group: the parser makes it no back reference.
        if (1 === LibraryPcre::match('/^\\\\g(?|\{([+-]\d++)\}|([+-]\d++))$/', $ref, $matches)) {
            $next = $this->nextNumberAt($node);
            $offset = (int) $matches[1];

            if (null === $next || 0 === $offset) {
                return [];
            }

            // "-1" is the group before the reference, "+1" the one after it.
            return $this->byNumber[$offset < 0 ? $next + $offset : $next + $offset - 1] ?? [];
        }

        if (1 === LibraryPcre::match('/^\\\\(?|g\{(\d++)\}|g?(\d++))$/', $ref, $matches)) {
            return $this->byNumber[(int) $matches[1]] ?? [];
        }

        if (1 === LibraryPcre::match('/^\\\\k[<{\']('.self::GROUP_NAME.')[>}\']$/u', $ref, $matches)) {
            return $this->byName[$matches[1]] ?? [];
        }

        // Unreachable from a parsed pattern: the parser spells a reference
        // one of the ways above. It guards a hand-built one.
        return [];
    }

    /**
     * Number the capturing groups as PCRE does, branch resets included, and
     * note where each call or reference sits in that count.
     */
    private function index(NodeInterface $node, int &$nextGroupNumber, bool $inBranchReset = false): void
    {
        if ($node instanceof SubroutineNode || $node instanceof BackrefNode) {
            $this->nextNumberAt[spl_object_id($node)] = $nextGroupNumber;
            $this->captureIndexAt[spl_object_id($node)] = $this->capturesIndexed;

            return;
        }

        if ($node instanceof GroupNode && GroupType::BranchReset === $node->type) {
            $this->hasBranchReset = true;
            $base = $nextGroupNumber;
            $highest = $base;
            $branches = $node->child instanceof AlternationNode ? $node->child->alternatives : [$node->child];
            foreach ($branches as $branch) {
                $nextGroupNumber = $base;
                $this->index($branch, $nextGroupNumber, true);
                $highest = max($highest, $nextGroupNumber);
            }
            $nextGroupNumber = $highest;

            return;
        }

        if ($node instanceof GroupNode) {
            // "(*scs:(+1)...)" counts groups from where it stands.
            if (GroupType::ScanSubstring === $node->type) {
                $this->nextNumberAt[spl_object_id($node)] = $nextGroupNumber;
            }

            if (GroupType::Capturing === $node->type || GroupType::Named === $node->type) {
                $this->capturesIndexed++;
                $this->numberOf[spl_object_id($node)] = $nextGroupNumber;
                $this->byNumber[$nextGroupNumber++][] = $node;
                if (null !== $node->name) {
                    $this->byName[$node->name][] = $node;
                }
                if ($inBranchReset) {
                    $this->inBranchReset[spl_object_id($node)] = true;
                }
            }

            $this->index($node->child, $nextGroupNumber, $inBranchReset);

            return;
        }

        $children = match (true) {
            $node instanceof SequenceNode => $node->children,
            $node instanceof AlternationNode => $node->alternatives,
            $node instanceof QuantifierNode => [$node->node],
            $node instanceof ConditionalNode => [$node->condition, $node->yes, $node->no],
            $node instanceof DefineNode => [$node->content],
            $node instanceof ScriptRunNode && null !== $node->content => [$node->content],
            default => [],
        };

        foreach ($children as $child) {
            $this->index($child, $nextGroupNumber, $inBranchReset);
        }
    }
}
