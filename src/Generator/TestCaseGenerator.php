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

namespace PHPRegex\Generator;

use PHPRegex\Generator\Exception\SampleGenerationException;
use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\Text\Bytes;

/**
 * Generates test cases (matching and non-matching strings) for a regex pattern.
 *
 * @phpstan-type TestCases array{matching: array<string>, non_matching: array<string>}
 *
 * @extends AbstractNodeVisitor<TestCases>
 */
final class TestCaseGenerator extends AbstractNodeVisitor
{
    private const MAX_SAMPLES = 3;

    /**
     * The longest case built, as long as the longest sample: a longer one
     * is left out before it is allocated.
     */
    private const MAX_CASE_LENGTH = 1048576;

    /**
     * @param PcreEngine $engine Asks the running engine which characters a class holds
     */
    public function __construct(private readonly PcreEngine $engine = new PcreEngine()) {}

    /**
     * Visits a RegexNode and generates test cases for its pattern, each one
     * checked against the running engine: preg_match() gives 1 on every
     * matching case and 0 on every non-matching one. A pattern the engine
     * refuses has no case.
     *
     * @param RegexNode $node the `RegexNode` representing the entire regular expression
     *
     * @return TestCases test cases
     */
    #[\Override]
    public function visitRegex(RegexNode $node): array
    {
        // No case is kept for a pattern the engine refuses: none is built,
        // however long its counts would make each one.
        $pattern = $node->accept(PatternPrinter::asWritten());
        if (null !== $this->engine->compile($pattern)) {
            return ['matching' => [], 'non_matching' => []];
        }

        $candidates = $node->pattern->accept($this);

        $matching = $this->kept($pattern, $candidates['matching'], true);
        if ([] === $matching) {
            // The sample generator reads what the heuristics skip, such as a
            // reference to a group; fixed seeds keep the cases stable.
            $samples = [];
            foreach ([1, 2, 3] as $seed) {
                $generator = new SampleGenerator(engine: $this->engine, seed: $seed);

                try {
                    $samples[] = $node->accept($generator);
                } catch (SampleGenerationException) {
                    break;
                }
            }
            $matching = $this->kept($pattern, [...$matching, ...$samples], true);
        }

        $nonMatching = $this->kept($pattern, $candidates['non_matching'], false);
        if (\count($nonMatching) < self::MAX_SAMPLES) {
            // Strings near the matching ones, each checked the same way.
            $near = [''];
            foreach ($matching as $sample) {
                $near[] = substr($sample, 0, -1);
                $near[] = substr($sample, 1);
            }
            $near[] = "\x00";
            $nonMatching = $this->kept($pattern, [...$nonMatching, ...$near], false);
        }

        return [
            'matching' => $matching,
            'non_matching' => $nonMatching,
        ];
    }

    /**
     * Visits an AlternationNode and generates test cases from one of its alternatives.
     *
     * @param AlternationNode $node the `AlternationNode` representing a choice between patterns
     *
     * @return TestCases test cases
     */
    #[\Override]
    public function visitAlternation(AlternationNode $node): array
    {
        // Choose the first alternative for simplicity
        $cases = $node->alternatives[0]->accept($this);

        // Add non-matching by choosing a different alternative if available
        $nonMatching = $cases['non_matching'];
        if (\count($node->alternatives) > 1) {
            $other = $node->alternatives[1]->accept($this)['matching'];
            $nonMatching = array_merge($nonMatching, \array_slice($other, 0, 1));
        }

        return [
            'matching' => \array_slice($cases['matching'], 0, self::MAX_SAMPLES),
            'non_matching' => \array_slice($nonMatching, 0, self::MAX_SAMPLES),
        ];
    }

