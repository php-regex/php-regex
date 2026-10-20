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

namespace PHPRegex\Parser\Validation;

use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ClassSetOperator;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\NodeInterface;

/**
 * The length the first pass of PCRE2 10.45 and later counts for an
 * extended class "(?[...])" that holds an operator, or a class in brackets
 * or parentheses; one that holds a single other item is the class of that
 * item.
 *
 * Each operand is compiled as a class, a negation above it turned over
 * into it, and its map lifted out: a match of any character, a map with
 * none past 255 or all of them, each one unit then, or an extended class
 * of the same length as on its own, without its map. Every operand counts,
 * though an operation with every character or none drops it, as does each
 * operation between two that are neither, one unit. The class is then
 * written as what remains, with a map of 32 units where one is needed, and
 * counts the longer of the two: a map of none or all past 255 is a class,
 * a match of any character where it holds every byte too.
 *
 * A measure holds what it counts for one class: each class takes its own.
 *
 * @internal
 */
final class ExtendedClassLength
{
    /**
     * A map of no byte, of every byte, and of the bytes past ASCII.
     */
    public const NO_BYTE = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

    public const EVERY_BYTE = "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF";

    public const PAST_ASCII = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF";

    /**
     * The bytes of the lists the operands keep beside the code.
     */
    public int $charListBytes = 0;

    /**
     * Up to 10.47, the most the class has counted when the lists of one of
     * its operands are checked against the limit, those lists included; 0
     * where it keeps none.
     */
    public int $listsPeak = 0;

    /**
     * Whether the class is written as a match of any character, which a
     * count repeats as it does a type; null where that is not known.
     */
    public ?bool $matchesAnyCharacter = false;

    /**
     * The units counted so far for the operands and operations.
     */
    private int $counted = 0;

    /**
     * Whether the class needs a map.
     */
    private bool $needsMap = false;

    /**
     * Whether the class is read under rules kept to ASCII.
     */
    private bool $restricted = false;

    public function __construct(private readonly ClassLength $classes) {}

    /**
     * The item an extended class of a single item, neither in brackets
     * nor in parentheses, stands for: it is the class of that item.
     */
    public static function singleItem(ExtendedCharClassNode $node): ?NodeInterface
    {
        $expression = $node->expression;
        if ($expression instanceof ClassSetOperationNode || $expression instanceof CharClassNode || '' === $node->text) {
            return null;
        }

        // A single item holds no parenthesis: one around it is one more than
        // the class opens on.
        return substr_count($node->text, '(') > 1 ? null : $expression;
    }

    /**
     * The length of the class in the code, without the lists its operands
     * keep beside it (charListBytes), under rules kept to ASCII or not, or
     * null where it is not known.
     */
    public function of(ExtendedCharClassNode $node, bool $caseless, bool $restricted): ?int
    {
        $this->restricted = $restricted;
        // A class of a single item is that item's; where the text that
        // would tell is not there, neither is the length.
        $expression = $node->expression;
        if (null !== self::singleItem($node) || (!$expression instanceof ClassSetOperationNode && !$expression instanceof CharClassNode && '' === $node->text)) {
            return null;
        }

        $result = $this->operation($expression, false, $caseless);
        if (null === $result) {
            return null;
        }

        // The header, then each operand and operation. Where it is not
        // known whether the class holds every byte, an operand is a map of
        // what a property or the locale holds, which counted 33 units: the
        // class counts as many whatever it is written as, but a count
        // repeats it as a type or as a class.
        $counted = 4 + $this->counted;
        [$kind, $units, $ones, $unknown] = $result;
        if ('any' === $kind && self::EVERY_BYTE === ($ones | $unknown)) {
            $this->matchesAnyCharacter = self::EVERY_BYTE === $ones ? true : null;

            return $counted;
        }

        // A map with none or all past 255 is a class of 33 units.
        if ('any' === $kind || 'none' === $kind) {
            return max($counted, 33);
        }

        // Without a map it is written in no more than was counted.
        return $this->needsMap ? max($counted, ('xclass' === $kind ? $units : 4 + $units) + 32) : $counted;
    }

    /**
     * What an operand or an operation compiles to, negated as $negated
     * says: a match of any character or none past 255 ("any", "none") and
     * the map below 256, an extended class ("xclass"), or operations
     * ("ops"), with their units and the bytes set and those that may be.
     *
     * @return array{0: string, 1: int, 2: string, 3: string}|null
     *
     * @phpstan-impure it counts the units of each operand and operation
     */
    private function operation(NodeInterface $node, bool $negated, bool $caseless): ?array
    {
        if (!$node instanceof ClassSetOperationNode) {
            return $this->operand($node, $negated, $caseless);
        }

        if (null === $node->left) {
            return $this->operation($node->right, !$negated, $caseless);
        }

        // A negated operation is turned over into its operands: !(A | B)
        // as !A & !B, !(A & B) as !A | !B, !(A - B) as !A | B, and !(A ^ B)
        // as !A ^ !B, then turned over once more.
        [$operator, $rightNegated] = match ($node->operator) {
            ClassSetOperator::Union => [$negated ? ClassSetOperator::Intersection : ClassSetOperator::Union, $negated],
            ClassSetOperator::Intersection => [$negated ? ClassSetOperator::Union : ClassSetOperator::Intersection, $negated],
            ClassSetOperator::Difference => [$negated ? ClassSetOperator::Union : ClassSetOperator::Intersection, !$negated],
            default => [ClassSetOperator::SymmetricDifference, $negated],
        };

        $left = $this->operation($node->left, $negated, $caseless);
        $right = null === $left ? null : $this->operation($node->right, $rightNegated, $caseless);
        if (null === $left || null === $right) {
            return null;
        }

        $result = $this->combine($operator, $left, $right);
        if (!$negated || ClassSetOperator::SymmetricDifference !== $operator) {
            return $result;
        }

        [$kind, $units, $ones, $unknown] = $this->turnedOver($result);

        return [$kind, $units, ~($ones | $unknown), $unknown];
    }

