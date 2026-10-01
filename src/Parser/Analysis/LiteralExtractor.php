<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser\Analysis;

use PhpRegex\Parser\AbstractNodeVisitor;
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
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
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

/**
 * Extracts literal strings that must appear in any match.
 *
 * @extends AbstractNodeVisitor<LiteralSet>
 */
final class LiteralExtractor extends AbstractNodeVisitor
{
    /**
     * Maximum number of literals generated to prevent explosion (e.g. [a-z]{10}).
     */
    private const MAX_LITERALS_COUNT = 128;

    private const LOOKAROUNDS = [
        GroupType::LookaheadPositive,
        GroupType::LookaheadNegative,
        GroupType::LookbehindPositive,
        GroupType::LookbehindNegative,
        GroupType::ScanSubstring,
    ];

    private bool $caseInsensitive = false;

    private bool $unicode = false;

    #[\Override]
    public function visitRegex(RegexNode $node): LiteralSet
    {
        $this->caseInsensitive = str_contains($node->flags, 'i');
        $this->unicode = str_contains($node->flags, 'u')
            || 1 === preg_match('/^(?:\(\*[A-Z_=0-9]+\))*\(\*(?:UTF|UCP)\)/', $node->source ?? '');

        return $node->pattern->accept($this);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): LiteralSet
    {
        $result = null;

        foreach ($node->alternatives as $alt) {
            /** @var \PhpRegex\Parser\Analysis\LiteralSet $altSet */
            $altSet = $alt->accept($this);

            if (null === $result) {
                $result = $altSet;
            } else {
                $result = $result->unite($altSet);
            }

            // Safety valve for memory
            if (\count($result->prefixes) > self::MAX_LITERALS_COUNT) {
                return LiteralSet::empty();
            }
        }

        return $result ?? LiteralSet::empty();
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): LiteralSet
    {
        $result = LiteralSet::fromString(''); // Start with empty complete string
        $accepted = false;

        foreach ($node->children as $child) {
            /** @var \PhpRegex\Parser\Analysis\LiteralSet $childSet */
            $childSet = $child->accept($this);
            $result = $result->concat($childSet);
            $accepted = $accepted || $this->mayAccept($child);

            // Safety valve
            if (\count($result->prefixes) > self::MAX_LITERALS_COUNT) {
                return LiteralSet::empty();
            }
        }

        // A "(*ACCEPT)" may end the match before the end of the sequence.
        return $accepted ? new LiteralSet($result->prefixes, [], false) : $result;
    }

    #[\Override]
    public function visitGroup(GroupNode $node): LiteralSet
    {
        // A lookaround looks at the subject without consuming it.
        if (\in_array($node->type, self::LOOKAROUNDS, true)) {
            return LiteralSet::fromString('');
        }

        $previousState = $this->caseInsensitive;
        $caseInsensitive = $this->caseInsensitiveAfter($node->flags ?? '');

        // "(?i)" holds to the end of the group it stands in, so it is not
        // undone here; "(?i:...)" holds for its own body. Reading an empty
        // "(?i:)" as the first is only ever more cautious.
        if (GroupType::InlineFlags === $node->type && $node->child instanceof LiteralNode && '' === $node->child->value) {
            $this->caseInsensitive = $caseInsensitive;

            return LiteralSet::fromString('');
        }

        $this->caseInsensitive = $caseInsensitive;

        /** @var \PhpRegex\Parser\Analysis\LiteralSet $result */
        $result = $node->child->accept($this);

        // Restore state
        $this->caseInsensitive = $previousState;

        return $result;
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): LiteralSet
    {
        $bounds = QuantifierBounds::parse($node->quantifier);

        // Case 1: Exact quantifier {n} -> repeat literals n times
        if (null !== $bounds && $bounds->min === $bounds->max) {
            $count = $bounds->min;
            if (0 === $count) {
                return LiteralSet::fromString(''); // Matches empty string
            }

            /** @var \PhpRegex\Parser\Analysis\LiteralSet $childSet */
            $childSet = $node->node->accept($this);

            // Repeat concatenation
            $result = $childSet;
            for ($i = 1; $i < $count; $i++) {
                $result = $result->concat($childSet);
                if (\count($result->prefixes) > self::MAX_LITERALS_COUNT) {
                    return LiteralSet::empty();
                }
            }

            return $result;
        }

        // Case 2: + or {n,} or {n,m} with n >= 1 (At least 1)
        // We can extract the literal from the node, but it's not complete anymore because of the tail
        if (null !== $bounds && $bounds->min >= 1) {
            /** @var \PhpRegex\Parser\Analysis\LiteralSet $childSet */
            $childSet = $node->node->accept($this);

            // The literal is present at least once, but followed by unknown quantity.
            // So suffixes are lost, completeness is lost.
            return new LiteralSet($childSet->prefixes, [], false);
        }

        // Case 3: * or ? (Optional)
        // Cannot guarantee presence.
        return LiteralSet::empty();
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): LiteralSet
    {
        if ($this->caseInsensitive) {
            return $this->expandCaseInsensitive($node->value);
        }

        return LiteralSet::fromString($node->value);
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): LiteralSet
    {
        $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        // Optimization: Single character class [a] is literal 'a'
        if (!$node->isNegated && 1 === \count($parts) && $parts[0] instanceof LiteralNode) {
            return $this->visitLiteral($parts[0]);
        }

        // [abc] is effectively an alternation a|b|c
        // We only handle simple literals inside char classes for now to avoid complexity
        if (!$node->isNegated) {
            $literals = [];
            foreach ($parts as $part) {
                if ($part instanceof LiteralNode) {
                    if ($this->caseInsensitive) {
                        // A member whose variants are unknown makes the class unknown.
                        $expanded = $this->expandCaseInsensitive($part->value);
                        if ([] === $expanded->prefixes) {
                            return LiteralSet::empty();
                        }
                        array_push($literals, ...$expanded->prefixes);
                    } else {
                        $literals[] = $part->value;
                    }
                } else {
                    // Range, char type, etc. -> considered non-literal for simplicity
                    return LiteralSet::empty();
                }
            }

            return new LiteralSet($literals, $literals, true); // Complete single char match
        }

        return LiteralSet::empty();
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): LiteralSet
    {
        return LiteralSet::empty();
    }

