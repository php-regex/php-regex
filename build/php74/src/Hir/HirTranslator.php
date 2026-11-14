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

namespace PHPRegex\Parser\Hir;

use PHPRegex\Parser\Analysis\GroupNumberingCollector;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
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
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\StartOptions;

/**
 * Translates the AST of a pattern into its Hir.
 *
 * Every set of characters is the one the running PCRE matches: a class, an
 * escape, a dot or a caseless letter is asked of the engine once per process
 * (see ClassSetProvider), so "\w" under /u, "\p{L}" or "k" under /iu, which
 * also matches the Kelvin sign, are exact for the PHP that runs. A class of
 * plain characters and ranges is read without asking. Given a target older
 * than PCRE2 10.40, a script name means the script, as it did there.
 *
 * @internal
 */
final class HirTranslator
{
    private bool $unicode = false;
    private const CASELESS = 1;

    private const DOT_ALL = 2;

    private const MULTILINE = 4;

    private const EXTENDED = 8;

    private const EXTENDED_MORE = 16;

    private const UNGREEDY = 32;

    private const RESTRICT = 64;

    private const ASCII_DIGIT = 128;

    private const ASCII_SPACE = 256;

    private const ASCII_WORD = 512;

    private const ASCII_POSIX = 1024;

    private const ASCII_POSIX_DIGIT = 2048;

    /**
     * The ASCII options by the letter after "a": "(?aD)" for "\d", "(?aS)"
     * for "\s", "(?aW)" for "\w", "(?aP)" for the POSIX classes, the digit
     * ones with them, "(?aT)" for the POSIX digit classes only. "-aP" also
     * takes the digit ones off: "(?aT)(?-aP)[[:digit:]]" matches U+0663.
     */
    private const ASCII_OPTIONS = [
        'D' => self::ASCII_DIGIT,
        'S' => self::ASCII_SPACE,
        'W' => self::ASCII_WORD,
        'P' => self::ASCII_POSIX | self::ASCII_POSIX_DIGIT,
        'T' => self::ASCII_POSIX_DIGIT,
    ];

    /**
     * What a lone "a" sets and "-a" takes off: every ASCII option.
     */
    private const ASCII_ALL = self::ASCII_DIGIT | self::ASCII_SPACE | self::ASCII_WORD | self::ASCII_POSIX | self::ASCII_POSIX_DIGIT;

    private const SURROGATE_FIRST = 0xD800;

    private const SURROGATE_LAST = 0xDFFF;

    private bool $unicodeFlag = false;

    private bool $dollarEndOnly = false;

    private string $startVerbs = '';

    private string $source = '';

    /**
     * @var array<int, int> the number of each capturing group, by object id
     */
    private array $groupNumbers = [];

    /**
     * The locale whose character tables the sets without /u are read
     * under, the target's; the one PHP compiles under when null.
     *
     */
    private ?string $locale;
    /**
     */
    private ?PcreTarget $target = null;

    /**
     * @param PcreTarget|null $target the PHP, PCRE2 and locale the pattern is
     *                                read for, its properties spelled as the
     *                                running engine reads them; the running
     *                                engine's reading when null
     */
    public function __construct(?PcreTarget $target = null)
    {
        $this->target = $target;
        $this->locale = ($nullsafeVariable1 = $target) ? $nullsafeVariable1->locale : null;
    }

    public function translate(RegexNode $regex): Hir
    {
        $this->source = $regex->source ?? '';
        $this->startVerbs = StartOptions::of($this->source);
        $this->unicode = self::unicodeOf($regex);
        $this->unicodeFlag = false !== strpos($regex->flags, 'u');
        $this->dollarEndOnly = false !== strpos($regex->flags, 'D');
        $this->groupNumbers = $this->numberGroups($regex);

        $flags = 0;
        foreach (['i' => self::CASELESS, 's' => self::DOT_ALL, 'm' => self::MULTILINE, 'x' => self::EXTENDED, 'U' => self::UNGREEDY, 'r' => self::RESTRICT] as $letter => $bit) {
            if (false !== strpos($regex->flags, $letter)) {
                $flags |= $bit;
            }
        }

        return $this->node($regex->pattern, $flags);
    }

    /**
     * Whether the pattern reads its subject as code points: the /u flag,
     * or a "(*UTF)" option the source opens with.
     */
    public static function unicodeOf(RegexNode $regex): bool
    {
        return $regex->isUnicode();
    }