    /**
     * Visits a SequenceNode and concatenates test cases from its children.
     *
     * @param SequenceNode $node the `SequenceNode` representing a series of regex components
     *
     * @return TestCases test cases
     */
    #[\Override]
    public function visitSequence(SequenceNode $node): array
    {
        // A case is kept as the pieces its children add and joined once, at
        // the end: a child after a long start copies none of it again.
        $matching = ['lengths' => [0], 'steps' => []];
        $nonMatching = ['lengths' => [0], 'steps' => []];

        foreach ($node->children as $child) {
            $childCases = $child->accept($this);
            self::extend($matching, $childCases['matching']);
            self::extend($nonMatching, $childCases['non_matching']);
        }

        return [
            'matching' => self::joined($matching),
            'non_matching' => self::joined($nonMatching),
        ];
    }

    /**
     * Visits a GroupNode and generates test cases from its child.
     *
     * @param GroupNode $node the `GroupNode` representing a grouping construct
     *
     * @return TestCases test cases
     */
    #[\Override]
    public function visitGroup(GroupNode $node): array
    {
        return $node->child->accept($this);
    }

    /**
     * Visits a QuantifierNode and generates test cases based on repetition.
     *
     * @param QuantifierNode $node the `QuantifierNode` representing a repetition operator
     *
     * @return TestCases test cases
     */
    #[\Override]
    public function visitQuantifier(QuantifierNode $node): array
    {
        $unit = $node->node->accept($this)['matching'][0] ?? '';
        [$min, $max] = $this->parseQuantifierRange($node);
        // No count past the limit builds a case: one more than the limit
        // stands for all of them, and the sums below stay integers.
        $min = min($min, self::MAX_CASE_LENGTH + 1);
        if (null !== $max) {
            $max = min($max, self::MAX_CASE_LENGTH + 1);
        }

        $matching = [];
        for ($i = $min; $i <= min($max ?? $min + 2, $min + 2); $i++) {
            $matching = [...$matching, ...$this->repeated($unit, $i)];
        }

        $nonMatching = [];
        if ($min > 0) {
            // Too few
            $nonMatching = $this->repeated($unit, $min - 1);
        }
        if (null !== $max) {
            // Too many
            $nonMatching = [...$nonMatching, ...$this->repeated($unit, $max + 1)];
        }

        return [
            'matching' => \array_slice($matching, 0, self::MAX_SAMPLES),
            'non_matching' => \array_slice($nonMatching, 0, self::MAX_SAMPLES),
        ];
    }

    /**
     * Visits a LiteralNode and generates test cases.
     *
     * @param LiteralNode $node the `LiteralNode` representing a literal character
     *
     * @return TestCases test cases
     */
    #[\Override]
    public function visitLiteral(LiteralNode $node): array
    {
        $value = $node->value;
        $matching = [$value];
        $nonMatching = [];

        // Generate non-matching by changing one character
        if ('' !== $value) {
            $chars = Bytes::split($value);
            for ($i = 0; $i < min(\strlen($value), 2); $i++) {
                $modified = $chars;
                $modified[$i] = \chr((\ord($chars[$i]) + 1) & 0xFF);
                $nonMatching[] = implode('', $modified);
            }
        }

        return [
            'matching' => $matching,
            'non_matching' => \array_slice($nonMatching, 0, self::MAX_SAMPLES),
        ];
    }

    /**
     * Visits a CharTypeNode and generates test cases.
     *
     * @param CharTypeNode $node the `CharTypeNode` representing a character type
     *
     * @return TestCases test cases
     */
    #[\Override]
    public function visitCharType(CharTypeNode $node): array
    {
        $sample = $this->generateForCharType($node->value);
        $matching = [$sample];
        $nonMatching = ['!']; // Simple non-matching

        return [
            'matching' => $matching,
            'non_matching' => $nonMatching,
        ];
    }

    /**
     * Visits a DotNode and generates test cases.
     *
     * @param DotNode $node the `DotNode` representing the wildcard dot
     *
     * @return TestCases test cases
     */
    #[\Override]
    public function visitDot(DotNode $node): array
    {
        return [
            'matching' => ['a'],
            'non_matching' => ["\n"], // Assuming dot doesn't match newline
        ];
    }

    /**
     * Visits an AnchorNode and returns empty test cases (anchors don't consume).
     *
     * @param AnchorNode $node the `AnchorNode` representing a positional anchor
     *
     * @return TestCases empty test cases
     */
    #[\Override]
    public function visitAnchor(AnchorNode $node): array
    {
        return [
            'matching' => [''],
            'non_matching' => [''],
        ];
    }