    /**
     * @return array{0: string, 1: int, 2: string, 3: string}|null
     *
     * @phpstan-impure it counts the units of each operand and operation
     */
    private function operand(NodeInterface $node, bool $negated, bool $caseless): ?array
    {
        $class = $node instanceof CharClassNode ? $node : new CharClassNode($node, false, $node->getStartPosition(), $node->getEndPosition());
        $operand = $this->classes->operand($class, $negated !== $class->isNegated, $caseless, $this->restricted);
        if (null === $operand) {
            return null;
        }

        [$kind, $units, $map, $ones, $unknown, $properties] = $operand;
        // Up to 10.47 the lists are checked as they are written, with what
        // the class has counted so far, the header and the properties of
        // the operand.
        $lists = $this->classes->charListBytes;
        if ($lists > 0) {
            $this->charListBytes += $lists;
            $this->listsPeak = max($this->listsPeak, $this->counted + 4 + 3 * $properties + $lists);
        }

        // A map of none or all past 255 may need a map too, but counted 33
        // units where it adds one at most to what is written: the class
        // counts no more with that map.
        $this->counted += $units;
        $this->needsMap = $this->needsMap || ('xclass' === $kind && $map);

        // Every character or none past 255 is one unit where it is written,
        // which nothing reads: it is never written in an operation.
        return [match ($kind) {
            'class' => 'none',
            'nclass' => 'any',
            default => $kind,
        }, $units, $ones, $unknown];
    }

    /**
     * Two operands joined: an operation with every character or none
     * keeps one side, or none or every character; any other is an
     * operation of one unit after both.
     *
     * @param array{0: string, 1: int, 2: string, 3: string} $left
     * @param array{0: string, 1: int, 2: string, 3: string} $right
     *
     * @return array{0: string, 1: int, 2: string, 3: string}
     *
     * @phpstan-impure it counts the units of each operand and operation
     */
    private function combine(ClassSetOperator $operator, array $left, array $right): array
    {
        [$leftKind, $leftUnits, $leftOnes, $leftUnknown] = $left;
        [$rightKind, $rightUnits, $rightOnes, $rightUnknown] = $right;
        $unknown = $leftUnknown | $rightUnknown;
        if (ClassSetOperator::Intersection === $operator) {
            $ones = $leftOnes & $rightOnes;
            $unknown = ($leftOnes | $leftUnknown) & ($rightOnes | $rightUnknown) & ~$ones;
            $kept = match (true) {
                'any' === $rightKind, 'none' === $leftKind => $left,
                'any' === $leftKind, 'none' === $rightKind => $right,
                default => null,
            };
        } elseif (ClassSetOperator::Union === $operator) {
            $ones = $leftOnes | $rightOnes;
            $unknown &= ~$ones;
            $kept = match (true) {
                'none' === $rightKind, 'any' === $leftKind => $left,
                'none' === $leftKind, 'any' === $rightKind => $right,
                default => null,
            };
        } else {
            // A symmetric difference with every character turns the other
            // side over, its map left as it is: the map of the whole follows.
            $ones = ($leftOnes ^ $rightOnes) & ~$unknown;
            $kept = match (true) {
                'none' === $rightKind => $left,
                'none' === $leftKind => $right,
                'any' === $rightKind => $this->turnedOver($left),
                'any' === $leftKind => $this->turnedOver($right),
                default => null,
            };
        }

        if (null === $kept) {
            $this->counted++;

            return ['ops', $leftUnits + $rightUnits + 1, $ones, $unknown];
        }

        return [$kept[0], $kept[1], $ones, $unknown];
    }

    /**
     * An operand or operations negated: operations take one unit more,
     * every character and none trade places, an extended class turns over
     * where it is written.
     *
     * @param array{0: string, 1: int, 2: string, 3: string} $operand
     *
     * @return array{0: string, 1: int, 2: string, 3: string}
     *
     * @phpstan-impure it counts the units of each operand and operation
     */
    private function turnedOver(array $operand): array
    {
        [$kind, $units, $ones, $unknown] = $operand;
        if ('ops' === $kind) {
            $this->counted++;
            $units++;
        }

        $kind = match ($kind) {
            'any' => 'none',
            'none' => 'any',
            default => $kind,
        };

        return [$kind, $units, $ones, $unknown];
    }
}
