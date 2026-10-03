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

namespace PHPRegex\Parser\Analysis;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Reads, from the tree alone, what a successful preg_match() writes into
 * $matches: which groups every match sets, which some matches leave unset,
 * which none sets, and the strings each can hold.
 *
 * Every answer is sound: when the pattern alone cannot tell, a group may be
 * unset and its values are unknown.
 *
 *     $shape = (new CaptureShapeAnalyzer())->analyze($parser->parse('/(a)(b)?/'));
 *     $shape->matchShape(); // "array{0: 'a'|'ab', 1: 'a', 2?: 'b'}"
 */
final class CaptureShapeAnalyzer
{
    private const MAX_VALUES = 32;

    private const MARK_VERBS = ['MARK', 'PRUNE', 'THEN', 'ACCEPT', 'COMMIT', 'F', 'FAIL'];

    private const TRANSPARENT_GROUPS = [GroupType::NonCapturing, GroupType::Atomic, GroupType::InlineFlags, GroupType::LookaheadPositive, GroupType::LookbehindPositive];

    private const NEGATIVE_LOOKAROUNDS = [GroupType::LookaheadNegative, GroupType::LookbehindNegative];

    private int $next = 1;

    /**
     * @var array<int, list<array{name: ?string, never: bool, values: list<string>|null, min: int, max: int|null}>>
     */
    private array $occurrences = [];

    /**
     * @var list<string>
     */
    private array $marks = [];

    private bool $accepts = false;

    private bool $keeps = false;

    private bool $caseless = false;

    private bool $unicode = false;

    private LengthRangeCalculator $lengths;

    public function __construct()
    {
        $this->lengths = new LengthRangeCalculator();
    }

    public function analyze(RegexNode $regex): CaptureShape
    {
        $this->reset($regex);

        $guaranteed = $this->walk($regex->pattern, false);

        ksort($this->occurrences);
        $groups = [];
        foreach ($this->occurrences as $number => $occurrences) {
            $groups[] = $this->group($number, $occurrences, isset($guaranteed[$number]));
        }

        [$min, $max] = $regex->pattern->accept($this->lengths);
        // \K and (*ACCEPT) cut the whole match short: only its presence is known.
        $exact = !$this->keeps && !$this->accepts;
        $whole = new CaptureGroupShape(0, null, Participation::Always, $exact ? $min : 0, $exact ? $max : null, $exact ? $this->values($regex->pattern) : null);

        return new CaptureShape($whole, $groups, $this->marks);
    }

    private function reset(RegexNode $regex): void
    {
        $this->next = 1;
        $this->occurrences = [];
        $this->marks = [];
        $this->accepts = false;
        $this->keeps = false;
        $this->unicode = $regex->isUnicode();
        $this->caseless = str_contains($regex->flags, 'i') || self::turnsCaselessOn($regex->pattern);
        $this->lengths = new LengthRangeCalculator($this->unicode);
    }

    /**
     * @param list<array{name: ?string, never: bool, values: list<string>|null, min: int, max: int|null}> $occurrences
     */
    private function group(int $number, array $occurrences, bool $guaranteed): CaptureGroupShape
    {
        $set = array_values(array_filter($occurrences, static fn (array $occurrence): bool => !$occurrence['never']));

        $participation = match (true) {
            [] === $set => Participation::Never,
            $guaranteed && !$this->accepts => Participation::Always,
            default => Participation::MayBeUnset,
        };

        $values = [];
        $min = null;
        $max = 0;
        foreach ([] === $set ? $occurrences : $set as $occurrence) {
            $values = null === $values || null === $occurrence['values'] ? null : [...$values, ...$occurrence['values']];
            $min = null === $min ? $occurrence['min'] : min($min, $occurrence['min']);
            $max = null === $max || null === $occurrence['max'] ? null : max($max, $occurrence['max']);
        }

        if (null !== $values) {
            $values = array_values(array_unique($values));
            $values = \count($values) > self::MAX_VALUES ? null : $values;
        }

        if ($this->accepts) {
            // A group (*ACCEPT) leaves open holds what it read so far.
            return new CaptureGroupShape($number, $occurrences[0]['name'], $participation, 0, null, null);
        }

        return new CaptureGroupShape($number, $occurrences[0]['name'], $participation, $min ?? 0, $max, [] === $set ? null : $values);
    }

    /**
     * Walks the tree in the order PCRE numbers groups, and returns the
     * numbers of the groups set whenever the node matches.
     *
     * @return array<int, true>
     */
    private function walk(NodeInterface $node, bool $never): array
    {
        if ($node instanceof PcreVerbNode) {
            $this->readVerb($node);
        }

        if ($node instanceof KeepNode) {
            $this->keeps = true;
        }

        if ($node instanceof DefineNode) {
            $this->walk($node->content, true);
        }

        return match (true) {
            $node instanceof GroupNode => $this->walkGroup($node, $never),
            $node instanceof AlternationNode => self::intersect(array_map(fn (NodeInterface $branch): array => $this->walk($branch, $never), $node->alternatives)),
            $node instanceof SequenceNode => array_replace([], ...array_map(fn (NodeInterface $child): array => $this->walk($child, $never), $node->children)),
            $node instanceof QuantifierNode => $this->walkQuantifier($node, $never),
            $node instanceof ConditionalNode => $this->walkConditional($node, $never),
            $node instanceof ScriptRunNode && null !== $node->content => $this->walk($node->content, $never),
            default => [],
        };
    }