    private function node(NodeInterface $node, int $flags): Hir
    {
        switch (true) {
            case true === $node instanceof SequenceNode:
                return $this->sequence($node, $flags);
            case true === $node instanceof AlternationNode:
                return $this->alternation($node, $flags);
            case true === $node instanceof GroupNode:
                return $this->group($node, $flags);
            case true === $node instanceof QuantifierNode:
                return $this->quantifier($node, $flags);
            case true === $node instanceof LiteralNode:
                return $this->literal($node, $flags);
            case true === $node instanceof CharLiteralNode:
            case true === $node instanceof ControlCharNode:
                return $this->character($node->codePoint, $node, $flags);
            case true === $node instanceof CharTypeNode:
                return $this->charType($node, $flags);
            case true === $node instanceof CharClassNode:
            case true === $node instanceof DotNode:
            case true === $node instanceof UnicodePropNode:
            case true === $node instanceof PosixClassNode:
            case true === $node instanceof ExtendedCharClassNode:
                return $this->characterClass($node, $flags);
            case true === $node instanceof AnchorNode:
            case true === $node instanceof AssertionNode:
                return $this->assertion($node, $flags);
            case true === $node instanceof KeepNode:
                return new AssertionHir(AssertionKind::ResetMatchStart(), $node->getStartPosition(), $node->getEndPosition());
            case true === $node instanceof CommentNode:
            case true === $node instanceof DefineNode:
            case true === $node instanceof LimitMatchNode:
                return new EmptyHir($node->getStartPosition(), $node->getEndPosition());
            case true === $node instanceof PcreVerbNode:
                return $node->getEndPosition() <= \strlen($this->startVerbs)
                    ? new EmptyHir($node->getStartPosition(), $node->getEndPosition())
                    : new OpaqueHir($node, Properties::zeroWidth(false, 0, 'ACCEPT' === $node->name), null, $node->getStartPosition(), $node->getEndPosition());
            case true === $node instanceof CalloutNode:
            case true === $node instanceof VersionConditionNode:
                return $this->opaque($node, Properties::zeroWidth());
            case true === $node instanceof ConditionalNode:
                return new ConditionalHir(
                    $node->condition,
                    $this->node($node->yes, $flags),
                    $this->node($node->no, $this->flagsAfter($node->yes, $flags)),
                    self::capturesIn($node->condition),
                    $node->getStartPosition(),
                    $node->getEndPosition(),
                );
            case true === $node instanceof ScriptRunNode:
                return $this->scriptRun($node, $flags);
            default:
                return $this->opaque($node, Properties::unknown(self::capturesIn($node)));
        }
    }

    private function sequence(SequenceNode $node, int $flags): Hir
    {
        $parts = [];
        foreach ($node->children as $child) {
            $parts[] = $this->node($child, $flags);
            $flags = $this->flagsAfter($child, $flags);
        }

        return self::concat($parts, $node->getStartPosition(), $node->getEndPosition());
    }

    private function alternation(AlternationNode $node, int $flags): Hir
    {
        // An option set in one alternative holds in the ones after it.
        $branches = [];
        foreach ($node->alternatives as $alternative) {
            $branches[] = $this->node($alternative, $flags);
            $flags = $this->flagsAfter($alternative, $flags);
        }

        if ([] === $branches) {
            return new EmptyHir($node->getStartPosition(), $node->getEndPosition());
        }

        return 1 === \count($branches) ? $branches[0] : new AlternationHir($branches, $node->getStartPosition(), $node->getEndPosition());
    }

