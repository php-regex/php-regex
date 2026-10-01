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

namespace PhpRegex\Transpiler\Target\JavaScript;

use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\ControlCharNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\UnicodePropNode;
use PhpRegex\Parser\Node\VersionConditionNode;
use PhpRegex\Transpiler\Target\AbstractTargetPrinter;
use PhpRegex\Transpiler\TranspileContext;
use PhpRegex\Transpiler\TranspileException;

/**
 * Compiles PCRE AST nodes into JavaScript-compatible regex source.
 */
final class JavaScriptPrinter extends AbstractTargetPrinter
{
    private const META_CHARACTERS = [
        '\\' => true, '.' => true, '^' => true, '$' => true,
        '[' => true, ']' => true, '(' => true, ')' => true,
        '|' => true, '*' => true, '+' => true, '?' => true, '{' => true, '}' => true,
    ];

    private const CHAR_CLASS_META = [
        '\\' => true, ']' => true, '-' => true, '^' => true, '[' => true,
    ];

    private const SUPPORTED_CHAR_TYPES = ['d', 's', 'w', 'D', 'S', 'W'];

    private bool $inCharClass = false;

    private bool $commentWarningEmitted = false;

    private string $flags;

    public function __construct(
        TranspileContext $context,
        private readonly bool $allowLookbehind,
        private readonly string $delimiter,
    ) {
        parent::__construct($context);

        $this->flags = $context->sourceFlags;
    }

    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        $this->flags = $node->flags;

