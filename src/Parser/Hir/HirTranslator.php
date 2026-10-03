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
use PHPRegex\Parser\Internal\StartOptions;
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
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;

/**
 * Translates the AST of a pattern into its Hir.
 *
 * Every set of characters is the one the running PCRE matches: a class, an
 * escape, a dot or a caseless letter is asked of the engine once per process
 * (see ClassSetProvider), so "\w" under /u, "\p{L}" or "k" under /iu, which
 * also matches the Kelvin sign, are exact for the PHP that runs. A class of
 * plain characters and ranges is read without asking.
 *
 * @internal
 */
final class HirTranslator
{
    private const CASELESS = 1;

    private const DOT_ALL = 2;

    private const MULTILINE = 4;

    private const EXTENDED = 8;

    private const EXTENDED_MORE = 16;

    private const UNGREEDY = 32;

    private const RESTRICT = 64;

    private const SURROGATE_FIRST = 0xD800;

    private const SURROGATE_LAST = 0xDFFF;

    /**
     * The options a pattern may open with, "(*UTF)" or "(*CR)" among them:
     * they match nothing, and change what a class, a dot or "\R" matches.
     * The limits take a value, "(*LIMIT_MATCH=10)".
     */
    private const LOOKAROUNDS = [
        GroupType::LookaheadPositive->value => LookKind::Ahead,
        GroupType::LookaheadNegative->value => LookKind::NegativeAhead,
        GroupType::LookbehindPositive->value => LookKind::Behind,
        GroupType::LookbehindNegative->value => LookKind::NegativeBehind,
    ];

    private bool $unicode = false;

    private bool $unicodeFlag = false;

    private bool $dollarEndOnly = false;

    private bool $caselessRestrict = false;

    private string $startVerbs = '';

    private string $source = '';

    /**
     * @var array<int, int> the number of each capturing group, by object id
     */
    private array $groupNumbers = [];

