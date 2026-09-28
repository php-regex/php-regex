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
use RegexParser\Node\ClassOperationNode;
use RegexParser\Node\ClassOperationType;
use RegexParser\Node\CommentNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
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
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\ScriptRunNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Node\SubroutineNode;
use RegexParser\Node\UnicodePropNode;
use RegexParser\Node\VersionConditionNode;

/**
 * Generates a human-readable explanation of the regex.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class ExplainNodeVisitor extends AbstractNodeVisitor
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

    private int $indentLevel = 0;

    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        $this->indentLevel = 0;
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
        $this->indentLevel++;
        $childExplain = $node->child->accept($this);
        $this->indentLevel--;

        $type = match ($node->type) {
            GroupType::T_GROUP_CAPTURING => 'Capturing group',
            GroupType::T_GROUP_NON_CAPTURING => 'Non-capturing group',
            GroupType::T_GROUP_NAMED => \sprintf("Capturing group (named: '%s')", $node->name),
            GroupType::T_GROUP_LOOKAHEAD_POSITIVE => 'Positive lookahead',
            GroupType::T_GROUP_LOOKAHEAD_NEGATIVE => 'Negative lookahead',
            GroupType::T_GROUP_LOOKBEHIND_POSITIVE => 'Positive lookbehind',
            GroupType::T_GROUP_LOOKBEHIND_NEGATIVE => 'Negative lookbehind',
            GroupType::T_GROUP_ATOMIC => 'Atomic group (no backtracking)',
            GroupType::T_GROUP_BRANCH_RESET => 'Branch reset group',
            GroupType::T_GROUP_INLINE_FLAGS => \sprintf("Inline flags '%s'", $node->flags),
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
        $childExplain = $node->node->accept($this);
        $quantExplain = $this->explainQuantifierValue($node->quantifier, $node->type->value);

        // If the child is simple (one line), put it on one line.
        if (!str_contains((string) $childExplain, "\n")) {
            return $this->line(\sprintf('%s (%s)', $childExplain, $quantExplain));
        }

        // If the child is complex, indent it.
        $this->indentLevel++;
        $childExplain = $node->node->accept($this);
        $this->indentLevel--;

        return implode("\n", [
            $this->line('Start Quantified Group ('.$quantExplain.')'),
            $childExplain,
            $this->line('End Quantified Group'),
        ]);
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): string
    {
        return $this->line($this->explainLiteral($node->value));
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): string
    {
        return $this->line('Character Type: '.(self::CHAR_TYPE_MAP[$node->value] ?? 'unknown (\\'.$node->value.')'));
    }

    #[\Override]
    public function visitDot(DotNode $node): string
    {
        return $this->line('Wildcard: any character (may or may not match line terminators)');
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): string
    {
        return $this->line('Anchor: '.(self::ANCHOR_MAP[$node->value] ?? $node->value));
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): string
    {
        return $this->line('Assertion: '.(self::ASSERTION_MAP[$node->value] ?? '\\'.$node->value));
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
            ? $this->explainLiteral($node->start->value)
            : $node->start->accept($this); // Fallback

        $end = ($node->end instanceof LiteralNode)
            ? $this->explainLiteral($node->end->value)
            : $node->end->accept($this); // Fallback

        return $this->line(\sprintf('Range: from %s to %s', $start, $end));
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): string
    {
        return $this->line(\sprintf('Backreference: whatever the capturing group "%s" matched', $node->ref));
    }

    /**
     * @deprecated the parser no longer builds a ClassOperationNode; this method goes in the next major version
     */
    #[\Override]
    public function visitClassOperation(ClassOperationNode $node): string
    {
        $op = ClassOperationType::INTERSECTION === $node->type ? 'intersection' : 'subtraction';

        return $this->line(\sprintf('Class %s between %s and %s', $op, $node->left->accept($this), $node->right->accept($this)));
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): string
    {
        return $this->line(\sprintf('Control character corresponding to %s (\\c%s)', $node->char, $node->char));
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): string
    {
        return $this->line(\sprintf('Script run assertion for script: %s', $node->script));
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
        $key = strtolower($prop);

        if (isset(self::UNICODE_PROPERTY_MAP[$key])) {
            $description = self::UNICODE_PROPERTY_MAP[$key];
            if ($isNegated) {
                if ('ingreek' === $key) {
                    return $this->line('Any character except one in the Greek block (block)');
                }

                return $this->line('Any character except '.lcfirst($description));
            }

            return $this->line($description);
        }

        $type = $isNegated ? 'non-matching' : 'matching';

        return $this->line(\sprintf('Unicode Property: any character %s "%s"', $type, $prop));
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): string
    {
        return match ($node->type) {
            CharLiteralType::UNICODE => $this->line('Character with hexadecimal value 0x'.$this->formatUnicodeHexValue($node)),
            CharLiteralType::UNICODE_NAMED => $this->line('Unicode named character: '.$this->extractCharLiteralDetail($node)),
            CharLiteralType::OCTAL => $this->line('Character with octal value '.$this->formatOctalValue($node)),
            CharLiteralType::OCTAL_LEGACY => $this->line('Character with octal value '.$this->formatLegacyOctalValue($node->originalRepresentation)),
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
        return $this->line(\sprintf("Comment: '%s'", $node->comment));
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): string
    {
        $this->indentLevel++;
        $cond = $node->condition->accept($this);
        $yes = $node->yes->accept($this);

        // Check if the 'no' branch is an empty literal node
        $hasElseBranch = !($node->no instanceof LiteralNode && '' === $node->no->value);
        $no = $hasElseBranch ? $node->no->accept($this) : '';

        $this->indentLevel--;

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
        return $this->line('PCRE Verb: (*'.$node->verb.')');
    }

    #[\Override]
    public function visitDefine(DefineNode $node): string
    {
        $this->indentLevel++;
        $content = $node->content->accept($this);
        $this->indentLevel--;

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

    private function explainQuantifierValue(string $q, string $type): string
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
            'lazy' => ' (as few as possible)',
            'possessive' => ' (and do not backtrack)',
            default => '',
        };

        return $desc;
    }

    private function indent(bool $withExtra = true): string
    {
        return str_repeat('  ', $this->indentLevel).($withExtra ? '  ' : '');
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

    private function extractCharLiteralDetail(CharLiteralNode $node): string
    {
        if (CharLiteralType::UNICODE_NAMED === $node->type) {
            if (preg_match('/^\\\\N\\{(.+)}$/', $node->originalRepresentation, $matches)) {
                return $matches[1];
            }
        }

        return $node->originalRepresentation;
    }

    private function line(string $text): string
    {
        return $this->indent(false).$text;
    }
}