    /**
     * Visits an AssertionNode and returns empty test cases.
     *
     * @param AssertionNode $node the `AssertionNode` representing a zero-width assertion
     *
     * @return TestCases empty test cases
     */
    #[\Override]
    public function visitAssertion(AssertionNode $node): array
    {
        return [
            'matching' => [''],
            'non_matching' => [''],
        ];
    }

    /**
     * Visits a CharClassNode and generates test cases.
     *
     * @param CharClassNode $node the `CharClassNode` representing a character class
     *
     * @return TestCases test cases
     */
    #[\Override]
    public function visitCharClass(CharClassNode $node): array
    {
        $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        if (empty($parts)) {
            return [
                'matching' => [],
                'non_matching' => ['a'],
            ];
        }

        $sample = $parts[0]->accept($this)['matching'][0] ?? 'a';
        $matching = [$sample];
        $nonMatching = $node->isNegated ? [] : ['!'];

        return [
            'matching' => $matching,
            'non_matching' => $nonMatching,
        ];
    }

    /**
     * Visits a RangeNode and generates test cases.
     *
     * @param RangeNode $node the `RangeNode` representing a character range
     *
     * @return TestCases test cases
     */
    #[\Override]
    public function visitRange(RangeNode $node): array
    {
        if (!$node->start instanceof LiteralNode || !$node->end instanceof LiteralNode) {
            return [
                'matching' => ['a'],
                'non_matching' => ['!'],
            ];
        }

        $start = $node->start->value;
        $end = $node->end->value;
        $sample = $start;
        $nonMatching = \chr((\ord($end) + 1) & 0xFF);

        return [
            'matching' => [$sample],
            'non_matching' => [$nonMatching],
        ];
    }

    // Other nodes return basic cases
    #[\Override]
    public function visitBackref(BackrefNode $node): array
    {
        return [
            'matching' => [''],
            'non_matching' => ['x'],
        ];
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): array
    {
        $char = $node->codePoint >= 0 && $node->codePoint <= 0xFF
            ? \chr($node->codePoint)
            : (mb_chr($node->codePoint, 'UTF-8') ?: '?');

        return [
            'matching' => [$char],
            'non_matching' => ['!'],
        ];
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): array
    {
        return [
            'matching' => ['a'],
            'non_matching' => ['1'],
        ];
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): array
    {
        return [
            'matching' => ['a'],
            'non_matching' => ['1'],
        ];
    }

    #[\Override]
    public function visitComment(CommentNode $node): array
    {
        return [
            'matching' => [''],
            'non_matching' => [''],
        ];
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): array
    {
        return $node->yes->accept($this);
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): array
    {
        return [
            'matching' => [''],
            'non_matching' => ['x'],
        ];
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): array
    {
        $char = $node->codePoint >= 0 && $node->codePoint <= 0xFF ? \chr($node->codePoint) : '?';

        return [
            'matching' => [$char],
            'non_matching' => ['x'],
        ];
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): array
    {
        // PCRE knows which characters are in the set: ask it, when it reads "(?[".
        $class = "\x01\\A".$node->accept(PatternPrinter::asWritten())."\\z\x01";
        $cases = ['matching' => [], 'non_matching' => []];
        for ($code = 0x20; $code < 0x7F; $code++) {
            $verdict = $this->engine->match($class, \chr($code))->matched;
            if (null === $verdict) {
                return $cases;
            }

            $kind = $verdict ? 'matching' : 'non_matching';
            if (\count($cases[$kind]) < self::MAX_SAMPLES) {
                $cases[$kind][] = \chr($code);
            }
        }

        return $cases;
    }