    private function group(GroupNode $node, int $flags): Hir
    {
        $start = $node->getStartPosition();
        $end = $node->getEndPosition();

        switch (true) {
            case $node->type === GroupType::LookaheadPositive():
                $look = LookKind::Ahead();
                break;
            case $node->type === GroupType::LookaheadNegative():
                $look = LookKind::NegativeAhead();
                break;
            case $node->type === GroupType::LookbehindPositive():
                $look = LookKind::Behind();
                break;
            case $node->type === GroupType::LookbehindNegative():
                $look = LookKind::NegativeBehind();
                break;
            default:
                $look = null;
                break;
        }
        if (null !== $look) {
            // "(*napla:...)" and its kind backtrack into their body.
            return new LookHir($this->node($node->child, $flags), $look, '*' !== $node->flags, $start, $end);
        }

        switch (true) {
            case $node->type === GroupType::Capturing():
            case $node->type === GroupType::Named():
                return new CaptureHir($this->node($node->child, $flags), $this->groupNumbers[spl_object_id($node)] ?? 0, $node->name, $start, $end);
            case $node->type === GroupType::Atomic():
                return new AtomicHir($this->node($node->child, $flags), $start, $end);
            case $node->type === GroupType::InlineFlags():
                return $this->isBareOptionSetting($node)
                    ? new EmptyHir($start, $end)
                    : $this->node($node->child, $this->applyOptions($flags, $node->flags ?? ''));
            case $node->type === GroupType::ScanSubstring():
                return $this->opaque($node, Properties::zeroWidth(false, self::capturesIn($node->child)));
            default:
                return $this->node($node->child, $flags);
        }
    }

    private function quantifier(QuantifierNode $node, int $flags): Hir
    {
        $bounds = $node->bounds;
        if (null === $bounds) {
            return $this->opaque($node, Properties::unknown(self::capturesIn($node)));
        }

        $body = $this->node($node->node, $flags);
        if (1 === $bounds->min && 1 === $bounds->max) {
            return $body;
        }

        switch (true) {
            case true === (QuantifierType::Possessive() === $node->type):
                $greed = Greed::Possessive();
                break;
            case true === ((QuantifierType::Lazy() === $node->type) !== (0 !== ($flags & self::UNGREEDY))):
                $greed = Greed::Lazy();
                break;
            default:
                $greed = Greed::Greedy();
                break;
        }

        return new RepetitionHir($body, $bounds->min, $bounds->max, $greed, $node->getStartPosition(), $node->getEndPosition());
    }

    private function literal(LiteralNode $node, int $flags): Hir
    {
        $codePoints = Utf8::decode($node->value, $this->unicode);
        if (null === $codePoints) {
            return $this->opaque($node, Properties::unknown());
        }

        if (0 === ($flags & self::CASELESS)) {
            return self::concat([new LiteralHir($codePoints, $node->getStartPosition(), $node->getEndPosition())], $node->getStartPosition(), $node->getEndPosition());
        }

        $parts = [];
        foreach ($codePoints as $codePoint) {
            $parts[] = $this->character($codePoint, $node, $flags);
        }

        return self::concat($parts, $node->getStartPosition(), $node->getEndPosition());
    }

    private function character(int $codePoint, NodeInterface $node, int $flags): Hir
    {
        // No code point (-1): a "\N{name}" whose name reads as no character.
        if ($codePoint < 0 || $codePoint > $this->alphabetEnd()) {
            return $this->opaque($node, Properties::unknown());
        }

        if ($this->unicode && self::isSurrogate($codePoint)) {
            return $this->surrogateHir($node);
        }

        if (0 === ($flags & self::CASELESS)) {
            return new LiteralHir([$codePoint], $node->getStartPosition(), $node->getEndPosition());
        }

        return $this->classOf(\sprintf('\\x{%X}', $codePoint), $node, $flags);
    }

    private function charType(CharTypeNode $node, int $flags): Hir
    {
        $start = $node->getStartPosition();
        $end = $node->getEndPosition();

        switch (true) {
            case 'R' === $node->value:
                return new AtomicHir(new AlternationHir([new LiteralHir([0x0D, 0x0A], $start, $end), $this->classOf('\\R', $node, $flags)], $start, $end), $start, $end);
            case 'X' === $node->value:
                return $this->opaque($node, new Properties(1, null, false, CharSet::universe($this->unicode), CharSet::universe($this->unicode), null, [], [], 0, false, false));
            case 'C' === $node->value:
                return $this->unicode
                    ? $this->opaque($node, new Properties(1, null, false, null, null, null, [], [], 0, false, false))
                    : new ClassHir(CharSet::universe(false), $start, $end);
            default:
                return $this->classOf('\\'.$node->value, $node, $flags);
        }
    }

    private function characterClass(NodeInterface $node, int $flags): Hir
    {
        if ($node instanceof CharClassNode) {
            if ($this->unicode && $this->namesSurrogate($node)) {
                return $this->surrogateHir($node);
            }

            // Under xx a class skips its unescaped spaces and tabs, "[a b]"
            // is "[ab]": the engine reads such a class.
            if (0 === ($flags & self::CASELESS)
                && (0 === ($flags & self::EXTENDED_MORE) || false === strpbrk($this->text($node), " \t"))) {
                $set = $this->directClass($node);
                if (null !== $set) {
                    return new ClassHir($set, $node->getStartPosition(), $node->getEndPosition());
                }
            }
        }

        return $this->classOf($this->text($node), $node, $flags);
    }

