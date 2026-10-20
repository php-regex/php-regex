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

namespace PHPRegex\Explain;

use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ClassSetOperator;
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
use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Parser\Text\Ascii;

/**
 * Words what each construct of a pattern means, as the flags in force where
 * it stands make it match, into the tree the text and the HTML explanations
 * both write out.
 *
 * @internal
 *
 * @extends AbstractNodeVisitor<Explanation>
 */
final class ExplanationBuilder extends AbstractNodeVisitor
{
    /**
     * The settings in force where the node being explained stands.
     */
    private MatchSettings $settings;

    /**
     * How many groups stand around the node being explained: a bare "(?i)"
     * holds to the end of the innermost one, or of the pattern.
     */
    private int $groupDepth = 0;

    public function __construct()
    {
        $this->settings = new MatchSettings();
    }

    #[\Override]
    public function visitRegex(RegexNode $node): Explanation
    {
        $this->settings = MatchSettings::of($node);
        $this->groupDepth = 0;
        $heading = 'Regex matches'.('' !== $node->flags ? ' (with flags: '.$node->flags.')' : '');

        return new Explanation(ExplanationKind::Pattern, $heading, children: [$node->pattern->accept($this)]);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): Explanation
    {
        return new Explanation(ExplanationKind::Alternation, children: $this->each($node->alternatives));
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): Explanation
    {
        return new Explanation(ExplanationKind::Sequence, children: $this->each($node->children));
    }

    #[\Override]
    public function visitGroup(GroupNode $node): Explanation
    {
        if (InlineSetting::is($node)) {
            // It holds to the end of the group around it: not restored here.
            $this->settings = $this->settings->with($node->flags ?? '');

            return Explanation::leaf(InlineSetting::describe($node, $this->groupDepth > 0));
        }

        $heading = match ($node->type) {
            GroupType::Capturing => 'Capturing group',
            GroupType::NonCapturing => 'Non-capturing group',
            GroupType::Named => \sprintf("Capturing group (named: '%s')", $node->name),
            GroupType::LookaheadPositive => 'Positive lookahead',
            GroupType::LookaheadNegative => 'Negative lookahead',
            GroupType::LookbehindPositive => 'Positive lookbehind',
            GroupType::LookbehindNegative => 'Negative lookbehind',
            GroupType::Atomic => 'Atomic group (no backtracking)',
            GroupType::BranchReset => 'Branch reset group',
            GroupType::ScanSubstring => \sprintf('Substring scan of groups %s', implode(', ', $node->scannedGroups)),
            GroupType::InlineFlags => \sprintf("Inline flags '%s'", $node->flags),
        };

        $flags = GroupType::InlineFlags === $node->type ? $node->flags : null;

        return new Explanation(ExplanationKind::Block, $heading, children: $this->inGroup([$node->child], $flags), end: 'End group');
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): Explanation
    {
        return new Explanation(
            ExplanationKind::Repetition,
            Vocabulary::quantifier($node->quantifier, $node->type),
            children: [$node->node->accept($this)],
            end: 'End quantified group',
        );
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): Explanation
    {
        return Explanation::leaf(Vocabulary::literal($node->value, $this->settings->utf));
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): Explanation
    {
        $meaning = Vocabulary::charType($node->value, $this->settings) ?? 'unknown (\\'.$node->value.')';

        return Explanation::leaf($meaning, 'Character Type', '\\'.$node->value);
    }

    #[\Override]
    public function visitDot(DotNode $node): Explanation
    {
        return Explanation::leaf(Vocabulary::dot($this->settings), 'Wildcard', '.');
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): Explanation
    {
        return Explanation::leaf(Vocabulary::anchor($node->value, $this->settings), 'Anchor', $node->value);
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): Explanation
    {
        return Explanation::leaf(Vocabulary::assertion($node->value, $this->settings), 'Assertion', '\\'.$node->value);
    }

    #[\Override]
    public function visitKeep(KeepNode $node): Explanation
    {
        return Explanation::leaf('the match reported starts here', 'Assertion', '\K');
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): Explanation
    {
        $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        $members = implode(', ', array_map(fn (NodeInterface $part): string => $part->accept($this)->inline(), $parts));

        return Explanation::leaf(\sprintf('any character %s [ %s ]', $node->isNegated ? 'except' : 'in', $members), 'Character Class');
    }

