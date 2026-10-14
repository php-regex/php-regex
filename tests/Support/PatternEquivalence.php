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

namespace PHPRegex\Tests\Support;

use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Whether two spellings of a pattern are the same pattern, judged two ways
 * that do not share code with the library's printer.
 *
 * The structure is the tree with what a layout may change taken out:
 * comments, positions, how a run of literal text is split into nodes,
 * nested sequences, and an unquantified "(?:)", which matches the empty
 * string and changes nothing. Flags are compared by the caller: laying a
 * pattern out adds "x", compacting it drops it.
 *
 * The engine run is what PCRE itself does with a subject: every match, every
 * group with its offset, every name, hashed.
 */
final class PatternEquivalence
{
    /**
     * Matches longer than this are not worth a subject.
     */
    private const MAX_SUBJECT_LENGTH = 300;

    public static function structure(RegexNode $regex): mixed
    {
        return self::canonical($regex->pattern, $regex->isUnicode());
    }

    /**
     * What PCRE does with $subject: the number of matches and a hash of each
     * match with its groups, their offsets and their names, or the error.
     */
    public static function engineRun(string $regex, string $subject): string
    {
        $hash = hash_init('md5');
        $result = @preg_replace_callback(
            $regex,
            static function (array $match) use ($hash): string {
                hash_update($hash, serialize($match));

                return '';
            },
            $subject,
            -1,
            $count,
            \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL,
        );

        return null === $result ? 'error '.preg_last_error() : $count.':'.hash_final($hash);
    }

    /**
     * Subjects that exercise $regex: fixed probes for what x would skip,
     * samples the pattern generates with four fixed seeds and variants of
     * them (spaces, halves, doubles), and the literal runs it is written with.
     *
     * @return list<string>
     */
    public static function subjects(RegexNode $regex): array
    {
        $subjects = ['', ' ', '#', "\n", 'a b', 'a#b', "\t"];
        $generator = new SampleGenerator();
        for ($seed = 1; $seed <= 4; $seed++) {
            try {
                $generator->setSeed($seed);
                $sample = $regex->accept($generator);
            } catch (\Throwable) {
                // Some patterns have no sample (an impossible branch, a
                // verb the generator does not follow): the other subjects
                // still exercise them.
                continue;
            }

            if (\strlen($sample) >= self::MAX_SUBJECT_LENGTH) {
                continue;
            }

            array_push(
                $subjects,
                $sample,
                $sample.' ',
                ' '.$sample,
                substr($sample, 0, intdiv(\strlen($sample), 2)),
                str_replace(' ', '', $sample),
                $sample."\n".$sample,
            );
        }

        if (preg_match_all('/[[:alnum:] #\-_.\/:@]{1,12}/', (string) $regex->source, $runs) > 0) {
            foreach (\array_slice($runs[0], 0, 6) as $run) {
                $subjects[] = $run;
                $subjects[] = stripslashes($run);
            }
        }

        return array_values(array_unique($subjects));
    }

    /**
     * "\K" inside a lookaround can end a match before it starts, and PHP
     * itself then fails ("Possible integer overflow in memory allocation"):
     * such a pattern is compared by structure only.
     */
    public static function crashesTheEngine(string $regex): bool
    {
        return str_contains($regex, '\\K')
            && 1 === preg_match('/\(\?<?[=!*]|\(\*(?:positive_|negative_|non_atomic_)?look/', $regex);
    }

    private static function canonical(NodeInterface $node, bool $unicode): mixed
    {
        if ($node instanceof SequenceNode) {
            return self::canonicalSequence($node, $unicode);
        }

        // An unquantified "(?:)" matches the empty string wherever it stands,
        // a whole pattern or a branch as much as an item of a sequence.
        if (self::isEmptyNonCapturingGroup($node)) {
            return ['LiteralNode', false, ''];
        }

        if ($node instanceof CharLiteralNode && ($unicode || $node->codePoint < 256)) {
            return ['LiteralNode', false, $unicode ? mb_chr($node->codePoint, 'UTF-8') : \chr($node->codePoint)];
        }

        if ($node instanceof ControlCharNode) {
            return ['LiteralNode', false, $unicode ? mb_chr($node->codePoint, 'UTF-8') : \chr($node->codePoint)];
        }

        if ($node instanceof LiteralNode) {
            return ['LiteralNode', $node->isRaw, $node->value];
        }

        if ($node instanceof CommentNode) {
            return ['LiteralNode', false, ''];
        }

        $canonical = [(new \ReflectionClass($node))->getShortName()];
        foreach ((new \ReflectionObject($node))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            if ('startPosition' === $name || 'endPosition' === $name) {
                continue;
            }

            $value = $property->getValue($node);
            $canonical[$name] = match (true) {
                $value instanceof NodeInterface => self::canonical($value, $unicode),
                \is_array($value) => array_map(static fn (mixed $item): mixed => $item instanceof NodeInterface ? self::canonical($item, $unicode) : $item, $value),
                $value instanceof \UnitEnum => $value->name,
                default => $value,
            };
        }

        return $canonical;
    }

    private static function canonicalSequence(SequenceNode $sequence, bool $unicode): mixed
    {
        $flat = [];
        $stack = array_reverse($sequence->children);
        while ([] !== $stack) {
            $child = array_pop($stack);
            if ($child instanceof SequenceNode) {
                foreach (array_reverse($child->children) as $grandchild) {
                    $stack[] = $grandchild;
                }

                continue;
            }

            $flat[] = $child;
        }

        $children = [];
        foreach ($flat as $child) {
            if ($child instanceof CommentNode || self::isEmptyNonCapturingGroup($child)) {
                continue;
            }

            $canonical = self::canonical($child, $unicode);
            $last = array_key_last($children);
            $previous = null === $last ? null : $children[$last];
            if (\is_array($canonical) && 'LiteralNode' === $canonical[0] && \is_string($canonical[2])
                && \is_array($previous) && 'LiteralNode' === $previous[0] && \is_string($previous[2])
                && $canonical[1] === $previous[1]) {
                $children[$last] = ['LiteralNode', $previous[1], $previous[2].$canonical[2]];

                continue;
            }

            $children[] = $canonical;
        }

        return match (\count($children)) {
            0 => ['LiteralNode', false, ''],
            1 => $children[0],
            default => ['Sequence', $children],
        };
    }

    private static function isEmptyNonCapturingGroup(NodeInterface $node): bool
    {
        return $node instanceof GroupNode
            && GroupType::NonCapturing === $node->type
            && (($node->child instanceof LiteralNode && '' === $node->child->value)
                || ($node->child instanceof SequenceNode && [] === $node->child->children));
    }
}
