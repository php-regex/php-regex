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

namespace PHPRegex\Transpiler\Target\JavaScript;

use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\Hir\Utf8;
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
use PHPRegex\Transpiler\TranspileContext;
use PHPRegex\Transpiler\TranspileException;

/**
 * Compiles PCRE AST nodes into JavaScript-compatible regex source.
 *
 * @internal
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

    /**
     * What a class escapes under the v flag: the syntax characters, "/",
     * "-", and the reserved punctuators, every one a legal escape there.
     */
    private const CLASS_SET_META = [
        '\\' => true, ']' => true, '-' => true, '^' => true, '[' => true, '(' => true, ')' => true,
        '{' => true, '}' => true, '/' => true, '|' => true, '$' => true, '.' => true, '*' => true,
        '+' => true, '?' => true, '&' => true, '!' => true, '#' => true, '%' => true, ',' => true,
        ':' => true, ';' => true, '<' => true, '=' => true, '>' => true, '@' => true, '`' => true, '~' => true,
    ];

    private const SUPPORTED_CHAR_TYPES = ['d', 's', 'w', 'D', 'S', 'W'];

    private bool $inCharClass = false;

    private bool $commentWarningEmitted = false;

    private string $flags;

    /**
     * Whether /i holds where the printer stands, under the v flag only.
     */
    private bool $caseless = false;

    /**
     * Whether /U holds where the printer stands: greedy and lazy swap.
     */
    private bool $ungreedy = false;

    private bool $unicode = false;

    private bool $unicodeFlag = false;

    private string $source = '';

    /**
     * @param bool $unicodeSets print for the v flag (the HTML pattern attribute): classes escape
     *                          what the v flag reserves, "\A" reads as "^", "\z" and "\Z" as "$",
     *                          and /i or "(?i)" is spelled out, each atom written with the
     *                          characters PCRE takes for it caselessly, as "[kK]"
     */
    public function __construct(
        TranspileContext $context,
        private readonly bool $allowLookbehind,
        private readonly string $delimiter,
        private readonly bool $unicodeSets = false,
    ) {
        parent::__construct($context);

        $this->flags = $context->sourceFlags;
    }

    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        $this->flags = $node->flags;
        $this->ungreedy = str_contains($node->flags, 'U');
        if ($this->unicodeSets) {
            $this->caseless = str_contains($node->flags, 'i');
            $this->unicode = $node->isUnicode();
            $this->unicodeFlag = str_contains($node->flags, 'u');
            $this->source = $node->source ?? '';
        }

        return $node->pattern->accept($this);
    }

    /**
     * Whether the group is a bare "(?flags)", which sets its flags for what
     * follows it up to the end of the enclosing group, the alternatives
     * after it included. "(?i:)" holds the same empty child one byte longer.
     */
    public static function isBareOptionSetting(NodeInterface $node): bool
    {
        return $node instanceof GroupNode
            && GroupType::InlineFlags === $node->type
            && $node->getEndPosition() - $node->getStartPosition() === \strlen((string) $node->flags) + 3;
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
        // An option set inside a group ends with it.
        [$caseless, $ungreedy] = [$this->caseless, $this->ungreedy];
        if (GroupType::InlineFlags === $node->type && $this->carriesInlineFlags((string) $node->flags, $node)) {
            if (self::isBareOptionSetting($node)) {
                return '';
            }

            $child = $node->child->accept($this);
            [$this->caseless, $this->ungreedy] = [$caseless, $ungreedy];

            return '(?:'.$child.')';
        }

        $child = $node->child->accept($this);
        [$this->caseless, $this->ungreedy] = [$caseless, $ungreedy];

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

        $suffix = (QuantifierType::Lazy === $node->type) !== $this->ungreedy ? '?' : '';
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

        $this->refuseALoneByte($node->value, $node);

        if ($this->caseless && !$this->inCharClass) {
            return $this->caselessLiteral($node->value, $node);
        }

        return $this->escapeString($node->value);
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): string
    {
        $codePoint = $node->codePoint;
        $this->refuseAByteEscape($codePoint, $node);

        if (CharLiteralType::UnicodeNamed === $node->type) {
            $this->context->addWarning('Converted Unicode named character to code point escape.');
        }

        if (CharLiteralType::Octal === $node->type || CharLiteralType::OctalLegacy === $node->type) {
            $this->context->addWarning('Converted octal escape to hex/Unicode escape for JavaScript.');
        }

        if ($this->caseless && !$this->inCharClass) {
            return $this->caselessCharacter($codePoint, $this->formatCodePoint($codePoint), $node);
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

        // A field value holds no line break: "\Z" ends it as "\z" does.
        if ($this->unicodeSets && \in_array($node->value, ['A', 'z', 'Z'], true)) {
            return 'A' === $node->value ? '^' : '$';
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
            $body = $node->expression->accept($this);

            return $this->caseless && !$wasInCharClass ? $this->caselessClass($body, $node) : '['.$negation.$body.']';
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
        if ($this->caseless) {
            return $this->unsupported('A backreference under /i cannot be carried into the HTML pattern attribute: it would match its group\'s text in one case only.', $node);
        }

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
        $prop = $this->javaScriptProperty($prop, $node);
        $printed = $isNegated ? '\\P{'.$prop.'}' : '\\p{'.$prop.'}';

        return $this->caseless && !$this->inCharClass ? $this->caselessAtom($printed, $this->text($node), $node) : $printed;
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
        if (!$this->inCharClass && LibraryPcre::match('/^\\{\\d+(?:,\\d*)?\\}$/', $value)) {
            return $value;
        }

        $meta = $this->inCharClass ? ($this->unicodeSets ? self::CLASS_SET_META : self::CHAR_CLASS_META) : self::META_CHARACTERS;
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
            } elseif ($ord < 32 || 127 === $ord) {
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
            // The v flag reads a bare "[" in a class as a nested class.
            return $this->unicodeSets || $this->shouldEscapeCharClassOpen($next) ? '\\[' : '[';
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
            LibraryPcre::match('/^\\\\g([+-]\\d+)$/', $ref, $matches)
            || LibraryPcre::match('/^\\\\g\\{([+-]\\d+)\\}$/', $ref, $matches)
        ) {
            throw new TranspileException(
                'Relative backreferences are not supported in JavaScript: '.$matches[0].'.',
                $position,
                $this->context->sourcePattern,
            );
        }

        if (LibraryPcre::match('/^\\\\g\\{?([0-9]+)\\}?$/', $ref, $matches)) {
            return '\\'.$matches[1];
        }

        if (LibraryPcre::match('/^\\\\k\\{([a-zA-Z0-9_]+)\\}$/', $ref, $matches)
            || LibraryPcre::match("/^\\\\k'([a-zA-Z0-9_]+)'$/", $ref, $matches)
        ) {
            return '\\k<'.$matches[1].'>';
        }

        if (LibraryPcre::match('/^\\\\k<([a-zA-Z0-9_]+)>$/', $ref)) {
            return $ref;
        }

        if (LibraryPcre::match('/^\\\\[1-9]\\d*$/', $ref)) {
            return $ref;
        }

        throw new TranspileException(
            'Unsupported backreference syntax for JavaScript: '.$ref.'.',
            $position,
            $this->context->sourcePattern,
        );
    }

    /**
     * A property as JavaScript names it. PCRE2 reads a bare script name as
     * its Script_Extensions, "sc:" as its Script, and a name loosely;
     * JavaScript wants "Script_Extensions=Han" or "Script=Han", spelled as
     * Unicode does. Common and Inherited are their Script either way in
     * PCRE2. A general category or a binary property is kept as written.
     */
    private function javaScriptProperty(string $prop, UnicodePropNode $node): string
    {
        $separator = strcspn($prop, ':=');
        if ($separator === \strlen($prop)) {
            $script = ScriptNames::javaScriptName($prop);

            return null === $script ? $prop : self::scriptProperty($script, true);
        }

        $name = ScriptNames::looseKey(substr($prop, 0, $separator));
        $value = substr($prop, $separator + 1);
        $extensions = \in_array($name, ['scx', 'scriptextensions'], true);
        if (!$extensions && !\in_array($name, ['sc', 'script'], true)) {
            // PCRE2 also reads "bc:" and "bidiclass:", and nothing else.
            return $this->unsupported(\in_array($name, ['bc', 'bidiclass'], true) ? 'Bidi_Class properties are not supported in JavaScript.' : 'Unsupported Unicode property in JavaScript: '.$prop.'.', $node);
        }

        $script = ScriptNames::javaScriptName($value);
        if (null === $script) {
            return $this->unsupported('The script '.$value.' has no JavaScript name.', $node);
        }

        return self::scriptProperty($script, $extensions);
    }

    private static function scriptProperty(string $script, bool $extensions): string
    {
        return ($extensions && !\in_array($script, ['Common', 'Inherited'], true) ? 'Script_Extensions=' : 'Script=').$script;
    }

    /**
     * Applies an inline "(?flags)" the target can carry, and says whether it
     * can: a leading "^" takes i off (not U), then the letters before "-"
     * set and those after it unset. JavaScript carries "U", swapping greedy
     * and lazy; the HTML attribute also takes "i" spelled out, and "m" and
     * "s", which change nothing in a field value, and "x", which the parser
     * has applied. Under JavaScript any other letter is left to the group's
     * refusal.
     */
    private function carriesInlineFlags(string $flags, GroupNode $node): bool
    {
        if ('' !== trim($flags, $this->unicodeSets ? '^-imsxU' : '-U') || str_contains($flags, 'xx')) {
            if (!$this->unicodeSets) {
                return false;
            }

            throw new TranspileException('The HTML pattern attribute cannot carry the inline flags (?'.$flags.').', $node->getStartPosition(), $this->context->sourcePattern);
        }

        if (str_starts_with($flags, '^')) {
            $this->caseless = false;
        }

        [$set, $unset] = explode('-', ltrim($flags, '^').'-', 3);
        $this->caseless = (str_contains($set, 'i') || $this->caseless) && !str_contains($unset, 'i');
        $this->ungreedy = (str_contains($set, 'U') || $this->ungreedy) && !str_contains($unset, 'U');

        return true;
    }

    /**
     * A caseless literal, each character that PCRE takes in more than one
     * case written as a class of them: "ab" under /i is "[aA][bB]". Without
     * /u only an ASCII letter has a pair.
     */
    private function caselessLiteral(string $value, LiteralNode $node): string
    {
        $codePoints = Utf8::decode($value, $this->unicode) ?? [];
        $result = '';
        $run = '';
        $spelled = false;
        foreach ($codePoints as $codePoint) {
            $character = Utf8::character($codePoint, $this->unicode);
            $partners = $this->unicode || $codePoint < 0x80 ? $this->partners($codePoint, $node) : CharSet::empty();
            if ($partners->isEmpty()) {
                $run .= $character;

                continue;
            }

            $result .= ('' === $run ? '' : $this->escapeString($run)).'['.$this->classCharacter($codePoint, $character).$this->classSet($partners).']';
            $run = '';
            $spelled = true;
        }

        if (!$spelled) {
            return $this->escapeString($value);
        }

        $this->noteCaselessSpelled();

        return $result.('' === $run ? '' : $this->escapeString($run));
    }

    private function caselessCharacter(int $codePoint, string $printed, NodeInterface $node): string
    {
        $partners = $this->partners($codePoint, $node);
        if ($partners->isEmpty()) {
            return $printed;
        }

        $this->noteCaselessSpelled();

        return '['.$printed.$this->classSet($partners).']';
    }

    /**
     * The characters PCRE takes for the code point under /i besides itself.
     */
    private function partners(int $codePoint, NodeInterface $node): CharSet
    {
        return $this->caselessSets(\sprintf('\\x{%X}', $codePoint), $node)[1]->subtract(CharSet::single($codePoint));
    }

    /**
     * A caseless property, "\p{Lu}": what PCRE takes under /i and not
     * without it joins it in a class, and what it no longer takes is
     * subtracted. Since PCRE2 10.45 "\p{Lu}" under /i takes every cased
     * letter, "\P{Lu}" none. "\d", "\s", "\w" and the like never change
     * under /i and are printed as they are.
     */
    private function caselessAtom(string $printed, string $atom, NodeInterface $node): string
    {
        [$sensitive, $caseless] = $this->caselessSets($atom, $node);
        if ($caseless->key() === $sensitive->key()) {
            return $printed;
        }

        $this->noteCaselessSpelled();

        $added = $caseless->subtract($sensitive);

        return $this->subtracted($added->isEmpty() ? $printed : '['.$printed.$this->classSet($added).']', $sensitive->subtract($caseless));
    }

    /**
     * A caseless class: what PCRE takes under /i and not without it joins
     * the class, or, for a negated class, what it no longer takes joins the
     * characters it excludes. "[a-z]" under /i is "[a-zA-Z]", "[^a-z]" is
     * "[^a-zA-Z]". A class that also loses characters, as "[\P{Lu}k]" does
     * since PCRE2 10.45, has them subtracted, a v class operation.
     */
    private function caselessClass(string $body, CharClassNode $node): string
    {
        $negation = $node->isNegated ? '^' : '';
        [$sensitive, $caseless] = $this->caselessSets($this->text($node), $node);
        if ($caseless->key() === $sensitive->key()) {
            return '['.$negation.$body.']';
        }

        $this->noteCaselessSpelled();
        $added = $caseless->subtract($sensitive);
        $removed = $sensitive->subtract($caseless);
        if (!$node->isNegated) {
            return $this->subtracted('['.$body.$this->classSet($added).']', $removed);
        }

        if ($added->isEmpty()) {
            return '[^'.$body.$this->classSet($removed).']';
        }

        return $this->subtracted('[[^'.$body.']'.$this->classSet($added).']', $removed);
    }

    /**
     * A class with the characters of the set taken out, "[A--[B]]".
     */
    private function subtracted(string $class, CharSet $removed): string
    {
        return $removed->isEmpty() ? $class : '['.$class.'--['.$this->classSet($removed).']]';
    }

    /**
     * What the atom matches without /i and with it, as the running PCRE
     * says.
     *
     * @return array{CharSet, CharSet}
     */
    private function caselessSets(string $atom, NodeInterface $node): array
    {
        $sensitive = ClassSetProvider::query($atom, $this->unicode, '', '', $this->unicodeFlag);
        $caseless = ClassSetProvider::query($atom, $this->unicode, 'i', '', $this->unicodeFlag);
        if (null === $sensitive || null === $caseless) {
            throw new TranspileException('PCRE cannot tell which characters '.$atom.' matches under /i.', $node->getStartPosition(), $this->context->sourcePattern);
        }

        return [$sensitive, $caseless];
    }

    /**
     * The characters of a set, written inside a v class: ranges joined by
     * "-", every character outside printable ASCII as an escape, so a
     * Kelvin sign reads "\u212A".
     */
    private function classSet(CharSet $set): string
    {
        $written = '';
        foreach ($set->ranges as [$from, $to]) {
            $written .= $this->classCharacter($from);
            if ($to > $from) {
                $written .= ($to > $from + 1 ? '-' : '').$this->classCharacter($to);
            }
        }

        return $written;
    }

    /**
     * One character inside a v class: as written when it is printable ASCII
     * or the given text, escaped otherwise.
     */
    private function classCharacter(int $codePoint, ?string $asWritten = null): string
    {
        if ($codePoint > 0x20 && $codePoint < 0x7F) {
            $character = \chr($codePoint);

            return isset(self::CLASS_SET_META[$character]) ? '\\'.$character : $character;
        }

        return $codePoint > 0x7F && null !== $asWritten ? $asWritten : $this->formatCodePoint($codePoint);
    }

    private function noteCaselessSpelled(): void
    {
        $this->context->addNote('Spelled /i out: each letter is written with every case it matches, as [aA].');
    }

    private function text(NodeInterface $node): string
    {
        return substr($this->source, $node->getStartPosition(), $node->getEndPosition() - $node->getStartPosition());
    }

    private function noteUnicodeWordBoundary(): void
    {
        $this->context->addNote('JavaScript \\w and \\b are ASCII-based; Unicode word boundaries may differ.');
    }
}