    #[\Override]
    public function visitRange(RangeNode $node): Explanation
    {
        return Explanation::leaf(\sprintf('from %s to %s', $node->start->accept($this)->inline(), $node->end->accept($this)->inline()), 'Range');
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): Explanation
    {
        return Explanation::leaf(Vocabulary::backreference($node->ref), 'Backreference', $node->ref);
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): Explanation
    {
        return Explanation::leaf('one character of '.$node->expression->accept($this)->inline(), 'Extended character class');
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): Explanation
    {
        $right = $node->right->accept($this)->inline();
        if (null === $node->left) {
            return Explanation::leaf('not '.$right);
        }

        $word = match ($node->operator) {
            ClassSetOperator::Intersection => 'and',
            ClassSetOperator::Difference => 'but not',
            ClassSetOperator::SymmetricDifference => 'or else',
            default => 'or',
        };

        return Explanation::leaf(\sprintf('(%s %s %s)', $node->left->accept($this)->inline(), $word, $right));
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): Explanation
    {
        return Explanation::leaf(\sprintf('the character 0x%02X', $node->codePoint), 'Control character', '\c'.$node->char);
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): Explanation
    {
        $heading = \sprintf('%s (every character from one script)', $node->atomic ? 'Atomic script run' : 'Script run');
        if (null === $node->content) {
            return Explanation::leaf($heading);
        }

        return new Explanation(ExplanationKind::Block, $heading, children: $this->inGroup([$node->content]), end: 'End script run');
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): Explanation
    {
        return Explanation::leaf(\sprintf('PCRE2 %s %s', $node->operator, $node->version), 'Version condition');
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): Explanation
    {
        $inner = $node->hasBraces ? substr($node->prop, 1, -1) : $node->prop;
        $negated = str_starts_with($inner, '^');
        $name = ltrim($inner, '^');
        $meaning = Vocabulary::unicodeProperty(Ascii::lower($name), $negated)
            ?? \sprintf('any character %s "%s"', $negated ? 'non-matching' : 'matching', $name);

        return Explanation::leaf($meaning, 'Unicode Property', \sprintf('\%s{%s}', $negated ? 'P' : 'p', $name));
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): Explanation
    {
        $meaning = match ($node->type) {
            CharLiteralType::Unicode => 'Character with hexadecimal value 0x'.Vocabulary::unicodeHexValue($node),
            CharLiteralType::UnicodeNamed => 'Unicode named character: '.(LibraryPcre::match('/^\\\\N\{(.+)}$/', $node->originalRepresentation, $matches) ? $matches[1] : $node->originalRepresentation),
            CharLiteralType::Octal => 'Character with octal value '.Vocabulary::octalValue($node),
            CharLiteralType::OctalLegacy => 'Character with octal value '.Vocabulary::legacyOctalValue($node->originalRepresentation),
        };

        return Explanation::leaf($meaning, syntax: $node->originalRepresentation);
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): Explanation
    {
        return Explanation::leaf('[[:'.$node->class.':]]', 'POSIX Class');
    }

    #[\Override]
    public function visitComment(CommentNode $node): Explanation
    {
        // A byte-mode comment may hold bytes that are no UTF-8: spelled.
        $comment = mb_check_encoding($node->comment, 'UTF-8') ? $node->comment : DisplayEscaper::escapeText($node->comment);

        return Explanation::leaf("'".$comment."'", 'Comment');
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): Explanation
    {
        // The branch taken when it does not hold is empty when not written.
        $branches = $node->no instanceof LiteralNode && '' === $node->no->value ? [$node->yes] : [$node->yes, $node->no];
        $settings = $this->settings;
        $this->groupDepth++;

        try {
            $condition = Vocabulary::condition($node->condition);
            $children = [null === $condition ? $node->condition->accept($this) : Explanation::leaf($condition), ...$this->each($branches)];
        } finally {
            $this->groupDepth--;
            $this->settings = $settings;
        }

        return new Explanation(ExplanationKind::Conditional, children: $children);
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): Explanation
    {
        $target = \in_array($node->reference, ['R', '0'], true) ? 'the entire pattern' : 'group '.$node->reference;
        $syntax = 'g' === $node->syntax ? '\g<'.$node->reference.'>' : '(?'.$node->syntax.$node->reference.')';

        return Explanation::leaf('recurses to '.$target, 'Subroutine Call', $syntax);
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): Explanation
    {
        // An option such as "(*CR)" is a verb only where the pattern opens.
        $this->settings = $this->settings->withOption($node->verb);

        return Explanation::leaf('(*'.$node->verb.')', 'PCRE Verb');
    }

    #[\Override]
    public function visitDefine(DefineNode $node): Explanation
    {
        return new Explanation(
            ExplanationKind::Block,
            'DEFINE block (defines subpatterns without matching)',
            children: $this->inGroup([$node->content]),
            end: 'End DEFINE block',
        );
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): Explanation
    {
        return Explanation::leaf(\sprintf('(*LIMIT_MATCH=%d), which sets the match limit to %1$d', $node->limit), 'PCRE Verb');
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): Explanation
    {
        if (null === $node->identifier) {
            return Explanation::leaf('passes control to user function with no argument', 'Callout', '(?C)');
        }

        $argument = $node->isStringIdentifier ? '"'.$node->identifier.'"' : $node->identifier;

        return Explanation::leaf('passes control to user function with argument '.$argument, 'Callout', '(?C'.$argument.')');
    }

    /**
     * @param list<NodeInterface> $nodes
     *
     * @return list<Explanation>
     */
    private function each(array $nodes): array
    {
        return array_map(fn (NodeInterface $node): Explanation => $node->accept($this), $nodes);
    }

    /**
     * What a group holds, under the flags it sets: they hold to its end, as
     * does a bare "(?m)" inside it.
     *
     * @param list<NodeInterface> $nodes
     *
     * @return list<Explanation>
     */
    private function inGroup(array $nodes, ?string $flags = null): array
    {
        $settings = $this->settings;
        if (null !== $flags) {
            $this->settings = $settings->with($flags);
        }

        $this->groupDepth++;

        try {
            return $this->each($nodes);
        } finally {
            $this->groupDepth--;
            $this->settings = $settings;
        }
    }
}