    /**
     * Best effort: a member of the left operand, or none for a complement.
     */
    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): array
    {
        if (null === $node->left) {
            return ['matching' => [], 'non_matching' => $node->right->accept($this)['matching']];
        }

        return $node->left->accept($this);
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): array
    {
        return [
            'matching' => [''],
            'non_matching' => [],
        ];
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): array
    {
        return [
            'matching' => [''],
            'non_matching' => [],
        ];
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): array
    {
        return [
            'matching' => [''],
            'non_matching' => [''],
        ];
    }

    #[\Override]
    public function visitDefine(DefineNode $node): array
    {
        return [
            'matching' => [''],
            'non_matching' => [''],
        ];
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): array
    {
        return [
            'matching' => [''],
            'non_matching' => [''],
        ];
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): array
    {
        return [
            'matching' => [''],
            'non_matching' => [''],
        ];
    }

    #[\Override]
    public function visitKeep(KeepNode $node): array
    {
        return [
            'matching' => [''],
            'non_matching' => [''],
        ];
    }

    /**
     * Each case followed by each piece, in order: the first three that fit
     * the limit. A step holds, for each of them, the case it follows and the
     * piece it adds.
     *
     * @param array{lengths: list<int>, steps: list<list<array{0: int, 1: string}>>} $cases
     * @param array<string>                                                          $pieces
     */
    private static function extend(array &$cases, array $pieces): void
    {
        $lengths = [];
        $step = [];
        foreach ($cases['lengths'] as $before => $length) {
            foreach ($pieces as $piece) {
                if (\count($step) < self::MAX_SAMPLES && $length + \strlen($piece) <= self::MAX_CASE_LENGTH) {
                    $lengths[] = $length + \strlen($piece);
                    $step[] = [$before, $piece];
                }
            }
        }

        $cases['lengths'] = $lengths;
        $cases['steps'][] = $step;
    }

    /**
     * Each case as a string: its pieces read back from the last step to the
     * first, then joined in order.
     *
     * @param array{lengths: list<int>, steps: list<list<array{0: int, 1: string}>>} $cases
     *
     * @return list<string>
     */
    private static function joined(array $cases): array
    {
        $joined = [];
        foreach (array_keys($cases['lengths']) as $case) {
            $pieces = [];
            for ($step = \count($cases['steps']) - 1; $step >= 0; $step--) {
                [$case, $piece] = $cases['steps'][$step][$case];
                $pieces[] = $piece;
            }

            $joined[] = implode('', array_reverse($pieces));
        }

        return $joined;
    }

    /**
     * The candidates preg_match() matches (or does not, for $matching
     * false), duplicates and those the engine errors on left out; three at
     * most. A pattern the engine refuses keeps none.
     *
     * @param array<string> $candidates
     *
     * @return list<string>
     */
    private function kept(string $pattern, array $candidates, bool $matching): array
    {
        $kept = [];
        foreach (array_unique($candidates) as $candidate) {
            if (\count($kept) < self::MAX_SAMPLES && $matching === $this->engine->match($pattern, $candidate)->matched) {
                $kept[] = $candidate;
            }
        }

        return $kept;
    }

    /**
     * The unit repeated, or nothing when the case would pass the limit.
     *
     * @return list<string>
     */
    private function repeated(string $unit, int $count): array
    {
        if ('' !== $unit && $count > intdiv(self::MAX_CASE_LENGTH, \strlen($unit))) {
            return [];
        }

        return [str_repeat($unit, $count)];
    }

    /**
     * The least and the most a quantifier repeats.
     *
     * @return array{0: int, 1: int|null} min and max
     */
    private function parseQuantifierRange(QuantifierNode $node): array
    {
        $bounds = $node->bounds;

        return null === $bounds ? [1, 1] : [$bounds->min, $bounds->max];
    }

    /**
     * Generates a sample character for a character type.
     *
     * @param string $type the type
     *
     * @return string the sample
     */
    private function generateForCharType(string $type): string
    {
        return match ($type) {
            'd' => '0',
            'D' => 'a',
            's' => ' ',
            'S' => 'a',
            'w' => 'a',
            'W' => '!',
            'h' => ' ',
            'H' => 'a',
            'v' => "\n",
            'V' => 'a',
            'R' => "\r\n",
            default => 'a',
        };
    }
}
