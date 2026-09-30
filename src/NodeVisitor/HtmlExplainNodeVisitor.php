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

namespace RegexParser\NodeVisitor;

use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharLiteralType;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassSetOperationNode;
use RegexParser\Node\ClassSetOperator;
use RegexParser\Node\CommentNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\ExtendedCharClassNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\KeepNode;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\NodeInterface;
use RegexParser\Node\PcreVerbNode;
use RegexParser\Node\PosixClassNode;
use RegexParser\Node\QuantifierBounds;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\QuantifierType;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\ScriptRunNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Node\SubroutineNode;
use RegexParser\Node\UnicodePropNode;
use RegexParser\Node\VersionConditionNode;

/**
 * Generates an HTML explanation of the regex.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class HtmlExplainNodeVisitor extends AbstractNodeVisitor
{
    private const CHAR_TYPE_MAP = [
        'd' => 'A digit: [0-9]',
        'D' => 'A non-digit: [^0-9]',
        'h' => 'A horizontal whitespace character: [ \\t\\xA0\\u1680\\u180e\\u2000-\\u200a\\u202f\\u205f\\u3000]',
        'H' => 'A non-horizontal whitespace character: [^\\h]',
        's' => 'A whitespace character: [ \\t\\n\\x0B\\f\\r]',
        'S' => 'A non-whitespace character: [^\\s]',
        'v' => 'A vertical whitespace character: [\\n\\x0B\\f\\r\\x85\\u2028\\u2029]',
        'V' => 'A non-vertical whitespace character: [^\\v]',
        'w' => 'A word character: [a-zA-Z_0-9]',
        'W' => 'A non-word character: [^\\w]',
        'R' => 'Any Unicode linebreak sequence (\\u000D\\u000A|[\\u000A\\u000B\\u000C\\u000D\\u0085\\u2028\\u2029])',
    ];

    private const ANCHOR_MAP = [
        '^' => 'the beginning of a line',
        '$' => 'the end of a line',
    ];

    private const ASSERTION_MAP = [
        'A' => 'the beginning of the input',
        'z' => 'the end of the input',
        'Z' => 'the end of the input but for the final terminator, if any',
        'G' => 'the end of the previous match',
        'b' => 'a word boundary',
        'B' => 'a non-word boundary',
    ];

    private const UNICODE_PROPERTY_MAP = [
        'lower' => 'A lower-case alphabetic character: [a-z]',
        'upper' => 'An upper-case alphabetic character: [A-Z]',
        'ascii' => 'All ASCII: [\\x00-\\x7F]',
        'alpha' => 'An alphabetic character: [\\p{Lower}\\p{Upper}]',
        'digit' => 'A decimal digit: [0-9]',
        'alnum' => 'An alphanumeric character: [\\p{Alpha}\\p{Digit}]',
        'punct' => 'Punctuation: One of !"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~',
        'graph' => 'A visible character: [\\p{Alnum}\\p{Punct}]',
        'print' => 'A printable character: [\\p{Graph}\\x20]',
        'blank' => 'A space or a tab: [ \\t]',
        'cntrl' => 'A control character: [\\x00-\\x1F\\x7F]',
        'xdigit' => 'A hexadecimal digit: [0-9a-fA-F]',
        'space' => 'A whitespace character: [ \\t\\n\\x0B\\f\\r]',
        'javalowercase' => 'Equivalent to java.lang.Character.isLowerCase()',
        'javauppercase' => 'Equivalent to java.lang.Character.isUpperCase()',
        'javawhitespace' => 'Equivalent to java.lang.Character.isWhitespace()',
        'javamirrored' => 'Equivalent to java.lang.Character.isMirrored()',
        'islatin' => 'A Latin script character (script)',
        'ingreek' => 'A character in the Greek block (block)',
        'lu' => 'An uppercase letter (category)',
        'isalphabetic' => 'An alphabetic character (binary property)',
        'sc' => 'A currency symbol',
    ];

    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
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
        $childExplain = $node->child->accept($this);
        $type = match ($node->type) {
            GroupType::T_GROUP_CAPTURING => 'Start Capturing Group',
            GroupType::T_GROUP_NON_CAPTURING => 'Start Non-Capturing Group',
            GroupType::T_GROUP_NAMED => \sprintf("Start Capturing Group (named: '%s')", $this->e($node->name)),
            GroupType::T_GROUP_LOOKAHEAD_POSITIVE => 'Start Positive Lookahead',
            GroupType::T_GROUP_LOOKAHEAD_NEGATIVE => 'Start Negative Lookahead',
            GroupType::T_GROUP_LOOKBEHIND_POSITIVE => 'Start Positive Lookbehind',
            GroupType::T_GROUP_LOOKBEHIND_NEGATIVE => 'Start Negative Lookbehind',
            GroupType::T_GROUP_ATOMIC => 'Start Atomic Group',
            GroupType::T_GROUP_BRANCH_RESET => 'Start Branch Reset Group',
            GroupType::T_GROUP_SCAN_SUBSTRING => \sprintf('Start Substring Scan (of groups %s)', $this->e(implode(', ', $node->scannedGroups))),
            GroupType::T_GROUP_INLINE_FLAGS => \sprintf("Start Group (with flags: '%s')", $this->e($node->flags)),
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
        $quantExplain = $this->explainQuantifierValue($node->quantifier, $node->type);

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
        $explanation = $this->explainLiteral($node->value);

        return \sprintf(
            '<li><span title="Literal: %s">Literal: <strong>%s</strong></span></li>',
            $this->e($explanation),
            $this->e($explanation),
        );
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): string
    {
        $explanation = self::CHAR_TYPE_MAP[$node->value] ?? 'unknown (\\'.$node->value.')';

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
        $explanation = 'any character (may or may not match line terminators)';

        return \sprintf(
            '<li><span title="%s">Wildcard: <strong>.</strong> (%s)</span></li>',
            $this->e($explanation),
            $this->e($explanation),
        );
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): string
    {
        $explanation = self::ANCHOR_MAP[$node->value] ?? $node->value;

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
        $explanation = self::ASSERTION_MAP[$node->value] ?? '\\'.$node->value;

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
        $parts = array_map(strip_tags(...), $explainedParts);

        $explanation = $node->isNegated
            ? \sprintf('any character except [ %s ]', implode(', ', $parts))
            : \sprintf('any character in [ %s ]', implode(', ', $parts));

        return \sprintf(
            '<li><span title="%s">Character Class: [ %s%s ]</span></li>',
            $this->e(strip_tags($explanation)),
            $negLabel,
            $this->e(implode(', ', $parts)),
        );
    }

    #[\Override]
    public function visitRange(RangeNode $node): string
    {
        $start = ($node->start instanceof LiteralNode)
            ? $this->explainLiteral($node->start->value)
            : $node->start->accept($this);

        $end = ($node->end instanceof LiteralNode)
            ? $this->explainLiteral($node->end->value)
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
        $key = strtolower($prop);

        if (isset(self::UNICODE_PROPERTY_MAP[$key])) {
            $description = self::UNICODE_PROPERTY_MAP[$key];
            if ($isNegated) {
                if ('ingreek' === $key) {
                    $description = 'Any character except one in the Greek block (block)';
                } else {
                    $description = 'Any character except '.lcfirst($description);
                }
            }
        } else {
            $type = $isNegated ? 'non-matching' : 'matching';
            $description = \sprintf('any character %s "%s"', $type, $prop);
        }

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
            ClassSetOperator::INTERSECTION => 'and',
            ClassSetOperator::DIFFERENCE => 'but not',
            ClassSetOperator::SYMMETRIC_DIFFERENCE => 'or else',
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

        return \sprintf("<li><strong>%s</strong>: every character from one script\n<ul>%s</ul>\n</li>", $title, $node->content->accept($this));
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
            $this->e($node->comment),
        );
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): string
    {
        $cond = $node->condition->accept($this);
        $yes = $node->yes->accept($this);

        // Check if the 'no' branch is an empty literal node
        $hasElseBranch = !($node->no instanceof LiteralNode && '' === $node->no->value);
        $no = $hasElseBranch ? $node->no->accept($this) : '';

        // Condition node will be a <li>, just need its text
        $condText = trim(strip_tags((string) $cond));

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
        return \sprintf(
            '<li><span title="PCRE Verb">PCRE Verb: <strong>(*%s)</strong></span></li>',
            $this->e($node->verb),
        );
    }

    #[\Override]
    public function visitDefine(DefineNode $node): string
    {
        $content = $node->content->accept($this);

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
            CharLiteralType::UNICODE => 'Character with hexadecimal value 0x'.$this->formatUnicodeHexValue($node),
            CharLiteralType::UNICODE_NAMED => 'Unicode named character',
            CharLiteralType::OCTAL => 'Character with octal value '.$this->formatOctalValue($node),
            CharLiteralType::OCTAL_LEGACY => 'Character with octal value '.$this->formatLegacyOctalValue($node->originalRepresentation),
        };

        return \sprintf(
            '<li><span title="%s">%s: <strong>%s</strong></span></li>',
            $this->e($title),
            htmlspecialchars($node->type->label()),
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

    private function explainQuantifierValue(string $q, QuantifierType $type): string
    {
        $bounds = QuantifierBounds::parse($q);
        $desc = match (true) {
            '*' === $q => 'zero or more times',
            '+' === $q => 'one or more times',
            '?' === $q => 'once or not at all',
            null === $bounds => 'with quantifier '.$q, // Fallback
            $bounds->min === $bounds->max => \sprintf('exactly %d times', $bounds->min),
            null === $bounds->max => \sprintf('at least %d times', $bounds->min),
            default => \sprintf('at least %d but not more than %d times', $bounds->min, $bounds->max),
        };

        $desc .= match ($type) {
            QuantifierType::T_LAZY => ' (as few as possible)',
            QuantifierType::T_POSSESSIVE => ' (and do not backtrack)',
            default => '',
        };

        return $desc;
    }

    private function explainLiteral(string $value): string
    {
        return match ($value) {
            ' ' => "' ' (space)",
            "\t" => "'\\t' (tab)",
            "\n" => "'\\n' (newline)",
            "\r" => "'\\r' (carriage return)",
            "\f" => "'\\f' (form feed)",
            "\x07" => "'\\a' (bell)",
            "\x1B" => "'\\e' (escape)",
            default => $this->formatCharLiteral($value),
        };
    }

    private function formatCharLiteral(string $value): string
    {
        // The first byte, as ord() read it before PHP 8.5 deprecated
        // passing it anything but one byte.
        $ord = '' === $value ? 0 : \ord($value[0]);

        // Handle control characters and extended ASCII as hex codes
        if ($ord < 32 || 127 === $ord || $ord >= 128) {
            return "'\\x".strtoupper(str_pad(dechex($ord), 2, '0', \STR_PAD_LEFT))."'";
        }

        // Printable characters
        return "'".$value."'";
    }

    private function formatUnicodeHexValue(CharLiteralNode $node): string
    {
        $rep = $node->originalRepresentation;
        if (preg_match('/^\\\\x([0-9a-fA-F]{1,2})$/', $rep, $matches)) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/^\\\\u([0-9a-fA-F]{4})$/', $rep, $matches)) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/^\\\\[xu]\\{([0-9a-fA-F]+)\\}$/', $rep, $matches)) {
            return strtoupper($matches[1]);
        }

        if (1 === \strlen($rep)) {
            return strtoupper(str_pad(dechex(\ord($rep)), 2, '0', \STR_PAD_LEFT));
        }

        return strtoupper($rep);
    }

    private function formatOctalValue(CharLiteralNode $node): string
    {
        $rep = $node->originalRepresentation;
        if (preg_match('/^\\\\o\\{([0-7]+)\\}$/', $rep, $matches)) {
            return '0'.$matches[1];
        }

        return $this->formatLegacyOctalValue($rep);
    }

    private function formatLegacyOctalValue(string $value): string
    {
        $raw = str_starts_with($value, '\\') ? substr($value, 1) : $value;

        return str_starts_with($raw, '0') ? $raw : '0'.$raw;
    }

    private function e(?string $s): string
    {
        return htmlspecialchars((string) $s, \ENT_QUOTES, 'UTF-8');
    }
}