    #[\Override]
    public function visitDot(DotNode $node): LiteralSet
    {
        return LiteralSet::empty();
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): LiteralSet
    {
        // Anchors match empty strings, so they are "complete" empty matches
        // This allows /^abc/ to return prefix 'abc'
        return LiteralSet::fromString('');
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): LiteralSet
    {
        return LiteralSet::fromString('');
    }

    #[\Override]
    public function visitKeep(KeepNode $node): LiteralSet
    {
        return LiteralSet::fromString('');
    }

    #[\Override]
    public function visitRange(RangeNode $node): LiteralSet
    {
        return LiteralSet::empty();
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): LiteralSet
    {
        return LiteralSet::empty();
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): LiteralSet
    {
        return LiteralSet::empty();
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): LiteralSet
    {
        return LiteralSet::empty();
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): LiteralSet
    {
        return LiteralSet::empty();
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): LiteralSet
    {
        return LiteralSet::empty();
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): LiteralSet
    {
        return LiteralSet::empty();
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): LiteralSet
    {
        return LiteralSet::empty();
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): LiteralSet
    {
        // A script run matches what it holds, from one script: the same literals.
        return null === $node->content ? LiteralSet::empty() : $node->content->accept($this);
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): LiteralSet
    {
        return LiteralSet::empty();
    }

    #[\Override]
    public function visitComment(CommentNode $node): LiteralSet
    {
        return LiteralSet::fromString('');
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): LiteralSet
    {
        return LiteralSet::empty();
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): LiteralSet
    {
        return LiteralSet::empty();
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): LiteralSet
    {
        // "(*ACCEPT)" ends the match: what follows it may not be matched.
        if (str_starts_with($node->verb, 'ACCEPT')) {
            return new LiteralSet([''], [], false);
        }

        return LiteralSet::fromString('');
    }

    #[\Override]
    public function visitDefine(DefineNode $node): LiteralSet
    {
        // A (DEFINE) group is never run in place: it matches nothing.
        return LiteralSet::fromString('');
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): LiteralSet
    {
        return LiteralSet::fromString('');
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): LiteralSet
    {
        // Callouts do not match characters, so they don't contribute to literal extraction.
        return LiteralSet::fromString('');
    }

    /**
     * Whether a "(*ACCEPT)" inside the node may end the match there: not one
     * in a lookaround, which only ends the assertion.
     */
    private function mayAccept(NodeInterface $node): bool
    {
        if ($node instanceof PcreVerbNode) {
            return str_starts_with($node->verb, 'ACCEPT');
        }

        $children = match (true) {
            $node instanceof GroupNode => \in_array($node->type, self::LOOKAROUNDS, true) ? [] : [$node->child],
            $node instanceof SequenceNode => $node->children,
            $node instanceof AlternationNode => $node->alternatives,
            $node instanceof QuantifierNode => [$node->node],
            $node instanceof ConditionalNode => [$node->yes, $node->no],
            default => [],
        };

        foreach ($children as $child) {
            if ($this->mayAccept($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether matching is caseless once an option setting such as "i",
     * "-i", "s-mi" or "^i" applies.
     */
    private function caseInsensitiveAfter(string $flags): bool
    {
        if ('' === $flags) {
            return $this->caseInsensitive;
        }

        $caseInsensitive = str_starts_with($flags, '^') ? false : $this->caseInsensitive;
        [$on, $off] = array_pad(explode('-', ltrim($flags, '^'), 2), 2, '');

        if (str_contains($off, 'i')) {
            return false;
        }

        return $caseInsensitive || str_contains($on, 'i');
    }

    private function expandCaseInsensitive(string $value): LiteralSet
    {
        // Limit expansion length. Beyond ASCII, caseless matching folds
        // characters strtolower() does not know ("ⱥ" and "Ⱥ"); in UTF mode
        // it also folds "k" with the Kelvin sign and "s" with the long s.
        if (\strlen($value) > 8 || 1 === preg_match($this->unicode ? '/[\x80-\xffkKsS]/' : '/[\x80-\xff]/', $value)) {
            return LiteralSet::empty();
        }

        $results = [''];
        for ($i = 0; $i < \strlen($value); $i++) {
            $char = $value[$i];
            $lower = strtolower($char);
            $upper = strtoupper($char);

            $nextResults = [];
            foreach ($results as $prefix) {
                if ($lower === $upper) {
                    $nextResults[] = $prefix.$char;
                } else {
                    $nextResults[] = $prefix.$lower;
                    $nextResults[] = $prefix.$upper;
                }
            }
            $results = $nextResults;
        }

        if (\count($results) > self::MAX_LITERALS_COUNT) {
            return LiteralSet::empty();
        }

        return new LiteralSet($results, $results, true);
    }
}
