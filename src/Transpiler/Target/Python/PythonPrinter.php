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

namespace PHPRegex\Transpiler\Target\Python;

use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Transpiler\Target\AbstractTargetPrinter;
use PHPRegex\Transpiler\TranspileException;

/**
 * Compiles PCRE AST nodes into Python 're' compatible regex source.
 *
 * @internal
 */
final class PythonPrinter extends AbstractTargetPrinter
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

    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
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
            $result = $this->compileCharClassNode($alternatives[0]);
            for ($i = 1, $count = \count($alternatives); $i < $count; $i++) {
                $result .= $this->compileCharClassNode($alternatives[$i]);
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
            $result = $this->compileCharClassNode($children[0]);
            for ($i = 1, $count = \count($children); $i < $count; $i++) {
                $result .= $this->compileCharClassNode($children[$i]);
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
            GroupType::Named => '(?P<'.$node->name.'>'.$child.')',
            GroupType::LookaheadPositive => '(?='.$child.')',
            GroupType::LookaheadNegative => '(?!'.$child.')',
            GroupType::LookbehindPositive => '(?<='.$child.')',
            GroupType::LookbehindNegative => '(?<!'.$child.')',
            GroupType::Atomic => '(?=(?P<tmp>'.$child.'))(?P=tmp)', // Atomic group emulation in Python
            GroupType::InlineFlags => '(?'.$node->flags.')', // Partially supported if simple
            GroupType::BranchReset => $this->unsupported('Branch reset groups are not supported in Python re.', $node),
            GroupType::ScanSubstring => $this->unsupported('Substring scans are not supported in Python re.', $node),
        };
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): string
    {
        if (QuantifierType::Possessive === $node->type) {
            return $this->unsupported('Possessive quantifiers are not supported in Python standard re module.', $node);
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

        return $this->formatCodePoint($codePoint);
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): string
    {
        if ('h' === $node->value) {
            $this->context->addNote('Converted \h to character class.');

            return '[\x09\x20\xA0\u1680\u180e\u2000-\u200a\u202f\u205f\u3000]';
        }

        if ('v' === $node->value) {
            $this->context->addNote('Converted \v to character class.');

            return '[\x0A-\x0D\x85\u2028\u2029]';
        }

        if (!\in_array($node->value, self::SUPPORTED_CHAR_TYPES, true)) {
            return $this->unsupported('Unsupported character type in Python: \\'.$node->value.'.', $node);
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
        return '\\'.$node->value;
    }

    #[\Override]
    public function visitKeep(KeepNode $node): string
    {
        return $this->unsupported('\\K is not supported in Python re.', $node);
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
        $ord = \ord(strtoupper($node->char)) - 64;

        return $this->formatCodePoint($ord);
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): string
    {
        return $this->unsupported('Script runs are not supported in Python re.', $node);
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): string
    {
        return $this->unsupported('Version conditions are not supported in Python re.', $node);
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): string
    {
        return $this->unsupported('Unicode properties (\p) are not supported in Python standard re module.', $node);
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): string
    {
        return $this->unsupported('POSIX character classes are not supported in Python re.', $node);
    }

    #[\Override]
    public function visitComment(CommentNode $node): string
    {
        return '(?#'.$node->comment.')';
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): string
    {
        return $this->unsupported('Conditional subpatterns are not supported in Python re.', $node);
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): string
    {
        return $this->unsupported('Subroutine calls are not supported in Python re.', $node);
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): string
    {
        return $this->unsupported('PCRE verbs are not supported in Python re.', $node);
    }

    #[\Override]
    public function visitDefine(DefineNode $node): string
    {
        return $this->unsupported('DEFINE subpatterns are not supported in Python re.', $node);
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): string
    {
        return $this->unsupported('LIMIT_MATCH is not supported in Python re.', $node);
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): string
    {
        return $this->unsupported('Callouts are not supported in Python re.', $node);
    }

    private function escapeString(string $value): string
    {
        $meta = $this->inCharClass ? self::CHAR_CLASS_META : self::META_CHARACTERS;
        $needsEscape = false;

        $len = \strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];
            $ord = \ord($char);
            if (isset($meta[$char]) || $ord < 32 || 127 === $ord) {
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
            if (isset($meta[$char])) {
                $result .= '\\'.$char;
            } elseif ($ord < 32 || 127 === $ord) {
                $result .= match ($ord) {
                    9 => '\\t',
                    10 => '\\n',
                    13 => '\\r',
                    12 => '\\f',
                    default => '\\x'.strtoupper(str_pad(dechex($ord), 2, '0', \STR_PAD_LEFT)),
                };
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    private function compileCharClassNode(NodeInterface $node): string
    {
        return $node->accept($this);
    }

    private function formatCodePoint(int $codePoint): string
    {
        if ($codePoint <= 0xFF) {
            return '\\x'.strtoupper(str_pad(dechex($codePoint), 2, '0', \STR_PAD_LEFT));
        }
        if ($codePoint <= 0xFFFF) {
            return '\\u'.strtoupper(str_pad(dechex($codePoint), 4, '0', \STR_PAD_LEFT));
        }

        return '\\U'.strtoupper(str_pad(dechex($codePoint), 8, '0', \STR_PAD_LEFT));
    }

    private function normalizeBackreference(string $ref, int $position): string
    {
        if (LibraryPcre::match('/^\\\\([1-9]\\d*)$/', $ref, $matches)) {
            return '\\'.$matches[1];
        }

        if (LibraryPcre::match('/^\\\\k<([a-zA-Z0-9_]+)>$/', $ref, $matches)) {
            return '(?P='.$matches[1].')';
        }

        if (LibraryPcre::match('/^\\\\g\{?([1-9]\\d*)\}?$/', $ref, $matches)) {
            return '\\'.$matches[1];
        }

        throw new TranspileException(
            'Unsupported backreference syntax for Python: '.$ref.'.',
            $position,
            $this->context->sourcePattern,
        );
    }
}
