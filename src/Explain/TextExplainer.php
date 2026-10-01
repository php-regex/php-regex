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

namespace PhpRegex\Explain;

use PhpRegex\Parser\AbstractNodeVisitor;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\ClassSetOperationNode;
use PhpRegex\Parser\Node\ClassSetOperator;
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
 * Generates a human-readable explanation of the regex.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class TextExplainer extends AbstractNodeVisitor
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

    /**
     * What a quantified node explains to at a level: a quantifier asks for
     * its child at two levels, and without this every level of nesting
     * doubled the work.
     *
     * Keyed by the node itself, so an entry goes with its tree.
     *
     * @var \WeakMap<\PhpRegex\Parser\Node\NodeInterface, array<int, string>>|null
     */
    private ?\WeakMap $explained = null;

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
        $quantExplain = $this->explainQuantifierValue($node->quantifier, $node->type->value);

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

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): string
    {
        return $this->line('Extended character class: one character of '.ltrim($node->expression->accept($this)));
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): string
    {
        $right = ltrim($node->right->accept($this));
        if (null === $node->left) {
            return 'not '.$right;
        }

        $word = match ($node->operator) {
            ClassSetOperator::Intersection => 'and',
            ClassSetOperator::Difference => 'but not',
            ClassSetOperator::SymmetricDifference => 'or else',
            default => 'or',
        };

        return \sprintf('(%s %s %s)', ltrim($node->left->accept($this)), $word, $right);
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

        $this->indentLevel++;
        $content = $node->content->accept($this);
        $this->indentLevel--;

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
            CharLiteralType::Unicode => $this->line('Character with hexadecimal value 0x'.$this->formatUnicodeHexValue($node)),
            CharLiteralType::UnicodeNamed => $this->line('Unicode named character: '.$this->extractCharLiteralDetail($node)),
            CharLiteralType::Octal => $this->line('Character with octal value '.$this->formatOctalValue($node)),
            CharLiteralType::OctalLegacy => $this->line('Character with octal value '.$this->formatLegacyOctalValue($node->originalRepresentation)),
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
        if (CharLiteralType::UnicodeNamed === $node->type) {
            if (preg_match('/^\\\\N\\{(.+)}$/', $node->originalRepresentation, $matches)) {
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
