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
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\DisplayEscaper;

/**
 * Generates an HTML explanation of the regex.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class HtmlExplainer extends AbstractNodeVisitor
{
    /**
     * How many groups stand around the node being explained: a bare "(?i)"
     * holds to the end of the innermost one, or of the pattern.
     */
    private int $groupDepth = 0;

    /**
     * The settings in force where the node being explained stands.
     */
    private MatchSettings $settings;

    public function __construct()
    {
        $this->settings = new MatchSettings();
    }

    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        $this->groupDepth = 0;
        $this->settings = MatchSettings::of($node);
        $patternExplain = $node->pattern->accept($this);
        $flags = $node->flags ? $this->e(' (with flags: '.$node->flags.')') : '';

        return \sprintf(
            "<div class=\"regex-explain\">\n<strong>Regex matches%s:</strong>\n<ul>%s</ul>\n</div>",
            $flags,
            $patternExplain,
        );
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): string
    {
        $alts = array_map(
            fn (NodeInterface $alt): string => $alt->accept($this),
            $node->alternatives,
        );

        return \sprintf(
            "<li><strong>EITHER:</strong>\n<ul>%s</ul>\n</li>",
            implode("\n<li><strong>OR:</strong>\n<ul>", $alts),
        );
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): string
    {
        $parts = array_map(fn (NodeInterface $child): string => $child->accept($this), $node->children);
        $parts = array_filter($parts, static fn (string $part): bool => '' !== $part);

        return implode("\n", $parts);
    }

    #[\Override]
    public function visitGroup(GroupNode $node): string
    {
        if (InlineSetting::is($node)) {
            $this->settings = $this->settings->with($node->flags ?? '');
            $setting = $this->e(InlineSetting::describe($node, $this->groupDepth > 0));

            return \sprintf('<li><span title="%s">%s</span></li>', $setting, $setting);
        }

        $childExplain = $this->explainInGroup($node->child, GroupType::InlineFlags === $node->type ? $node->flags : null);
        $type = match ($node->type) {
            GroupType::Capturing => 'Start Capturing Group',
            GroupType::NonCapturing => 'Start Non-Capturing Group',
            GroupType::Named => \sprintf("Start Capturing Group (named: '%s')", $this->e($node->name)),
            GroupType::LookaheadPositive => 'Start Positive Lookahead',
            GroupType::LookaheadNegative => 'Start Negative Lookahead',
            GroupType::LookbehindPositive => 'Start Positive Lookbehind',
            GroupType::LookbehindNegative => 'Start Negative Lookbehind',
            GroupType::Atomic => 'Start Atomic Group',
            GroupType::BranchReset => 'Start Branch Reset Group',
            GroupType::ScanSubstring => \sprintf('Start Substring Scan (of groups %s)', $this->e(implode(', ', $node->scannedGroups))),
            GroupType::InlineFlags => \sprintf("Start Group (with flags: '%s')", $this->e($node->flags)),
        };

        return \sprintf(
            "<li><span title=\"%s\"><strong>%s:</strong></span>\n<ul>%s</ul>\n</li>",
            $this->e($type),
            $this->e($type),
            $childExplain,
        );
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): string
    {
        $childExplain = $node->node->accept($this);
        $quantExplain = Vocabulary::quantifier($node->quantifier, $node->type);

        // If the child is simple (one line <li>), put it on one line.
        if (str_starts_with((string) $childExplain, '<li>') && !str_contains(substr((string) $childExplain, 4), '<li>')) {
            // Inject the quantifier explanation into the child's <li>
            return str_replace('<li>', \sprintf('<li>(%s) ', $this->e($quantExplain)), (string) $childExplain);
        }

        // If the child is complex, wrap it
        return \sprintf(
            "<li><strong>Quantifier (%s):</strong>\n<ul>%s</ul>\n</li>",
            $this->e($quantExplain),
            $childExplain,
        );
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): string
    {
        $explanation = Vocabulary::literal($node->value, $this->settings->utf);

        return \sprintf(
            '<li><span title="Literal: %s">Literal: <strong>%s</strong></span></li>',
            $this->e($explanation),
            $this->e($explanation),
        );
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): string
    {
        $explanation = Vocabulary::charType($node->value, $this->settings) ?? 'unknown (\\'.$node->value.')';

        return \sprintf(
            '<li><span title="Character Type: %s">Character Type: <strong>\%s</strong> (%s)</span></li>',
            $this->e($explanation),
            $this->e($node->value),
            $this->e($explanation),
        );
    }

    #[\Override]
    public function visitDot(DotNode $node): string
    {
        $explanation = Vocabulary::dot($this->settings);

        return \sprintf(
            '<li><span title="%s">Wildcard: <strong>.</strong> (%s)</span></li>',
            $this->e($explanation),
            $this->e($explanation),
        );
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): string
    {
        $explanation = Vocabulary::anchor($node->value, $this->settings);

        return \sprintf(
            '<li><span title="%s">Anchor: <strong>%s</strong> (%s)</span></li>',
            $this->e($explanation),
            $this->e($node->value),
            $this->e($explanation),
        );
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): string
    {
        $explanation = Vocabulary::assertion($node->value, $this->settings);

        return \sprintf(
            '<li><span title="%s">Assertion: <strong>\%s</strong> (%s)</span></li>',
            $this->e($explanation),
            $this->e($node->value),
            $this->e($explanation),
        );
    }

    #[\Override]
    public function visitKeep(KeepNode $node): string
    {
        $explanation = '\K (reset match start)';

        return \sprintf(
            '<li><span title="%s">Assertion: <strong>\K</strong> (%s)</span></li>',
            $this->e($explanation),
            $this->e($explanation),
        );
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): string
    {
        $negLabel = $node->isNegated ? '<strong>except</strong> ' : '';
        $expressionParts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        $explainedParts = array_map(fn (NodeInterface $part): string => $part->accept($this), $expressionParts);

        // Char class parts are just strings, not <li>
        $parts = array_map($this->text(...), $explainedParts);

        $explanation = $node->isNegated
            ? \sprintf('any character except [ %s ]', implode(', ', $parts))
            : \sprintf('any character in [ %s ]', implode(', ', $parts));

        return \sprintf(
            '<li><span title="%s">Character Class: [ %s%s ]</span></li>',
            $this->e($explanation),
            $negLabel,
            $this->e(implode(', ', $parts)),
        );
    }

    #[\Override]
    public function visitRange(RangeNode $node): string
    {
        $start = ($node->start instanceof LiteralNode)
            ? Vocabulary::literal($node->start->value, $this->settings->utf)
            : $node->start->accept($this);

        $end = ($node->end instanceof LiteralNode)
            ? Vocabulary::literal($node->end->value, $this->settings->utf)
            : $node->end->accept($this);

        return \sprintf('Range: from %s to %s', $this->e((string) $start), $this->e((string) $end));
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): string
    {
        $explanation = \sprintf('whatever the capturing group "%s" matched', $node->ref);

        return \sprintf(
            '<li><span title="%s">Backreference: <strong>\%s</strong></span></li>',
            $this->e($explanation),
            $this->e($node->ref),
        );
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): string
    {
        $inner = $node->hasBraces ? trim($node->prop, '{}') : $node->prop;
        $isNegated = str_starts_with($inner, '^');
        $prop = ltrim($inner, '^');
        $prefix = $isNegated ? 'P' : 'p';
        $key = Ascii::lower($prop);

        $description = Vocabulary::unicodeProperty($key, $isNegated)
            ?? \sprintf('any character %s "%s"', $isNegated ? 'non-matching' : 'matching', $prop);

        return \sprintf(
            '<li><span title="%s">Unicode Property: <strong>\%s{%s}</strong></span></li>',
            $this->e($description),
            $prefix,
            $this->e($prop),
        );
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): string
    {
        return \sprintf('<li>POSIX Class: [[:%s:]]</li>', $this->e($node->class));
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): string
    {
        // Operands come back escaped, in <li>: their text is kept.
        return '<li>Extended character class: one character of '.strip_tags($node->expression->accept($this)).'</li>';
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): string
    {
        $right = strip_tags($node->right->accept($this));
        if (null === $node->left) {
            return 'not '.$right;
        }

        $word = match ($node->operator) {
            ClassSetOperator::Intersection => 'and',
            ClassSetOperator::Difference => 'but not',
            ClassSetOperator::SymmetricDifference => 'or else',
            default => 'or',
        };

        return \sprintf('(%s %s %s)', strip_tags($node->left->accept($this)), $word, $right);
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): string
    {
        $title = $node->atomic ? 'Atomic script run' : 'Script run';
        if (null === $node->content) {
            return \sprintf('<li><strong>%s</strong>: every character from one script</li>', $title);
        }

        return \sprintf("<li><strong>%s</strong>: every character from one script\n<ul>%s</ul>\n</li>", $title, $this->explainInGroup($node->content));
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): string
    {
        return \sprintf(
            '<li>Version Condition: PCRE2 <strong>%s %s</strong></li>',
            $this->e($node->operator),
            $this->e($node->version),
        );
    }

    #[\Override]
    public function visitComment(CommentNode $node): string
    {
        return \sprintf(
            '<li><span title="Comment" style="color: #888; font-style: italic;">Comment: %s</span></li>',
            // A byte-mode comment may hold bytes that are no UTF-8: spelled.
            $this->e(mb_check_encoding($node->comment, 'UTF-8') ? $node->comment : DisplayEscaper::escapeText($node->comment)),
        );
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): string
    {
        $cond = $this->e(Vocabulary::condition($node->condition)) ?: $this->explainInGroup($node->condition);
        $yes = $this->explainInGroup($node->yes);

        // Check if the 'no' branch is an empty literal node
        $hasElseBranch = !($node->no instanceof LiteralNode && '' === $node->no->value);
        $no = $hasElseBranch ? $this->explainInGroup($node->no) : '';

        // Condition node will be a <li>, just need its text
        $condText = trim($this->text((string) $cond), " \t\n\r\0\x0B");

        if ('' === $no || '<li></li>' === $no) {
            return \sprintf(
                "<li><strong>Conditional: IF</strong> (%s) <strong>THEN:</strong>\n<ul>%s</ul>\n</li>",
                $this->e($condText),
                $yes,
            );
        }

        return \sprintf(
            "<li><strong>Conditional: IF</strong> (%s) <strong>THEN:</strong>\n<ul>%s</ul>\n<strong>ELSE:</strong>\n<ul>%s</ul>\n</li>",
            $this->e($condText),
            $yes,
            $no,
        );
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): string
    {
        $ref = match ($node->reference) {
            'R', '0' => 'the entire pattern',
            default => 'group '.$this->e($node->reference),
        };
        $explanation = \sprintf('recurses to %s', $ref);

        return \sprintf(
            '<li><span title="%s">Subroutine Call: <strong>(%s%s)</strong></span></li>',
            $this->e($explanation),
            $this->e($node->syntax),
            $this->e($node->reference),
        );
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): string
    {
        // An option such as "(*CR)" is a verb only where the pattern opens.
        $this->settings = $this->settings->withOption($node->verb);

        return \sprintf(
            '<li><span title="PCRE Verb">PCRE Verb: <strong>(*%s)</strong></span></li>',
            $this->e($node->verb),
        );
    }

    #[\Override]
    public function visitDefine(DefineNode $node): string
    {
        $content = $this->explainInGroup($node->content);

        return \sprintf(
            "<li><strong>DEFINE Block</strong> (defines subpatterns without matching):\n<ul>%s</ul>\n</li>",
            $content,
        );
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): string
    {
        $explanation = \sprintf('sets the match limit to %d', $node->limit);

        return \sprintf(
            '<li><span title="%s">PCRE Verb: <strong>(*LIMIT_MATCH=%d)</strong></span></li>',
            $this->e($explanation),
            $node->limit,
        );
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): string
    {
        if (null === $node->identifier) {
            $argument = '';
            $explanation = 'passes control to user function with no argument';
        } else {
            $argument = $node->isStringIdentifier ? '"'.$node->identifier.'"' : (string) $node->identifier;
            $explanation = \sprintf('passes control to user function with argument %s', $argument);
        }

        return \sprintf(
            '<li><span title="%s">Callout: <strong>(?C%s)</strong></span></li>',
            $this->e($explanation),
            $this->e($argument),
        );
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): string
    {
        $title = match ($node->type) {
            CharLiteralType::Unicode => 'Character with hexadecimal value 0x'.Vocabulary::unicodeHexValue($node),
            CharLiteralType::UnicodeNamed => 'Unicode named character',
            CharLiteralType::Octal => 'Character with octal value '.Vocabulary::octalValue($node),
            CharLiteralType::OctalLegacy => 'Character with octal value '.Vocabulary::legacyOctalValue($node->originalRepresentation),
        };

        return \sprintf(
            '<li><span title="%s">%s: <strong>%s</strong></span></li>',
            $this->e($title),
            $this->e($node->type->label()),
            $this->e($node->originalRepresentation),
        );
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): string
    {
        $explanation = \sprintf('control character corresponding to %s', $node->char);

        return \sprintf(
            '<li><span title="%s">Control character: <strong>\\c%s</strong></span></li>',
            $this->e($explanation),
            $this->e($node->char),
        );
    }

    /**
     * The explanation of what a group holds, under the flags it sets: they
     * hold to its end, as does a bare "(?m)" inside it.
     */
    private function explainInGroup(NodeInterface $node, ?string $flags = null): string
    {
        $settings = $this->settings;
        if (null !== $flags) {
            $this->settings = $settings->with($flags);
        }

        $this->groupDepth++;

        try {
            return (string) $node->accept($this);
        } finally {
            $this->groupDepth--;
            $this->settings = $settings;
        }
    }

    /**
     * The text of an explained part, its tags dropped and its entities
     * read back, to be escaped once more where it is written.
     */
    private function text(string $html): string
    {
        return html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    private function e(?string $s): string
    {
        return htmlspecialchars((string) $s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
