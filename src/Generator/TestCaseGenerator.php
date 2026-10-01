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

namespace PhpRegex\Generator;

use PhpRegex\Parser\AbstractNodeVisitor;
use PhpRegex\Parser\Engine\PcreEngine;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\ClassSetOperationNode;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\ControlCharNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\ExtendedCharClassNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\QuantifierBounds;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\UnicodePropNode;
use PhpRegex\Parser\Node\VersionConditionNode;
use PhpRegex\Parser\Printer\PatternPrinter;

/**
 * Generates test cases (matching and non-matching strings) for a regex pattern.
 *
 * @extends AbstractNodeVisitor<array{matching: array<string>, non_matching: array<string>}>
 */
final class TestCaseGenerator extends AbstractNodeVisitor
{
    private const MAX_SAMPLES = 3;

    /**
     * @param PcreEngine $engine Asks the running engine which characters a class holds
     */
    public function __construct(private readonly PcreEngine $engine = new PcreEngine()) {}

    /**
     * Visits a RegexNode and generates test cases for its pattern.
     *
     * @param RegexNode $node the `RegexNode` representing the entire regular expression
     *
     * @return array{matching: array<string>, non_matching: array<string>} test cases
     */
    #[\Override]
    public function visitRegex(RegexNode $node): array
    {
        return $node->pattern->accept($this);
    }

    /**
     * Visits an AlternationNode and generates test cases from one of its alternatives.
     *
     * @param AlternationNode $node the `AlternationNode` representing a choice between patterns
     *
     * @return array{matching: array<string>, non_matching: array<string>} test cases
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
     * @return array{matching: array<string>, non_matching: array<string>} test cases
     */
    #[\Override]
    public function visitSequence(SequenceNode $node): array
    {
        $matching = [''];
        $nonMatching = [''];

        foreach ($node->children as $child) {
            $childCases = $child->accept($this);
            $newMatching = [];
            $newNonMatching = [];

            foreach ($matching as $m) {
                foreach ($childCases['matching'] as $cm) {
                    $newMatching[] = $m.$cm;
                }
            }

            foreach ($nonMatching as $nm) {
                foreach ($childCases['non_matching'] as $cnm) {
                    $newNonMatching[] = $nm.$cnm;
                }
            }

            $matching = \array_slice($newMatching, 0, self::MAX_SAMPLES);
            $nonMatching = \array_slice($newNonMatching, 0, self::MAX_SAMPLES);
        }

        return [
            'matching' => $matching,
            'non_matching' => $nonMatching,
        ];
    }

    /**
     * Visits a GroupNode and generates test cases from its child.
     *
     * @param GroupNode $node the `GroupNode` representing a grouping construct
     *
     * @return array{matching: array<string>, non_matching: array<string>} test cases
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
     * @return array{matching: array<string>, non_matching: array<string>} test cases
     */
    #[\Override]
    public function visitQuantifier(QuantifierNode $node): array
    {
        $childCases = $node->node->accept($this);
        $range = $this->parseQuantifierRange($node->quantifier);
        $min = $range[0];
        $max = $range[1];

        $matching = [];
        for ($i = $min; $i <= min($max ?? $min + 2, $min + 2); $i++) {
            $sample = str_repeat($childCases['matching'][0] ?? '', $i);
            $matching[] = $sample;
        }

        $nonMatching = [];
        if ($min > 0) {
            // Too few
            $nonMatching[] = str_repeat($childCases['matching'][0] ?? '', $min - 1);
        }
        if (null !== $max) {
            // Too many
            $nonMatching[] = str_repeat($childCases['matching'][0] ?? '', $max + 1);
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
     * @return array{matching: array<string>, non_matching: array<string>} test cases
     */
    #[\Override]
    public function visitLiteral(LiteralNode $node): array
    {
        $value = $node->value;
        $matching = [$value];
        $nonMatching = [];

        // Generate non-matching by changing one character
        if ('' !== $value) {
            $chars = str_split($value);
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
     * @return array{matching: array<string>, non_matching: array<string>} test cases
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
     * @return array{matching: array<string>, non_matching: array<string>} test cases
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
     * @return array{matching: array<string>, non_matching: array<string>} empty test cases
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
     * @return array{matching: array<string>, non_matching: array<string>} empty test cases
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
     * @return array{matching: array<string>, non_matching: array<string>} test cases
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
     * @return array{matching: array<string>, non_matching: array<string>} test cases
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
        $class = "\x01\\A".$node->accept(new PatternPrinter())."\\z\x01";
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
     * Parses a quantifier string into min and max.
     *
     * @param string $q the quantifier
     *
     * @return array{0: int, 1: int|null} min and max
     */
    private function parseQuantifierRange(string $q): array
    {
        $bounds = QuantifierBounds::parse($q);

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