    /**
     * The set PCRE matches for one character's worth of pattern.
     */
    private function classOf(string $atom, NodeInterface $node, int $flags): Hir
    {
        $modifiers = (0 !== ($flags & self::CASELESS) ? 'i' : '')
            .(0 !== ($flags & self::DOT_ALL) ? 's' : '')
            .(0 !== ($flags & self::EXTENDED_MORE) ? 'xx' : (0 !== ($flags & self::EXTENDED) ? 'x' : ''))
            .(0 !== ($flags & self::RESTRICT) ? 'r' : '');
        foreach (['D' => self::ASCII_DIGIT, 'S' => self::ASCII_SPACE, 'W' => self::ASCII_WORD, 'P' => self::ASCII_POSIX] as $letter => $bit) {
            if (0 !== ($flags & $bit)) {
                $modifiers .= 'a'.$letter;
            }
        }
        // "aP" holds the digit classes already.
        if (self::ASCII_POSIX_DIGIT === ($flags & (self::ASCII_POSIX | self::ASCII_POSIX_DIGIT))) {
            $modifiers .= 'aT';
        }

        $atom = ClassSetProvider::spelledFor($atom, $this->target);
        $set = null === $atom ? null : ClassSetProvider::query($atom, $this->unicode, $modifiers, $this->startVerbs, $this->unicodeFlag, $this->locale);
        if (null === $set) {
            // PCRE refused the atom on its own, or gave up scanning it under
            // its limits, or the running engine has no name for a property
            // of it as the target reads it: one character, of a set unknown.
            return $this->opaque($node, new Properties(1, 1, false, null, null, null, [], [], 0, false, false));
        }

        return new ClassHir($set, $node->getStartPosition(), $node->getEndPosition());
    }

    /**
     * @param AnchorNode|AssertionNode $node
     */
    private function assertion($node, int $flags): Hir
    {
        $multiline = 0 !== ($flags & self::MULTILINE);
        switch (true) {
            case '^' === $node->value:
                $kind = $multiline ? AssertionKind::LineStart() : AssertionKind::SubjectStart();
                break;
            case '$' === $node->value:
                switch (true) {
                    case true === $multiline:
                        $kind = AssertionKind::LineEnd();
                        break;
                    case true === $this->dollarEndOnly:
                        $kind = AssertionKind::SubjectEnd();
                        break;
                    default:
                        $kind = AssertionKind::EndOrFinalNewline();
                        break;
                }
                break;
            case 'A' === $node->value:
                $kind = AssertionKind::SubjectStart();
                break;
            case 'z' === $node->value:
                $kind = AssertionKind::SubjectEnd();
                break;
            case 'Z' === $node->value:
                $kind = AssertionKind::EndOrFinalNewline();
                break;
            case 'G' === $node->value:
                $kind = AssertionKind::MatchStart();
                break;
            case 'b' === $node->value:
                $kind = AssertionKind::WordBoundary();
                break;
            case 'B' === $node->value:
                $kind = AssertionKind::NotWordBoundary();
                break;
            default:
                $kind = null;
                break;
        }

        if (null === $kind) {
            return $this->opaque($node, Properties::zeroWidth());
        }

        // A word boundary tells word characters from the rest: which they
        // are, under /u or "(*UCP)", the engine says.
        $word = AssertionKind::WordBoundary() === $kind || AssertionKind::NotWordBoundary() === $kind ? $this->classOf('\\w', $node, $flags) : null;

        return new AssertionHir($kind, $node->getStartPosition(), $node->getEndPosition(), $word instanceof ClassHir ? $word->set : null);
    }

    private function scriptRun(ScriptRunNode $node, int $flags): Hir
    {
        if (null === $node->content) {
            return $this->opaque($node, Properties::unknown());
        }

        $body = $this->node($node->content, $flags);

        return new OpaqueHir($node, $body->properties->irregular(), $body, $node->getStartPosition(), $node->getEndPosition());
    }

