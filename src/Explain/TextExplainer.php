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
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\DisplayEscaper;

/**
 * Generates a human-readable explanation of the regex.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class TextExplainer extends AbstractNodeVisitor
{
    private int $indentLevel = 0;

    /**
     * What a quantified node explains to at a level: a quantifier asks for
     * its child at two levels, and without this every level of nesting
     * doubled the work.
     *
     * Keyed by the node itself, so an entry goes with its tree.
     *
     * @var \WeakMap<NodeInterface, array<int, string>>|null
     */
    private ?\WeakMap $explained = null;

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
        $this->indentLevel = 0;
        $this->groupDepth = 0;
        $this->settings = MatchSettings::of($node);
        $flags = $node->flags ? ' (with flags: '.$node->flags.')' : '';
        $header = $this->line('Regex matches'.$flags);
        $this->indentLevel++;
        $body = $node->pattern->accept($this);
        $this->indentLevel = 0;

        return $header."\n".$body;
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): string
    {
        $lines = [];
        $this->indentLevel++;
        foreach ($node->alternatives as $index => $alt) {
            $label = 0 === $index ? 'EITHER' : 'OR';
            $lines[] = $this->line($label);
            $this->indentLevel++;
            $lines[] = $alt->accept($this);
            $this->indentLevel--;
        }
        $this->indentLevel--;

        return implode("\n", $lines);
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

            return $this->line(InlineSetting::describe($node, $this->groupDepth > 0));
        }

        $settings = $this->settings;
        if (GroupType::InlineFlags === $node->type) {
            $this->settings = $settings->with($node->flags ?? '');
        }

        $this->indentLevel++;
        $this->groupDepth++;
        $childExplain = $node->child->accept($this);
        $this->groupDepth--;
        $this->indentLevel--;
        $this->settings = $settings;

        $type = match ($node->type) {
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

        return implode("\n", [
            $this->line($type),
            $childExplain,
            $this->line('End group'),
        ]);
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): string
    {
        $childExplain = $this->explainAt($node->node, $this->indentLevel);
        $quantExplain = Vocabulary::quantifier($node->quantifier, $node->type);

        // If the child is simple (one line), put it on one line.
        if (!str_contains($childExplain, "\n")) {
            return $this->line(\sprintf('%s (%s)', $childExplain, $quantExplain));
        }

        // If the child is complex, indent it.
        $childExplain = $this->explainAt($node->node, $this->indentLevel + 1);

        return implode("\n", [
            $this->line('Start Quantified Group ('.$quantExplain.')'),
            $childExplain,
            $this->line('End Quantified Group'),
        ]);
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): string
    {
        return $this->line(Vocabulary::literal($node->value, $this->settings->utf));
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): string
    {
        return $this->line('Character Type: '.(Vocabulary::charType($node->value, $this->settings) ?? 'unknown (\\'.$node->value.')'));
    }

    #[\Override]
    public function visitDot(DotNode $node): string
    {
        return $this->line('Wildcard: '.Vocabulary::dot($this->settings));
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): string
    {
        return $this->line('Anchor: '.(Vocabulary::anchor($node->value, $this->settings)));
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): string
    {
        return $this->line('Assertion: '.(Vocabulary::assertion($node->value, $this->settings)));
    }

    #[\Override]
    public function visitKeep(KeepNode $node): string
    {
        return $this->line('Assertion: \K (reset match start)');
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): string
    {
        $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        $explainedParts = array_map(fn (NodeInterface $part): string => $part->accept($this), $parts);

        if ($node->isNegated) {
            return $this->line(\sprintf('Character Class: any character except [ %s ]', implode(', ', $explainedParts)));
        }

        return $this->line(\sprintf('Character Class: any character in [ %s ]', implode(', ', $explainedParts)));
    }

    #[\Override]
    public function visitRange(RangeNode $node): string
    {
        $start = ($node->start instanceof LiteralNode)
            ? Vocabulary::literal($node->start->value, $this->settings->utf)
            : $node->start->accept($this); // Fallback

        $end = ($node->end instanceof LiteralNode)
            ? Vocabulary::literal($node->end->value, $this->settings->utf)
            : $node->end->accept($this); // Fallback

        return $this->line(\sprintf('Range: from %s to %s', $start, $end));
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): string
    {
        return $this->line(\sprintf('Backreference: whatever the capturing group "%s" matched', $node->ref));
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): string
    {
        return $this->line('Extended character class: one character of '.ltrim($node->expression->accept($this), " \t\n\r\0\x0B"));
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): string
    {
        $right = ltrim($node->right->accept($this), " \t\n\r\0\x0B");
        if (null === $node->left) {
            return 'not '.$right;
        }

        $word = match ($node->operator) {
            ClassSetOperator::Intersection => 'and',
            ClassSetOperator::Difference => 'but not',
            ClassSetOperator::SymmetricDifference => 'or else',
            default => 'or',
        };

        return \sprintf('(%s %s %s)', ltrim($node->left->accept($this), " \t\n\r\0\x0B"), $word, $right);
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): string
    {
        return $this->line(\sprintf('Control character corresponding to %s (\\c%s)', $node->char, $node->char));
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): string
    {
        $title = \sprintf('%s: every character from one script', $node->atomic ? 'Atomic script run' : 'Script run');
        if (null === $node->content) {
            return $this->line($title);
        }

        $settings = $this->settings;
        $this->indentLevel++;
        $this->groupDepth++;
        $content = $node->content->accept($this);
        $this->groupDepth--;
        $this->indentLevel--;
        $this->settings = $settings;

        return implode("\n", [$this->line($title), $content, $this->line('End script run')]);
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): string
    {
        return $this->line(\sprintf('Version condition: %s %s', $node->operator, $node->version));
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): string
    {
        $inner = $node->prop;
        if (str_starts_with($inner, '{') && str_ends_with($inner, '}')) {
            $inner = substr($inner, 1, -1);
        }
        $isNegated = str_starts_with($inner, '^');
        $prop = ltrim($inner, '^');
        $key = Ascii::lower($prop);

        $description = Vocabulary::unicodeProperty($key, $isNegated);
        if (null !== $description) {
            return $this->line($description);
        }

        $type = $isNegated ? 'non-matching' : 'matching';

        return $this->line(\sprintf('Unicode Property: any character %s "%s"', $type, $prop));
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): string
    {
        return match ($node->type) {
            CharLiteralType::Unicode => $this->line('Character with hexadecimal value 0x'.Vocabulary::unicodeHexValue($node)),
            CharLiteralType::UnicodeNamed => $this->line('Unicode named character: '.$this->extractCharLiteralDetail($node)),
            CharLiteralType::Octal => $this->line('Character with octal value '.Vocabulary::octalValue($node)),
            CharLiteralType::OctalLegacy => $this->line('Character with octal value '.Vocabulary::legacyOctalValue($node->originalRepresentation)),
        };
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): string
    {
        return $this->line('POSIX Class: '.$node->class);
    }

    #[\Override]
    public function visitComment(CommentNode $node): string
    {
        // A byte-mode comment may hold bytes that are no UTF-8: spelled.
        $comment = mb_check_encoding($node->comment, 'UTF-8') ? $node->comment : DisplayEscaper::escapeText($node->comment);

        return $this->line(\sprintf("Comment: '%s'", $comment));
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): string
    {
        $settings = $this->settings;
        $this->indentLevel++;
        $this->groupDepth++;
        $cond = $node->condition->accept($this);
        $yes = $node->yes->accept($this);

        // Check if the 'no' branch is an empty literal node
        $hasElseBranch = !($node->no instanceof LiteralNode && '' === $node->no->value);
        $no = $hasElseBranch ? $node->no->accept($this) : '';

        $this->groupDepth--;
        $this->indentLevel--;
        $this->settings = $settings;

        if ('' === $no) {
            return implode("\n", [
                $this->line(\sprintf('IF (%s) THEN', $cond)),
                $yes,
            ]);
        }

        return implode("\n", [
            $this->line(\sprintf('IF (%s) THEN', $cond)),
            $yes,
            $this->line('ELSE'),
            $no,
        ]);
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): string
    {
        $ref = match ($node->reference) {
            'R', '0' => 'the entire pattern',
            default => 'group '.$node->reference,
        };

        return $this->line(\sprintf('Subroutine Call: recurses to %s', $ref));
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): string
    {
        // An option such as "(*CR)" is a verb only where the pattern opens.
        $this->settings = $this->settings->withOption($node->verb);

        return $this->line('PCRE Verb: (*'.$node->verb.')');
    }

    #[\Override]
    public function visitDefine(DefineNode $node): string
    {
        $settings = $this->settings;
        $this->indentLevel++;
        $this->groupDepth++;
        $content = $node->content->accept($this);
        $this->groupDepth--;
        $this->indentLevel--;
        $this->settings = $settings;

        return implode("\n", [
            $this->line('DEFINE block (defines subpatterns without matching)'),
            $content,
            $this->line('End DEFINE Block'),
        ]);
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): string
    {
        return $this->line(\sprintf('PCRE Verb: (*LIMIT_MATCH=%d) - sets a match limit for backtracking control', $node->limit));
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): string
    {
        if (null === $node->identifier) {
            return $this->line('Callout: passes control to user function with no argument');
        }

        $arg = \is_int($node->identifier) || !$node->isStringIdentifier
            ? $node->identifier
            : '"'.$node->identifier.'"';

        return $this->line(\sprintf('Callout: passes control to user function with argument %s', $arg));
    }

    private function indent(bool $withExtra = true): string
    {
        return str_repeat('  ', $this->indentLevel).($withExtra ? '  ' : '');
    }

    private function extractCharLiteralDetail(CharLiteralNode $node): string
    {
        if (CharLiteralType::UnicodeNamed === $node->type) {
            if (LibraryPcre::match('/^\\\\N\\{(.+)}$/', $node->originalRepresentation, $matches)) {
                return $matches[1];
            }
        }

        return $node->originalRepresentation;
    }

    private function explainAt(NodeInterface $node, int $level): string
    {
        $this->explained ??= new \WeakMap();
        $known = $this->explained[$node] ?? [];
        if (isset($known[$level])) {
            return $known[$level];
        }

        $indentLevel = $this->indentLevel;
        $this->indentLevel = $level;

        try {
            $known[$level] = (string) $node->accept($this);
        } finally {
            $this->indentLevel = $indentLevel;
        }

        $this->explained[$node] = $known;

        return $known[$level];
    }

    private function line(string $text): string
    {
        return $this->indent(false).$text;
    }
}