    /**
     * @return array<int, true>
     */
    private function walkGroup(GroupNode $node, bool $never): array
    {
        if (GroupType::BranchReset === $node->type) {
            // Each branch numbers its groups from the same base.
            $base = $this->next;
            $end = $base;
            $sets = [];
            foreach ($node->child instanceof AlternationNode ? $node->child->alternatives : [$node->child] as $branch) {
                $this->next = $base;
                $sets[] = $this->walk($branch, $never);
                $end = max($end, $this->next);
            }
            $this->next = $end;

            return self::intersect($sets);
        }

        if (GroupType::Capturing === $node->type || GroupType::Named === $node->type) {
            $number = $this->next++;
            [$min, $max] = $node->child->accept($this->lengths);
            $this->occurrences[$number][] = [
                'name' => $node->name,
                'never' => $never,
                'values' => $this->values($node->child),
                'min' => $min,
                'max' => $max,
            ];

            return [$number => true] + $this->walk($node->child, $never);
        }

        if (\in_array($node->type, self::NEGATIVE_LOOKAROUNDS, true)) {
            // PCRE discards what a negative assertion captured.
            $this->walk($node->child, true);

            return [];
        }

        $guaranteed = $this->walk($node->child, $never);

        return \in_array($node->type, self::TRANSPARENT_GROUPS, true) ? $guaranteed : [];
    }

    /**
     * @return array<int, true>
     */
    private function walkQuantifier(QuantifierNode $node, bool $never): array
    {
        $bounds = QuantifierBounds::parse($node->quantifier);
        if (null !== $bounds && 0 === $bounds->max) {
            $this->walk($node->node, true);

            return [];
        }

        $guaranteed = $this->walk($node->node, $never);

        return null !== $bounds && $bounds->min > 0 ? $guaranteed : [];
    }

    /**
     * @return array<int, true>
     */
    private function walkConditional(ConditionalNode $node, bool $never): array
    {
        // What the condition captures is set only when it holds.
        $this->walk($node->condition, $never);

        return self::intersect([$this->walk($node->yes, $never), $this->walk($node->no, $never)]);
    }

    private function readVerb(PcreVerbNode $node): void
    {
        [$verb, $name] = array_pad(explode(':', $node->verb, 2), 2, null);
        $verb = '' === $verb ? 'MARK' : $verb;

        if ('ACCEPT' === $verb) {
            $this->accepts = true;
        }

        if (null !== $name && '' !== $name && \in_array($verb, self::MARK_VERBS, true) && !\in_array($name, $this->marks, true)) {
            $this->marks[] = $name;
        }
    }

    /**
     * The strings a node can match, when they are a small finite set read
     * from literals alone.
     *
     * @return list<string>|null
     */
    private function values(NodeInterface $node): ?array
    {
        if ($this->caseless) {
            return null;
        }

        return match (true) {
            $node instanceof LiteralNode => [$node->value],
            $node instanceof CharLiteralNode => $this->character($node->codePoint),
            $node instanceof SequenceNode => $this->product(array_map($this->values(...), $node->children)),
            $node instanceof AlternationNode => $this->union(array_map($this->values(...), $node->alternatives)),
            $node instanceof GroupNode => \in_array($node->type, [...self::NEGATIVE_LOOKAROUNDS, GroupType::LookaheadPositive, GroupType::LookbehindPositive], true) ? [''] : $this->values($node->child),
            $node instanceof QuantifierNode => $this->repeat($node),
            $node instanceof AnchorNode, $node instanceof AssertionNode, $node instanceof CommentNode,
            $node instanceof KeepNode, $node instanceof DefineNode, $node instanceof CalloutNode => [''],
            $node instanceof PcreVerbNode => str_starts_with($node->verb, 'ACCEPT') ? null : [''],
            default => null,
        };
    }

    /**
     * @return list<string>|null
     */
    private function repeat(QuantifierNode $node): ?array
    {
        $bounds = QuantifierBounds::parse($node->quantifier);
        $body = $this->values($node->node);
        if (null === $bounds || null === $body) {
            return null;
        }

        if (0 === $bounds->min && 1 === $bounds->max) {
            return $this->union([[''], $body]);
        }

        if ($bounds->min !== $bounds->max || $bounds->min > 4) {
            return null;
        }

        return $this->product(array_fill(0, $bounds->min, $body));
    }

    /**
     * @return list<string>|null
     */
    private function character(int $codePoint): ?array
    {
        if ($this->unicode) {
            $character = mb_chr($codePoint, 'UTF-8');

            return false === $character ? null : [$character];
        }

        return $codePoint <= 0xFF ? [\chr($codePoint)] : null;
    }

    /**
     * @param array<list<string>|null> $parts
     *
     * @return list<string>|null
     */
    private function product(array $parts): ?array
    {
        $strings = [''];
        foreach ($parts as $part) {
            if (null === $part) {
                return null;
            }

            $next = [];
            foreach ($strings as $prefix) {
                foreach ($part as $suffix) {
                    $next[] = $prefix.$suffix;
                }
            }

            $strings = array_values(array_unique($next));
            if (\count($strings) > self::MAX_VALUES) {
                return null;
            }
        }

        return $strings;
    }

    /**
     * @param array<list<string>|null> $parts
     *
     * @return list<string>|null
     */
    private function union(array $parts): ?array
    {
        $strings = [];
        foreach ($parts as $part) {
            if (null === $part) {
                return null;
            }

            $strings = [...$strings, ...$part];
        }

        $strings = array_values(array_unique($strings));

        return \count($strings) > self::MAX_VALUES ? null : $strings;
    }

    /**
     * @param array<array<int, true>> $sets
     *
     * @return array<int, true>
     */
    private static function intersect(array $sets): array
    {
        return [] === $sets ? [] : array_intersect_key(...$sets);
    }

    private static function turnsCaselessOn(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && GroupType::InlineFlags === $node->type && str_contains(explode('-', $node->flags ?? '')[0], 'i')) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::turnsCaselessOn($child)) {
                return true;
            }
        }

        return false;
    }
}