    private function opaque(NodeInterface $node, Properties $properties): OpaqueHir
    {
        return new OpaqueHir($node, $properties, null, $node->getStartPosition(), $node->getEndPosition());
    }

    /**
     * The parts, nested sequences flattened, empty ones left out and adjacent
     * runs of characters joined.
     *
     * @param list<Hir> $parts
     */
    private static function concat(array $parts, int $start, int $end): Hir
    {
        $flat = [];
        $codePoints = [];
        $literalStart = 0;
        $literalEnd = 0;
        foreach ($parts as $part) {
            foreach ($part instanceof ConcatHir ? $part->parts : [$part] as $piece) {
                if ($piece instanceof EmptyHir || ($piece instanceof LiteralHir && [] === $piece->codePoints)) {
                    continue;
                }

                if ($piece instanceof LiteralHir) {
                    if ([] === $codePoints) {
                        $literalStart = $piece->startPosition;
                    }
                    // Append each character once instead of copying the growing run.
                    foreach ($piece->codePoints as $codePoint) {
                        $codePoints[] = $codePoint;
                    }
                    $literalEnd = $piece->endPosition;

                    continue;
                }

                if ([] !== $codePoints) {
                    $flat[] = new LiteralHir($codePoints, $literalStart, $literalEnd);
                    $codePoints = [];
                }
                $flat[] = $piece;
            }
        }

        if ([] !== $codePoints) {
            $flat[] = new LiteralHir($codePoints, $literalStart, $literalEnd);
        }
        $matchSubject555 = \count($flat);

        switch (true) {
            case 0 === $matchSubject555:
                return new EmptyHir($start, $end);
            case 1 === $matchSubject555:
                return $flat[0];
            default:
                return new ConcatHir($flat, $start, $end);
        }
    }

    /**
     * The set of a class made of characters and ranges only, without asking
     * the engine; null when it holds anything else.
     */
    private function directClass(CharClassNode $node): ?CharSet
    {
        $members = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        $set = CharSet::empty();
        foreach ($members as $member) {
            if ($member instanceof RangeNode) {
                $from = $this->singleCodePoint($member->start);
                $to = $this->singleCodePoint($member->end);
                if (null === $from || null === $to) {
                    return null;
                }

                $set = $set->union(CharSet::range($from, $to));

                continue;
            }

            $codePoint = $this->singleCodePoint($member);
            if (null === $codePoint) {
                return null;
            }

            $set = $set->union(CharSet::single($codePoint));
        }

        // A range may straddle the surrogate block, which no subject holds:
        // the set keeps the real characters and leaves the hole out.
        return $node->isNegated ? CharSet::universe($this->unicode)->subtract($set) : $set->intersect(CharSet::universe($this->unicode));
    }

