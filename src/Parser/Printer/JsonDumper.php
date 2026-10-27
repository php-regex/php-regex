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

namespace PHPRegex\Parser\Printer;

use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Analysis\GroupIndex;
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
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
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

/**
 * Exports the tree as data, ready for json_encode(): one document holding
 * the format version, the delimiter, the flags and the pattern, then one
 * object per node with its kind ("type", a stable name, not a class name),
 * its byte offsets in the pattern ("start", "end") and its own fields.
 * ast.schema.json, shipped beside this package, describes the document.
 *
 * Every string is valid UTF-8: a byte that is not is written U+FFFD, and a
 * literal holding such bytes also gives them as hexadecimal ("bytes").
 * Group numbers and the groups a reference or a call points to come from
 * the whole pattern: a subtree exported alone has none.
 *
 * @extends AbstractNodeVisitor<array<string, mixed>>
 */
final class JsonDumper extends AbstractNodeVisitor
{
    /**
     * The version of the document's format. A field added keeps it; a field
     * renamed, removed or given another meaning raises it.
     */
    public const VERSION = 1;

    private ?GroupIndex $groups = null;

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitRegex(RegexNode $node): array
    {
        $this->groups = GroupIndex::of($node->pattern);

        try {
            return [
                'version' => self::VERSION,
                ...$this->node('regex', $node),
                'delimiter' => self::text($node->delimiter),
                'flags' => $node->flags,
                'source' => null === $node->source ? null : self::text($node->source),
                'pattern' => $node->pattern->accept($this),
            ];
        } finally {
            $this->groups = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitAlternation(AlternationNode $node): array
    {
        return [...$this->node('alternation', $node), 'alternatives' => $this->all($node->alternatives)];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitSequence(SequenceNode $node): array
    {
        return [...$this->node('sequence', $node), 'children' => $this->all($node->children)];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitGroup(GroupNode $node): array
    {
        return [
            ...$this->node('group', $node),
            'kind' => $node->type->value,
            // A substring scan keeps its spelling there; it names nothing.
            'name' => null === $node->name || GroupType::ScanSubstring === $node->type ? null : self::text($node->name),
            'flags' => $node->flags,
            'number' => $this->groups?->numberOf($node),
            'scanned_groups' => array_map(self::text(...), $node->scannedGroups),
            'child' => $node->child->accept($this),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitQuantifier(QuantifierNode $node): array
    {
        // Null only for a tree built by hand with a quantifier the parser
        // would not read.
        $bounds = $node->bounds;

        return [
            ...$this->node('quantifier', $node),
            'min' => $bounds?->min,
            'max' => $bounds?->max,
            'mode' => $node->type->value,
            'child' => $node->node->accept($this),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitLiteral(LiteralNode $node): array
    {
        $literal = [...$this->node('literal', $node), 'value' => self::text($node->value)];

        if (!mb_check_encoding($node->value, 'UTF-8')) {
            $literal['bytes'] = bin2hex($node->value);
        }

        return $literal;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitCharType(CharTypeNode $node): array
    {
        return [...$this->node('char_type', $node), 'value' => $node->value];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitDot(DotNode $node): array
    {
        return $this->node('dot', $node);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitAnchor(AnchorNode $node): array
    {
        return [...$this->node('anchor', $node), 'value' => $node->value];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitAssertion(AssertionNode $node): array
    {
        return [...$this->node('assertion', $node), 'value' => $node->value];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitKeep(KeepNode $node): array
    {
        return $this->node('keep', $node);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitCharClass(CharClassNode $node): array
    {
        $members = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];

        return [...$this->node('char_class', $node), 'negated' => $node->isNegated, 'members' => $this->all($members)];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitRange(RangeNode $node): array
    {
        return [...$this->node('range', $node), 'from' => $node->start->accept($this), 'to' => $node->end->accept($this)];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitBackref(BackrefNode $node): array
    {
        return [...$this->node('backref', $node), 'reference' => self::text($node->ref), 'groups' => $this->referencedGroups($node)];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): array
    {
        return [...$this->node('char_literal', $node), 'code_point' => $node->codePoint, 'kind' => $node->type->value];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): array
    {
        return [...$this->node('extended_char_class', $node), 'expression' => $node->expression->accept($this)];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): array
    {
        return [
            ...$this->node('class_set_operation', $node),
            'operator' => $node->operator->value,
            'left' => $node->left?->accept($this),
            'right' => $node->right->accept($this),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitControlChar(ControlCharNode $node): array
    {
        return [...$this->node('control_char', $node), 'char' => self::text($node->char), 'code_point' => $node->codePoint];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): array
    {
        return [
            ...$this->node('script_run', $node),
            'script' => self::text($node->script),
            'atomic' => $node->isAtomic,
            'content' => $node->content?->accept($this),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): array
    {
        return [...$this->node('version_condition', $node), 'operator' => $node->operator, 'version' => $node->version];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): array
    {
        // The parser folds every negation, "\P" or "^", into a leading "^".
        $property = $node->hasBraces ? trim($node->prop, '{}') : $node->prop;
        $negated = str_starts_with($property, '^');

        return [
            ...$this->node('unicode_property', $node),
            'name' => self::text($negated ? substr($property, 1) : $property),
            'negated' => $negated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitPosixClass(PosixClassNode $node): array
    {
        $negated = str_starts_with($node->class, '^');

        return [
            ...$this->node('posix_class', $node),
            'name' => self::text($negated ? substr($node->class, 1) : $node->class),
            'negated' => $negated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitComment(CommentNode $node): array
    {
        return [...$this->node('comment', $node), 'text' => self::text($node->comment), 'extended' => $node->isExtended];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitConditional(ConditionalNode $node): array
    {
        return [
            ...$this->node('conditional', $node),
            'condition' => $node->condition->accept($this),
            'yes' => $node->yes->accept($this),
            'no' => $node->no->accept($this),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitSubroutine(SubroutineNode $node): array
    {
        return [
            ...$this->node('subroutine', $node),
            'reference' => self::text($node->reference),
            'groups' => $this->numbers($this->groups?->groupsCalledBy($node) ?? []),
            'returned_groups' => array_map(self::text(...), $node->returnedGroups),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): array
    {
        return [...$this->node('pcre_verb', $node), 'verb' => self::text($node->verb)];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitDefine(DefineNode $node): array
    {
        return [...$this->node('define', $node), 'content' => $node->content->accept($this)];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): array
    {
        return [...$this->node('limit_match', $node), 'limit' => $node->limit];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function visitCallout(CalloutNode $node): array
    {
        return [
            ...$this->node('callout', $node),
            'identifier' => \is_string($node->identifier) ? self::text($node->identifier) : $node->identifier,
        ];
    }

    /**
     * @return array{type: string, start: int, end: int}
     */
    private function node(string $type, NodeInterface $node): array
    {
        return ['type' => $type, 'start' => $node->getStartPosition(), 'end' => $node->getEndPosition()];
    }

    /**
     * @param array<NodeInterface> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function all(array $nodes): array
    {
        $exported = [];
        foreach ($nodes as $node) {
            $exported[] = $node->accept($this);
        }

        return $exported;
    }

    /**
     * @return list<int>
     */
    private function referencedGroups(BackrefNode $node): array
    {
        if (null === $this->groups) {
            return [];
        }

        return $this->numbers($this->groups->groupsReferencedBy($node));
    }

    /**
     * @param list<GroupNode> $groups
     *
     * @return list<int>
     */
    private function numbers(array $groups): array
    {
        $numbers = [];
        foreach ($groups as $group) {
            $number = $this->groups?->numberOf($group);
            if (null !== $number) {
                $numbers[$number] = $number;
            }
        }
        ksort($numbers);

        return array_values($numbers);
    }

    private static function text(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        // json_encode() writes each byte that is not UTF-8 as U+FFFD.
        $valid = json_decode(json_encode($text, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR), true, 1, \JSON_THROW_ON_ERROR);

        return \is_string($valid) ? $valid : '';
    }
}