    public function translate(RegexNode $regex): Hir
    {
        $this->source = $regex->source ?? '';
        $this->startVerbs = StartOptions::of($this->source);
        $this->unicode = self::unicodeOf($regex);
        $this->unicodeFlag = str_contains($regex->flags, 'u');
        $this->dollarEndOnly = str_contains($regex->flags, 'D');
        $this->caselessRestrict = str_contains($regex->flags, 'r');
        $this->groupNumbers = $this->numberGroups($regex);

        $flags = 0;
        foreach (['i' => self::CASELESS, 's' => self::DOT_ALL, 'm' => self::MULTILINE, 'x' => self::EXTENDED, 'U' => self::UNGREEDY] as $letter => $bit) {
            if (str_contains($regex->flags, $letter)) {
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
        return match (true) {
            $node instanceof SequenceNode => $this->sequence($node, $flags),
            $node instanceof AlternationNode => $this->alternation($node, $flags),
            $node instanceof GroupNode => $this->group($node, $flags),
            $node instanceof QuantifierNode => $this->quantifier($node, $flags),
            $node instanceof LiteralNode => $this->literal($node, $flags),
            $node instanceof CharLiteralNode, $node instanceof ControlCharNode => $this->character($node->codePoint, $node, $flags),
            $node instanceof CharTypeNode => $this->charType($node, $flags),
            $node instanceof CharClassNode, $node instanceof DotNode, $node instanceof UnicodePropNode,
            $node instanceof PosixClassNode, $node instanceof ExtendedCharClassNode => $this->characterClass($node, $flags),
            $node instanceof AnchorNode, $node instanceof AssertionNode => $this->assertion($node, $flags),
            $node instanceof KeepNode => new AssertionHir(AssertionKind::ResetMatchStart, $node->getStartPosition(), $node->getEndPosition()),
            $node instanceof CommentNode, $node instanceof DefineNode, $node instanceof LimitMatchNode => new EmptyHir($node->getStartPosition(), $node->getEndPosition()),
            // An option the pattern opens with is applied to the sets already.
            $node instanceof PcreVerbNode => $node->getEndPosition() <= \strlen($this->startVerbs)
                ? new EmptyHir($node->getStartPosition(), $node->getEndPosition())
                : new OpaqueHir($node, Properties::zeroWidth(false, 0, str_starts_with($node->verb, 'ACCEPT')), null, $node->getStartPosition(), $node->getEndPosition()),
            $node instanceof CalloutNode, $node instanceof VersionConditionNode => $this->opaque($node, Properties::zeroWidth()),
            $node instanceof ConditionalNode => new ConditionalHir(
                $node->condition,
                $this->node($node->yes, $flags),
                $this->node($node->no, $flags),
                self::capturesIn($node->condition),
                $node->getStartPosition(),
                $node->getEndPosition(),
            ),
            $node instanceof ScriptRunNode => $this->scriptRun($node, $flags),
            // A backreference, a subroutine call, a range outside a class.
            default => $this->opaque($node, Properties::unknown(self::capturesIn($node))),
        };
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

        if (isset(self::LOOKAROUNDS[$node->type->value])) {
            // "(*napla:...)" and its kind backtrack into their body.
            return new LookHir($this->node($node->child, $flags), self::LOOKAROUNDS[$node->type->value], '*' !== $node->flags, $start, $end);
        }

        return match ($node->type) {
            GroupType::Capturing, GroupType::Named => new CaptureHir($this->node($node->child, $flags), $this->groupNumbers[spl_object_id($node)] ?? 0, $node->name, $start, $end),
            GroupType::Atomic => new AtomicHir($this->node($node->child, $flags), $start, $end),
            GroupType::InlineFlags => $this->isBareOptionSetting($node)
                ? new EmptyHir($start, $end)
                : $this->node($node->child, $this->applyOptions($flags, $node->flags ?? '')),
            GroupType::ScanSubstring => $this->opaque($node, Properties::zeroWidth(false, self::capturesIn($node->child))),
            default => $this->node($node->child, $flags),
        };
    }

    private function quantifier(QuantifierNode $node, int $flags): Hir
    {
        $bounds = QuantifierBounds::parse($node->quantifier);
        if (null === $bounds) {
            return $this->opaque($node, Properties::unknown(self::capturesIn($node)));
        }

        $body = $this->node($node->node, $flags);
        if (1 === $bounds->min && 1 === $bounds->max) {
            return $body;
        }

        $greed = match (true) {
            QuantifierType::Possessive === $node->type => Greed::Possessive,
            (QuantifierType::Lazy === $node->type) !== (0 !== ($flags & self::UNGREEDY)) => Greed::Lazy,
            default => Greed::Greedy,
        };

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
        if ($codePoint > $this->alphabetEnd()) {
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

        return match ($node->value) {
            // "\R" is "\r\n" or one vertical space, atomically: which spaces,
            // under /u or "(*BSR_ANYCRLF)", the engine says.
            'R' => new AtomicHir(new AlternationHir([new LiteralHir([0x0D, 0x0A], $start, $end), $this->classOf('\\R', $node, $flags)], $start, $end), $start, $end),
            // A grapheme cluster: any character, then any number of marks.
            'X' => $this->opaque($node, new Properties(1, null, false, CharSet::universe($this->unicode), CharSet::universe($this->unicode), null, [], [], 0, false, false)),
            // One code unit: a whole character only without /u.
            'C' => $this->unicode
                ? $this->opaque($node, new Properties(1, null, false, null, null, null, [], [], 0, false, false))
                : new ClassHir(CharSet::universe(false), $start, $end),
            default => $this->classOf('\\'.$node->value, $node, $flags),
        };
    }

    private function characterClass(NodeInterface $node, int $flags): Hir
    {
        if ($node instanceof CharClassNode) {
            if ($this->unicode && $this->namesSurrogate($node)) {
                return $this->surrogateHir($node);
            }

            if (0 === ($flags & self::CASELESS)) {
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
            .(0 !== ($flags & self::EXTENDED_MORE) ? 'xx' : (0 !== ($flags & self::EXTENDED) ? 'x' : ''));

        $set = ClassSetProvider::query(
            $atom,
            $this->unicode,
            $modifiers,
            $this->caselessRestrict || 0 !== ($flags & self::RESTRICT),
            $this->startVerbs,
            $this->unicodeFlag,
        );
        if (null === $set) {
            // PCRE refused the atom on its own: one character, of a set unknown.
            return $this->opaque($node, new Properties(1, 1, false, null, null, null, [], [], 0, false, false));
        }

        return new ClassHir($set, $node->getStartPosition(), $node->getEndPosition());
    }

    private function assertion(AnchorNode|AssertionNode $node, int $flags): Hir
    {
        $multiline = 0 !== ($flags & self::MULTILINE);
        $kind = match ($node->value) {
            '^' => $multiline ? AssertionKind::LineStart : AssertionKind::SubjectStart,
            '$' => match (true) {
                $multiline => AssertionKind::LineEnd,
                $this->dollarEndOnly => AssertionKind::SubjectEnd,
                default => AssertionKind::EndOrFinalNewline,
            },
            'A' => AssertionKind::SubjectStart,
            'z' => AssertionKind::SubjectEnd,
            'Z' => AssertionKind::EndOrFinalNewline,
            'G' => AssertionKind::MatchStart,
            'b' => AssertionKind::WordBoundary,
            'B' => AssertionKind::NotWordBoundary,
            default => null,
        };

        if (null === $kind) {
            return $this->opaque($node, Properties::zeroWidth());
        }

        return new AssertionHir($kind, $node->getStartPosition(), $node->getEndPosition());
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
        foreach ($parts as $part) {
            foreach ($part instanceof ConcatHir ? $part->parts : [$part] as $piece) {
                if ($piece instanceof EmptyHir || ($piece instanceof LiteralHir && [] === $piece->codePoints)) {
                    continue;
                }

                $previous = [] === $flat ? null : $flat[\count($flat) - 1];
                if ($piece instanceof LiteralHir && $previous instanceof LiteralHir) {
                    $flat[\count($flat) - 1] = new LiteralHir([...$previous->codePoints, ...$piece->codePoints], $previous->startPosition, $piece->endPosition);

                    continue;
                }

                $flat[] = $piece;
            }
        }

        return match (\count($flat)) {
            0 => new EmptyHir($start, $end),
            1 => $flat[0],
            default => new ConcatHir($flat, $start, $end),
        };
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
        if ($node instanceof GroupNode && (GroupType::Capturing === $node->type || GroupType::Named === $node->type)) {
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
        return substr($this->source, $node->getStartPosition(), $node->getEndPosition() - $node->getStartPosition());
    }

    private function isBareOptionSetting(GroupNode $node): bool
    {
        $text = $this->text($node);
        $length = \strlen($text);

        return $length >= 3 && str_starts_with($text, '(?') && str_ends_with($text, ')')
            && $length - 3 === strspn($text, '^-abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 2, $length - 3);
    }

    private function flagsAfter(NodeInterface $node, int $flags): int
    {
        if ($node instanceof GroupNode && GroupType::InlineFlags === $node->type && $this->isBareOptionSetting($node)) {
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
        if (str_starts_with($options, '^')) {
            // "(?^" takes every letter it may set back off, the restrict
            // with them: "(?ri)(?^i)k" folds to the Kelvin sign again.
            $flags &= ~(self::CASELESS | self::DOT_ALL | self::MULTILINE | self::EXTENDED | self::EXTENDED_MORE | self::RESTRICT);
            $options = substr($options, 1);
        }

        $on = true;
        $previous = '';
        foreach (str_split($options) as $letter) {
            if ('-' === $letter) {
                $on = false;
            } elseif ('x' === $letter) {
                // "x" sets /x, "xx" also its form for classes; "-x" unsets both.
                $flags = match (true) {
                    !$on => $flags & ~(self::EXTENDED | self::EXTENDED_MORE),
                    'x' === $previous => $flags | self::EXTENDED_MORE,
                    default => $flags | self::EXTENDED,
                };
            } elseif (isset($bits[$letter])) {
                $flags = $on ? $flags | $bits[$letter] : $flags & ~$bits[$letter];
            }

            $previous = $letter;
        }

        return $flags;
    }
}
