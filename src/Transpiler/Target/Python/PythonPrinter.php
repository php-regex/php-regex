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

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassSetProvider;
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
use PHPRegex\Parser\Text\Ascii;
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

    private int $groupCount = 0;

    /**
     * How many lookbehinds the printer stands in.
     */
    private int $lookbehinds = 0;

    private string $source = '';

    /**
     * @var array<int, true> the groups whose closing parenthesis has been printed
     */
    private array $closed = [];

    /**
     * @var array<string, int>
     */
    private array $names = [];

    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        $this->enterPattern($node);
        $this->source = $node->source ?? '';

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
            $result = $alternatives[0]->accept($this);
            for ($i = 1, $count = \count($alternatives); $i < $count; $i++) {
                $result .= $alternatives[$i]->accept($this);
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
        return $this->printSequence($node->children);
    }

    #[\Override]
    public function visitGroup(GroupNode $node): string
    {
        // An option set inside a group ends with it.
        $scope = $this->scope();
        if (GroupType::InlineFlags === $node->type) {
            if (!$this->applyInlineFlags((string) $node->flags, 'imsxU', true)) {
                return $this->unsupported('The inline flags (?'.(string) $node->flags.') cannot be carried into Python re.', $node);
            }

            if (self::isBareOptionSetting($node)) {
                return '';
            }

            $child = $node->child->accept($this);
            $this->restoreScope($scope);

            return '(?:'.$child.')';
        }

        $this->refuseNonAtomic($node, 'Python re');
        if (GroupType::Atomic === $node->type) {
            return $this->unsupported('Atomic groups are not supported in Python re.', $node);
        }

        $number = null;
        if (GroupType::Capturing === $node->type || GroupType::Named === $node->type) {
            $number = ++$this->groupCount;
            if (null !== $node->name) {
                // Two groups share a name only under (?J), which is refused.
                $this->names[$node->name] = $number;
            }
        }

        $lookbehind = GroupType::LookbehindPositive === $node->type || GroupType::LookbehindNegative === $node->type;
        $this->lookbehinds += $lookbehind ? 1 : 0;

        try {
            if ($lookbehind && $node->child instanceof AlternationNode) {
                return $this->splitLookbehind($node, $node->child, $scope);
            }

            $child = $node->child->accept($this);
        } finally {
            $this->lookbehinds -= $lookbehind ? 1 : 0;
        }

        $this->restoreScope($scope);
        if (null !== $number) {
            $this->closed[$number] = true;
        }

        $name = $node->name ?? '';

        return match ($node->type) {
            GroupType::Capturing => '('.$child.')',
            GroupType::Named => '(?P<'.$name.'>'.$child.')',
            GroupType::LookaheadPositive => '(?='.$child.')',
            GroupType::LookaheadNegative => '(?!'.$child.')',
            GroupType::LookbehindPositive => '(?<='.$child.')',
            GroupType::LookbehindNegative => '(?<!'.$child.')',
            GroupType::BranchReset => $this->unsupported('Branch reset groups are not supported in Python re.', $node),
            GroupType::ScanSubstring => $this->unsupported('Substring scans are not supported in Python re.', $node),
            default => '(?:'.$child.')',
        };
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): string
    {
        if (QuantifierType::Possessive === $node->type) {
            return $this->unsupported('Possessive quantifiers are not supported in Python standard re module.', $node);
        }

        $assertion = $this->repeatedAssertion($node);
        if (null !== $assertion) {
            return $assertion;
        }

        $nodeCompiled = $node->node->accept($this);

        if ($node->node instanceof SequenceNode || $node->node instanceof AlternationNode) {
            $nodeCompiled = '(?:'.$nodeCompiled.')';
        }

        return $nodeCompiled.$this->normalizeQuantifier($node->quantifier).$this->lazySuffix($node);
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

        $this->refuseALoneByte($node->value, $node);
        if ($this->inCharClass) {
            return $this->escapeString($node->value);
        }

        if (!$this->caseless || !$this->unicode || 1 !== LibraryPcre::match('/[Iiİı]/u', $node->value)) {
            return $this->caseScoped($this->escapeString($node->value));
        }

        $written = '';
        $run = '';
        foreach (mb_str_split($node->value, 1, 'UTF-8') as $character) {
            if (!\in_array($character, ['I', 'i', 'İ', 'ı'], true)) {
                $run .= $character;

                continue;
            }

            $written .= ('' === $run ? '' : $this->caseScoped($this->escapeString($run))).$this->foldedLikePcre($this->escapeString($character), \sprintf('\x{%X}', mb_ord($character, 'UTF-8')), $node);
            $run = '';
        }

        return $written.('' === $run ? '' : $this->caseScoped($this->escapeString($run)));
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): string
    {
        $codePoint = $node->codePoint;

        if (CharLiteralType::UnicodeNamed === $node->type) {
            if ($codePoint < 0) {
                return $this->unsupported('Unicode named character escape with unknown name cannot be converted.', $node);
            }

            if (1 !== LibraryPcre::match('/^\\\\N\\{[ \\t]*U\\+/', $node->originalRepresentation)) {
                return $this->unsupported('PCRE2 does not support \N{name}: write the character as \N{U+hhhh}.', $node);
            }

            $this->context->addWarning('Converted Unicode named character to code point escape.');
        }

        $this->refuseAByteEscape($codePoint, $node);

        if ($this->inCharClass) {
            return $this->formatCodePoint($codePoint);
        }

        return $this->foldedLikePcre($this->formatCodePoint($codePoint), \sprintf('\x{%X}', $codePoint), $node);
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): string
    {
        if ('h' === $node->value || 'v' === $node->value) {
            $this->context->addNote('Converted \\'.$node->value.' to character class.');
            $set = 'h' === $node->value ? self::HORIZONTAL_SPACES : self::VERTICAL_SPACES;

            return $this->inCharClass ? $set : '['.$set.']';
        }

        if (!\in_array($node->value, self::SUPPORTED_CHAR_TYPES, true)) {
            return $this->unsupported('Unsupported character type in Python: \\'.$node->value.'.', $node);
        }

        // Under u PHP compiles with UCP: "\d" is Python's, "\s" is written
        // out, and Python has no way to write "\w" as UCP reads it.
        if ($this->unicode && ('s' === $node->value || 'S' === $node->value)) {
            return $this->inCharClass && 's' === $node->value ? self::UNICODE_SPACES : '['.('S' === $node->value ? '^' : '').self::UNICODE_SPACES.']';
        }

        if ($this->unicode && ('w' === $node->value || 'W' === $node->value)) {
            return $this->refuseUnicodeWord($node->value, $node);
        }

        return '\\'.$node->value;
    }

    #[\Override]
    public function visitDot(DotNode $node): string
    {
        $dotAllFlag = str_contains($this->patternFlags, 's');
        if ($this->dotAll) {
            return $dotAllFlag ? '.' : '[\s\S]';
        }

        return $dotAllFlag ? '[^\n]' : '.';
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): string
    {
        // Under m a line starts after "\n", but not after one that ends the subject.
        if ('^' === $node->value) {
            return $this->multiline ? '(?:^|(?<=\n)(?!\Z))' : '^';
        }

        if ($this->multiline) {
            return '(?=\n|\Z)';
        }

        // Python's "$" is PCRE's: the end, or before a newline that ends the subject.
        return $this->dollarEndOnly ? '\Z' : '$';
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): string
    {
        // Python's "\Z" is PCRE's "\z"; before 3.14 its "\B" fails on the empty subject.
        return match ($node->value) {
            'A' => '\A',
            'z' => '\Z',
            'Z' => '(?=\n?\Z)',
            'b' => $this->unicode ? $this->refuseUnicodeWord($node->value, $node) : '\b',
            'B' => $this->unicode ? $this->refuseUnicodeWord($node->value, $node) : '(?:\B|\A\Z)',
            default => $this->unsupported('\\'.$node->value.' is not supported in Python re.', $node),
        };
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
            $members = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
            $kept = array_values(array_filter($members, static fn (NodeInterface $member): bool => !$member instanceof CharTypeNode || 'S' !== $member->value));
            if ($this->unicode && \count($kept) < \count($members)) {
                $written = '';
                foreach ($kept as $member) {
                    $written .= $member->accept($this);
                }

                $class = self::composedClass($written, [self::UNICODE_SPACES], $node->isNegated);
            } else {
                $class = '['.($node->isNegated ? '^' : '').$node->expression->accept($this).']';
            }

            return $wasInCharClass ? $class : $this->foldedLikePcre($class, substr($this->source, $node->getStartPosition(), $node->getEndPosition() - $node->getStartPosition()), $node);
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
        if ($this->lookbehinds > 0) {
            return $this->unsupported('A backreference inside a lookbehind cannot be carried into Python re, which needs a lookbehind of a fixed length.', $node);
        }

        if ($this->caseless && $this->unicode) {
            return $this->unsupported('A backreference under /i and u cannot be carried into Python re: it matches "I", "i", "İ" and "ı" with one another, where PCRE pairs "I" with "i" alone.', $node);
        }

        $reference = $this->normalizeBackreference($node->ref, $node->getStartPosition());

        return $this->caseScoped($reference);
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): string
    {
        return $this->formatCodePoint($node->codePoint);
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
        // A comment of /x or "(?x)" ends at the line's end and may hold a ")".
        $this->context->addNote('Dropped comments during transpilation.');

        return '';
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
                    default => '\\x'.Ascii::upper(str_pad(dechex($ord), 2, '0', \STR_PAD_LEFT)),
                };
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    /**
     * The atom with the case rule in force where it stands, when that rule
     * is not the pattern's: "(?i:b)" under "(?i)", "(?-i:b)" under "(?-i)".
     */
    private function caseScoped(string $atom): string
    {
        if ($this->caseless === str_contains($this->patternFlags, 'i')) {
            return $atom;
        }

        return ($this->caseless ? '(?i:' : '(?-i:').$atom.')';
    }

    /**
     * A caseless atom as PCRE reads it under u. Python's IGNORECASE matches
     * "I", "i", "İ" (U+0130) and "ı" (U+0131) with one another, where PCRE
     * pairs "I" with "i" and leaves the other two alone: an atom whose
     * characters include one of them, in either case, is written with the
     * characters PCRE takes for it, read case-sensitively.
     *
     * @param string $printed the atom as Python writes it
     * @param string $atom    the atom as PCRE writes it, asked of the running PCRE
     *
     * @throws TranspileException
     */
    private function foldedLikePcre(string $printed, string $atom, NodeInterface $node): string
    {
        if (!$this->caseless || !$this->unicode) {
            return $this->caseScoped($printed);
        }

        $sensitive = ClassSetProvider::query($atom, true, '', '', true);
        $caseless = ClassSetProvider::query($atom, true, 'i', '', true);
        if (null === $sensitive || null === $caseless) {
            return $this->unsupported('PCRE cannot tell which characters '.$atom.' matches under /i.', $node);
        }

        // "I" and "i" fold together in PCRE: "i" in either set stands for both.
        $dottedI = CharSet::fromRanges([[0x69, 0x69], [0x130, 0x131]]);
        if ($sensitive->intersect($dottedI)->isEmpty() && $caseless->intersect($dottedI)->isEmpty()) {
            return $this->caseScoped($printed);
        }

        $complement = CharSet::universe(true)->subtract($caseless);
        $class = match (true) {
            $caseless->isEmpty() => '(?!)',
            $complement->isEmpty() => '[\s\S]',
            \count($complement->ranges) < \count($caseless->ranges) => '[^'.$this->rangesOf($complement).']',
            default => '['.$this->rangesOf($caseless).']',
        };

        return str_contains($this->patternFlags, 'i') ? '(?-i:'.$class.')' : $class;
    }

    /**
     * The characters of a set, written inside a class: ranges joined by
     * "-", a letter or a digit as itself, anything else as an escape.
     */
    private function rangesOf(CharSet $set): string
    {
        $written = '';
        foreach ($set->ranges as [$from, $to]) {
            $written .= $this->classCharacter($from).($to > $from + 1 ? '-' : '').($to > $from ? $this->classCharacter($to) : '');
        }

        return $written;
    }

    private function classCharacter(int $codePoint): string
    {
        return $codePoint < 0x80 && Ascii::isAlnum(\chr($codePoint)) ? \chr($codePoint) : $this->formatCodePoint($codePoint);
    }

    /**
     * A lookbehind over alternatives of different lengths, which PCRE takes
     * and Python does not, as one lookbehind per alternative.
     *
     * @param array{bool, bool, bool, bool} $scope
     */
    private function splitLookbehind(GroupNode $node, AlternationNode $alternation, array $scope): string
    {
        $positive = GroupType::LookbehindPositive === $node->type;
        $written = [];
        foreach ($alternation->alternatives as $alternative) {
            $written[] = ($positive ? '(?<=' : '(?<!').$alternative->accept($this).')';
        }

        $this->restoreScope($scope);

        return $positive ? '(?:'.implode('|', $written).')' : implode('', $written);
    }

    /**
     * @throws TranspileException
     */
    private function refuseUnicodeWord(string $type, NodeInterface $node): string
    {
        return $this->unsupported('Python re reads \\'.$type.' otherwise than PCRE under u, which reads a word character with UCP: combining marks and connector punctuation are word characters to PCRE alone.', $node);
    }

    private function formatCodePoint(int $codePoint): string
    {
        if ($codePoint <= 0xFF) {
            return '\\x'.Ascii::upper(str_pad(dechex($codePoint), 2, '0', \STR_PAD_LEFT));
        }
        if ($codePoint <= 0xFFFF) {
            return '\\u'.Ascii::upper(str_pad(dechex($codePoint), 4, '0', \STR_PAD_LEFT));
        }

        return '\\U'.Ascii::upper(str_pad(dechex($codePoint), 8, '0', \STR_PAD_LEFT));
    }

    /**
     * Python refuses a reference to a group still open or not yet opened,
     * which PCRE reads as one to an earlier repetition's capture.
     */
    private function normalizeBackreference(string $ref, int $position): string
    {
        if (LibraryPcre::match('/^\\\\g?\{?([1-9]\\d*)\}?$/', $ref, $matches)) {
            $number = (int) $matches[1];
            $reference = '\\'.$matches[1];
        } elseif (LibraryPcre::match('/^\\\\k[<{\']([a-zA-Z0-9_]+)[>}\']$/', $ref, $matches)) {
            $number = $this->names[$matches[1]] ?? 0;
            $reference = '(?P='.$matches[1].')';
        } else {
            throw new TranspileException('Unsupported backreference syntax for Python: '.$ref.'.', $position, $this->context->sourcePattern);
        }

        if (!isset($this->closed[$number])) {
            throw new TranspileException('A backreference to a group that has not closed before it cannot be carried into Python re.', $position, $this->context->sourcePattern);
        }

        return $reference;
    }
}