    /**
     * Whether a member of the class names a surrogate code point, as a
     * single character or as the endpoint of a range: the engine refuses to
     * compile the pattern at all then. A range that straddles the block
     * with both endpoints outside it is legal.
     */
    private function namesSurrogate(CharClassNode $node): bool
    {
        $members = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        foreach ($members as $member) {
            if ($member instanceof RangeNode) {
                if (self::isSurrogate($this->singleCodePoint($member->start)) || self::isSurrogate($this->singleCodePoint($member->end))) {
                    return true;
                }

                continue;
            }

            if (self::isSurrogate($this->singleCodePoint($member))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The stand-in for an atom that names a surrogate: its set holds the
     * forbidden block, which the automata ladder refuses before anything is
     * built, so no wrong language is ever read from it.
     */
    private function surrogateHir(NodeInterface $node): Hir
    {
        return new ClassHir(CharSet::range(self::SURROGATE_FIRST, self::SURROGATE_LAST), $node->getStartPosition(), $node->getEndPosition());
    }

    private static function isSurrogate(?int $codePoint): bool
    {
        return null !== $codePoint && $codePoint >= self::SURROGATE_FIRST && $codePoint <= self::SURROGATE_LAST;
    }

    private function singleCodePoint(NodeInterface $node): ?int
    {
        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return $node->codePoint <= $this->alphabetEnd() ? $node->codePoint : null;
        }

        if ($node instanceof LiteralNode) {
            $characters = Utf8::decode($node->value, $this->unicode);

            return null !== $characters && 1 === \count($characters) ? $characters[0] : null;
        }

        return null;
    }

    private function alphabetEnd(): int
    {
        return $this->unicode ? 0x10FFFF : 0xFF;
    }

    /**
     * The number of each capturing group, as PCRE gives them: groups counted
     * in the order written, each branch of a "(?|...)" starting again.
     *
     * @return array<int, int>
     */
    private function numberGroups(RegexNode $regex): array
    {
        $groups = [];
        self::collectGroups($regex->pattern, $groups);

        $numbers = (new GroupNumberingCollector())->collect($regex)->captureSequence;
        $byGroup = [];
        foreach ($groups as $index => $id) {
            if (isset($numbers[$index])) {
                $byGroup[$id] = $numbers[$index];
            }
        }

        return $byGroup;
    }

    /**
     * The capturing groups in the order the numbering meets them.
     *
     * @param list<int> $groups object ids
     */
    private static function collectGroups(NodeInterface $node, array &$groups): void
    {
        if ($node instanceof GroupNode && (GroupType::Capturing() === $node->type || GroupType::Named() === $node->type)) {
            $groups[] = spl_object_id($node);
        }

        foreach ($node->getChildren() as $child) {
            self::collectGroups($child, $groups);
        }
    }

    private static function capturesIn(NodeInterface $node): int
    {
        $groups = [];
        self::collectGroups($node, $groups);

        return \count($groups);
    }

    private function text(NodeInterface $node): string
    {
        return (string) substr($this->source, $node->getStartPosition(), $node->getEndPosition() - $node->getStartPosition());
    }

    private function isBareOptionSetting(GroupNode $node): bool
    {
        $text = $this->text($node);
        $length = \strlen($text);

        return $length >= 3 && 0 === strncmp($text, '(?', strlen('(?')) && 0 === substr_compare($text, ')', -strlen(')'))
            && $length - 3 === strspn($text, '^-abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 2, $length - 3);
    }

    private function flagsAfter(NodeInterface $node, int $flags): int
    {
        if ($node instanceof GroupNode && GroupType::InlineFlags() === $node->type && $this->isBareOptionSetting($node)) {
            return $this->applyOptions($flags, $node->flags ?? '');
        }

        // "(?i)" inside a branch also holds in the branches after it.
        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $flags = $this->flagsAfter($child, $flags);
            }
        }

        return $flags;
    }

    private function applyOptions(int $flags, string $options): int
    {
        $bits = ['i' => self::CASELESS, 's' => self::DOT_ALL, 'm' => self::MULTILINE, 'U' => self::UNGREEDY, 'r' => self::RESTRICT];
        if (0 === strncmp($options, '^', strlen('^'))) {
            // "(?^" takes i, m, n, s, x, xx and r back off: "(?ri)(?^i)k"
            // folds to the Kelvin sign again. U and the ASCII options stay.
            $flags &= ~(self::CASELESS | self::DOT_ALL | self::MULTILINE | self::EXTENDED | self::EXTENDED_MORE | self::RESTRICT);
            $options = (string) substr($options, 1);
        }

        // The group's letters are gathered first and applied together, as
        // PCRE does: "x" alone in a group takes xx off, so "(?xx)(?x)" and
        // "(?xx)(?ix)" leave a class's spaces in, while "(?xxix)" keeps xx.
        // "-x" takes both off.
        $set = 0;
        $unset = 0;
        $on = true;
        $previous = '';
        $length = \strlen($options);
        for ($index = 0; $index < $length; ++$index) {
            $letter = $options[$index];
            $bit = 0;
            if ('-' === $letter) {
                $on = false;
            } elseif ('x' === $letter) {
                $bit = $on && 'x' !== $previous ? self::EXTENDED : self::EXTENDED | self::EXTENDED_MORE;
            } elseif ('a' === $letter) {
                // "a" alone, or "a" and the one letter naming its option.
                $next = $options[$index + 1] ?? '';
                $ascii = self::ASCII_OPTIONS[$next] ?? null;
                if (null !== $ascii) {
                    ++$index;
                }

                $bit = $ascii ?? self::ASCII_ALL;
            } elseif (isset($bits[$letter])) {
                $bit = $bits[$letter];
            }

            if ($on) {
                $set |= $bit;
            } else {
                $unset |= $bit;
            }

            $previous = $letter;
        }

        if (self::EXTENDED === ($set & (self::EXTENDED | self::EXTENDED_MORE))) {
            $unset |= self::EXTENDED_MORE;
        }

        return ($flags | $set) & ~$unset;
    }
}