        return $node->pattern->accept($this);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): string
    {
        $alternatives = $node->alternatives;
        if ([] === $alternatives) {
            return '';
        }

        if ($this->inCharClass) {
            $result = $this->compileCharClassNode($alternatives[0], $alternatives[1] ?? null);
            for ($i = 1, $count = \count($alternatives); $i < $count; $i++) {
                $result .= $this->compileCharClassNode($alternatives[$i], $alternatives[$i + 1] ?? null);
            }

            return $result;
        }

        $result = $alternatives[0]->accept($this);
        for ($i = 1, $count = \count($alternatives); $i < $count; $i++) {
            $result .= '|'.$alternatives[$i]->accept($this);
        }

        return $result;
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): string
    {
        $children = $node->children;
        if ([] === $children) {
            return '';
        }

        if ($this->inCharClass) {
            $result = $this->compileCharClassNode($children[0], $children[1] ?? null);
            for ($i = 1, $count = \count($children); $i < $count; $i++) {
                $result .= $this->compileCharClassNode($children[$i], $children[$i + 1] ?? null);
            }

            return $result;
        }

        $result = $children[0]->accept($this);
        for ($i = 1, $count = \count($children); $i < $count; $i++) {
            $result .= $children[$i]->accept($this);
        }

        return $result;
    }

    #[\Override]
    public function visitGroup(GroupNode $node): string
    {
        $child = $node->child->accept($this);

        return match ($node->type) {
            GroupType::Capturing => '('.$child.')',
            GroupType::NonCapturing => '(?:'.$child.')',
            GroupType::Named => '(?<'.$node->name.'>'.$child.')',
            GroupType::LookaheadPositive => '(?='.$child.')',
            GroupType::LookaheadNegative => '(?!'.$child.')',
            GroupType::LookbehindPositive => $this->compileLookbehind('(?<=', $child, $node),
            GroupType::LookbehindNegative => $this->compileLookbehind('(?<!', $child, $node),
            GroupType::InlineFlags => $this->unsupported('Inline flags groups are not supported in JavaScript.', $node),
            GroupType::Atomic => $this->unsupported('Atomic groups are not supported in JavaScript.', $node),
            GroupType::BranchReset => $this->unsupported('Branch reset groups are not supported in JavaScript.', $node),
            GroupType::ScanSubstring => $this->unsupported('Substring scans are not supported in JavaScript.', $node),
        };
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): string
    {
        if (QuantifierType::Possessive === $node->type) {
            return $this->unsupported('Possessive quantifiers are not supported in JavaScript.', $node);
        }

        $nodeCompiled = $node->node->accept($this);

        if ($node->node instanceof SequenceNode || $node->node instanceof AlternationNode) {
            $nodeCompiled = '(?:'.$nodeCompiled.')';
        }

        $suffix = QuantifierType::Lazy === $node->type ? '?' : '';
        $quantifier = $this->normalizeQuantifier($node->quantifier);

        return $nodeCompiled.$quantifier.$suffix;
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): string
    {
        if ('' === $node->value) {
            return '';
        }

        if ($node->isRaw) {
            return $node->value;
        }

        return $this->escapeString($node->value);
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): string
    {
        $codePoint = $node->codePoint;

        if (CharLiteralType::UnicodeNamed === $node->type) {
            $this->context->addWarning('Converted Unicode named character to code point escape.');
        }

        if (CharLiteralType::Octal === $node->type || CharLiteralType::OctalLegacy === $node->type) {
            $this->context->addWarning('Converted octal escape to hex/Unicode escape for JavaScript.');
        }

        return $this->formatCodePoint($codePoint);
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): string
    {
        if ('h' === $node->value) {
            $this->context->addNote('Converted \h (horizontal whitespace) to character class.');

            return '[\x09\x20\xA0\u1680\u180e\u2000-\u200a\u202f\u205f\u3000]';
        }

        if ('v' === $node->value) {
            $this->context->addNote('Converted \v (vertical whitespace) to character class.');

            return '[\x0A-\x0D\x85\u2028\u2029]';
        }

        if (!\in_array($node->value, self::SUPPORTED_CHAR_TYPES, true)) {
            return $this->unsupported('Unsupported character type in JavaScript: \\'.$node->value.'.', $node);
        }

        if ('w' === $node->value || 'W' === $node->value) {
            $this->noteUnicodeWordBoundary();
        }

        return '\\'.$node->value;
    }

    #[\Override]
    public function visitDot(DotNode $node): string
    {
        return '.';
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): string
    {
        return $node->value;
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): string
    {
        if ('b' === $node->value || 'B' === $node->value) {
            $this->noteUnicodeWordBoundary();

            return '\\'.$node->value;
        }

        return $this->unsupported('Unsupported assertion in JavaScript: \\'.$node->value.'.', $node);
    }

    #[\Override]
    public function visitKeep(KeepNode $node): string
    {
        return $this->unsupported('\\K is not supported in JavaScript.', $node);
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): string
    {
        $wasInCharClass = $this->inCharClass;
        $this->inCharClass = true;

        try {
            $negation = $node->isNegated ? '^' : '';

            return '['.$negation.$node->expression->accept($this).']';
        } finally {
            $this->inCharClass = $wasInCharClass;
        }
    }

    #[\Override]
    public function visitRange(RangeNode $node): string
    {
        return $node->start->accept($this).'-'.$node->end->accept($this);
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): string
    {
        return $this->normalizeBackreference($node->ref, $node->getStartPosition());
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): string
    {
        return '\\c'.$node->char;
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): string
    {
        return $this->unsupported('Script run annotations are not supported in JavaScript.', $node);
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): string
    {
        return $this->unsupported('Version conditions are not supported in JavaScript.', $node);
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): string
    {
        $prop = $node->hasBraces ? trim($node->prop, '{}') : $node->prop;
        $isNegated = str_starts_with($prop, '^');
        $prop = ltrim($prop, '^');

        if ('' === $prop) {
            return $this->unsupported('Empty Unicode property is not supported in JavaScript.', $node);
        }

        $this->context->requireFlag('u', 'Added /u for Unicode property escapes.');

        return $isNegated ? '\\P{'.$prop.'}' : '\\p{'.$prop.'}';
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): string
    {
        return $this->unsupported('POSIX character classes are not supported in JavaScript.', $node);
    }

    #[\Override]
    public function visitComment(CommentNode $node): string
    {
        if (!str_contains($this->flags, 'x')) {
            return $this->unsupported('Inline comments are not supported in JavaScript.', $node);
        }

        if (!$this->commentWarningEmitted) {
            $this->commentWarningEmitted = true;
            $this->context->addNote('Dropped /x comments during transpilation.');
        }

        return '';
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): string
    {
        return $this->unsupported('Conditional subpatterns are not supported in JavaScript.', $node);
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): string
    {
        return $this->unsupported('Subroutine calls are not supported in JavaScript.', $node);
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): string
    {
        return $this->unsupported('PCRE verbs are not supported in JavaScript.', $node);
    }

    #[\Override]
    public function visitDefine(DefineNode $node): string
    {
        return $this->unsupported('DEFINE subpatterns are not supported in JavaScript.', $node);
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): string
    {
        return $this->unsupported('LIMIT_MATCH is not supported in JavaScript.', $node);
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): string
    {
        return $this->unsupported('Callouts are not supported in JavaScript.', $node);
    }

    private function compileLookbehind(string $prefix, string $child, GroupNode $node): string
    {
        if (!$this->allowLookbehind) {
            return $this->unsupported('Lookbehind is disabled for JavaScript targets.', $node);
        }

        return $prefix.$child.')';
    }

    private function escapeString(string $value): string
    {
        if (!$this->inCharClass && preg_match('/^\\{\\d+(?:,\\d*)?\\}$/', $value)) {
            return $value;
        }

        $meta = $this->inCharClass ? self::CHAR_CLASS_META : self::META_CHARACTERS;
        $unicodeMode = $this->isUnicodeMode();
        $needsEscape = false;

        $len = \strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];
            $ord = \ord($char);
            if (
                $char === $this->delimiter
                || isset($meta[$char])
                || $ord < 32
                || 127 === $ord
                || (!$unicodeMode && $ord >= 128)
            ) {
                $needsEscape = true;

                break;
            }
        }

        if (!$needsEscape) {
            return $value;
        }

        $result = '';
        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];
            $ord = \ord($char);
            if ($char === $this->delimiter || isset($meta[$char])) {
                $result .= '\\'.$char;
            } elseif ($ord < 32 || 127 === $ord || (!$unicodeMode && $ord >= 128)) {
                $result .= match ($ord) {
                    8 => $this->inCharClass ? '\\b' : '\\x08',
                    9 => '\\t',
                    10 => '\\n',
                    13 => '\\r',
                    12 => '\\f',
                    27 => '\\x1B',
                    default => '\\x'.strtoupper(str_pad(dechex($ord), 2, '0', \STR_PAD_LEFT)),
                };
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    private function compileCharClassNode(NodeInterface $node, ?NodeInterface $next): string
    {
        if ($node instanceof LiteralNode && '[' === $node->value) {
            return $this->shouldEscapeCharClassOpen($next) ? '\\[' : '[';
        }

        if ($node instanceof RangeNode) {
            $start = $node->start;
            $startCompiled = $start instanceof LiteralNode && '[' === $start->value
                ? '['
                : $start->accept($this);

            return $startCompiled.'-'.$node->end->accept($this);
        }

        return $node->accept($this);
    }

    private function shouldEscapeCharClassOpen(?NodeInterface $next): bool
    {
        if (!$next instanceof LiteralNode) {
            return false;
        }

        return \in_array($next->value, [':', '.', '='], true);
    }

    private function formatCodePoint(int $codePoint): string
    {
        if ($codePoint <= 0xFF) {
            return '\\x'.strtoupper(str_pad(dechex($codePoint), 2, '0', \STR_PAD_LEFT));
        }

        if ($codePoint <= 0xFFFF) {
            return '\\u'.strtoupper(str_pad(dechex($codePoint), 4, '0', \STR_PAD_LEFT));
        }

        $this->context->requireFlag('u', 'Added /u for Unicode code point escapes.');

        return '\\u{'.strtoupper(dechex($codePoint)).'}';
    }

    private function normalizeBackreference(string $ref, int $position): string
    {
        if (
            preg_match('/^\\\\g([+-]\\d+)$/', $ref, $matches)
            || preg_match('/^\\\\g\\{([+-]\\d+)\\}$/', $ref, $matches)
        ) {
            throw new TranspileException(
                'Relative backreferences are not supported in JavaScript: '.$matches[0].'.',
                $position,
                $this->context->sourcePattern,
            );
        }

        if (preg_match('/^\\\\g\\{?([0-9]+)\\}?$/', $ref, $matches)) {
            return '\\'.$matches[1];
        }

        if (preg_match('/^\\\\k\\{([a-zA-Z0-9_]+)\\}$/', $ref, $matches)) {
            return '\\k<'.$matches[1].'>';
        }

        if (preg_match('/^\\\\k<([a-zA-Z0-9_]+)>$/', $ref)) {
            return $ref;
        }

        if (preg_match('/^\\\\[1-9]\\d*$/', $ref)) {
            return $ref;
        }

        throw new TranspileException(
            'Unsupported backreference syntax for JavaScript: '.$ref.'.',
            $position,
            $this->context->sourcePattern,
        );
    }

    private function isUnicodeMode(): bool
    {
        return str_contains($this->flags, 'u') || $this->context->requiresFlag('u');
    }

    private function noteUnicodeWordBoundary(): void
    {
        $this->context->addNote('JavaScript \\w and \\b are ASCII-based; Unicode word boundaries may differ.');
    }
}
