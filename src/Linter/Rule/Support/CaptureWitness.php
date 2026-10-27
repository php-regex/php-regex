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

namespace PHPRegex\Linter\Rule\Support;

use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Parser\Analysis\GroupIndex;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
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
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Syntax\Delimiters;
use PHPRegex\Parser\Text\Ascii;

/**
 * A subject on which the engine fills a capturing group with text, built
 * from the pattern: each item read as one string it matches (a literal as
 * written, a class as one of its members, a type as an ASCII character it
 * holds), the alternative and the repetition that reach the group taken,
 * the group's body made to read something. The pattern is then run on it
 * with the running engine, under the caller's limits.
 *
 * Where the engine fills the group, the pattern with the group's body
 * removed, which leaves the group empty, writes other $matches on that
 * subject: the group does not always stay empty. Where it does not, or
 * where an item has no string to stand for it (a reference, a call, a
 * condition, a lookaround around the group, a property), nothing is shown,
 * and nothing follows from it.
 *
 * @internal
 */
final class CaptureWitness
{
    /**
     * The longest subject built: a count past this makes no subject.
     */
    private const MAX_SUBJECT_LENGTH = 4096;

    /**
     * ASCII characters a negated class is read as, the first it holds.
     */
    private const OUTSIDE_CANDIDATES = ['a', '0', ' ', 'Z', '_', '-', '~', '!', '#', '%', '9', 'q'];

    private static ?PcreEngine $engine = null;

    /**
     * Whether the subject is being built inside the group, whose body must
     * read something.
     */
    private bool $filling = false;

    /**
     * @param array<int, true> $path         the group and the nodes holding it, by object id
     * @param bool             $repeatAround whether the repeats around the group read once at least, as a
     *                                       separator between two runs does
     * @param bool             $otherChars   whether the group's body reads other characters than the items
     *                                       around it most likely do
     */
    private function __construct(
        private readonly GroupNode $group,
        private readonly array $path,
        private readonly bool $unicode,
        private readonly bool $repeatAround = false,
        private readonly bool $otherChars = false,
    ) {}

    /**
     * Whether the running engine fills the group with text on a subject
     * built from the pattern the context lints.
     */
    public static function fills(LintContext $context, GroupNode $group): bool
    {
        $parents = $context->parents();
        $number = GroupIndex::of($parents[0] ?? $group)->numberOf($group);
        if (null === $number) {
            return false;
        }

        $path = [spl_object_id($group) => true];
        foreach ($parents as $parent) {
            $path[spl_object_id($parent)] = true;
        }

        $pattern = $context->pattern;
        $regex = $pattern->delimiter.$pattern->source.Delimiters::closing($pattern->delimiter).$pattern->flags;
        $tried = [];
        // The fewest characters first, then a separator read between two
        // runs, then a body read with other characters than its neighbours.
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$repeatAround, $otherChars]) {
            $subject = (new self($group, $path, $pattern->unicodeMode, $repeatAround, $otherChars))->sample($parents[0] ?? $group);
            if (null === $subject || '' === $subject || \strlen($subject) > self::MAX_SUBJECT_LENGTH || isset($tried[$subject])) {
                continue;
            }

            $tried[$subject] = true;
            $match = (self::$engine ??= new PcreEngine())->match($regex, $subject);
            $captured = $match->groups[$number] ?? null;
            if (true === $match->matched && \is_string($captured) && '' !== $captured) {
                return true;
            }
        }

        return false;
    }

    /**
     * One string the node matches, on the way to the group; null when the
     * node has none to give.
     */
    private function sample(NodeInterface $node): ?string
    {
        if ($node === $this->group) {
            $this->filling = true;

            try {
                $body = $this->sample($node->child);
            } finally {
                $this->filling = false;
            }

            return '' === $body ? null : $body;
        }

        return match (true) {
            $node instanceof SequenceNode => $this->sequence($node),
            $node instanceof AlternationNode => $this->alternative($node),
            $node instanceof QuantifierNode => $this->repetition($node),
            $node instanceof GroupNode => $this->groupBody($node),
            $node instanceof LiteralNode => $node->value,
            $node instanceof CharLiteralNode => $this->character($node->codePoint),
            $node instanceof CharTypeNode => self::typeCharacter($node->value, $this->otherCharacters()),
            $node instanceof DotNode => $this->otherCharacters() ? '~' : 'a',
            $node instanceof CharClassNode => $this->classCharacter($node),
            $node instanceof UnicodePropNode => self::propertyCharacter($node->prop),
            $node instanceof AnchorNode, $node instanceof AssertionNode, $node instanceof KeepNode,
            $node instanceof CommentNode, $node instanceof PcreVerbNode, $node instanceof CalloutNode,
            $node instanceof LimitMatchNode => '',
            default => null,
        };
    }

    private function sequence(SequenceNode $node): ?string
    {
        $text = '';
        foreach ($node->children as $child) {
            $part = $this->sample($child);
            if (null === $part) {
                return null;
            }

            $text .= $part;
        }

        return $text;
    }

    /**
     * The alternative that holds the group; otherwise the first one with a
     * string, inside the group the first one that reads something.
     */
    private function alternative(AlternationNode $node): ?string
    {
        foreach ($node->alternatives as $alternative) {
            if (isset($this->path[spl_object_id($alternative)])) {
                return $this->sample($alternative);
            }
        }

        $fallback = null;
        foreach ($node->alternatives as $alternative) {
            $text = $this->sample($alternative);
            if (null === $text) {
                continue;
            }

            if (!$this->filling || '' !== $text) {
                return $text;
            }

            $fallback ??= $text;
        }

        return $fallback;
    }

    /**
     * The fewest repetitions, one at least on the way to the group and
     * inside it.
     */
    private function repetition(QuantifierNode $node): ?string
    {
        // The parser gives only quantifiers that read: one that does not
        // is read as repeating nothing.
        $bounds = $node->bounds;
        [$min, $max] = null === $bounds ? [0, 0] : [$bounds->min, $bounds->max];

        $count = isset($this->path[spl_object_id($node)]) || $this->filling || $this->repeatAround ? max($min, 1) : $min;
        if (null !== $max && $count > $max) {
            $count = $max;
        }

        if (0 === $count) {
            return isset($this->path[spl_object_id($node)]) ? null : '';
        }

        $once = $this->sample($node->node);
        if (null === $once || \strlen($once) * $count > self::MAX_SUBJECT_LENGTH) {
            return null;
        }

        return str_repeat($once, $count);
    }

    private function groupBody(GroupNode $node): ?string
    {
        return match ($node->type) {
            GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::Atomic, GroupType::InlineFlags => $this->sample($node->child),
            // A lookaround reads nothing; around the group, what it reads
            // would have to be placed where the group is not.
            GroupType::LookaheadPositive, GroupType::LookaheadNegative,
            GroupType::LookbehindPositive, GroupType::LookbehindNegative => isset($this->path[spl_object_id($node)]) ? null : '',
            default => null,
        };
    }

    private function character(int $codePoint): ?string
    {
        if ($this->unicode) {
            $character = mb_chr($codePoint, 'UTF-8');

            return false === $character ? null : $character;
        }

        return $codePoint <= 0xFF ? \chr($codePoint) : null;
    }

    /**
     * Whether the item being read is in the group's body, to be read with
     * other characters.
     */
    private function otherCharacters(): bool
    {
        return $this->filling && $this->otherChars;
    }

    /**
     * An ASCII character every PCRE2 release puts in the type; another one
     * when $other.
     */
    private static function typeCharacter(string $type, bool $other = false): ?string
    {
        return match ($type) {
            'd' => $other ? '9' : '0',
            'w' => $other ? 'Z' : 'a',
            'D', 'S', 'H', 'V', 'N', 'X', 'C' => $other ? '~' : 'a',
            's', 'h' => $other ? "\t" : ' ',
            'W' => $other ? '~' : ' ',
            'v', 'R' => "\n",
            default => null,
        };
    }

    /**
     * A member of a class; for a negated class, a candidate no member may
     * hold, as far as the members say: the engine has the last word.
     */
    private function classCharacter(CharClassNode $node): ?string
    {
        $members = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];

        if ($this->otherCharacters()) {
            $members = array_reverse($members);
        }

        if (!$node->isNegated) {
            foreach ($members as $member) {
                $character = $this->memberCharacter($member);
                if (null !== $character) {
                    return $character;
                }
            }

            return null;
        }

        foreach ($this->otherCharacters() ? array_reverse(self::OUTSIDE_CANDIDATES) : self::OUTSIDE_CANDIDATES as $candidate) {
            $outside = true;
            foreach ($members as $member) {
                $holds = $this->mayHold($member, $candidate);
                if (null === $holds) {
                    return null;
                }

                if ($holds) {
                    $outside = false;

                    break;
                }
            }

            if ($outside) {
                return $candidate;
            }
        }

        return null;
    }

    private function memberCharacter(NodeInterface $member): ?string
    {
        return match (true) {
            $member instanceof LiteralNode && '' !== $member->value => $member->value,
            $member instanceof CharLiteralNode => $this->character($member->codePoint),
            $member instanceof RangeNode => $this->memberCharacter($this->otherCharacters() ? $member->end : $member->start),
            $member instanceof CharTypeNode => self::typeCharacter($member->value, $this->otherCharacters()),
            $member instanceof PosixClassNode => self::posixCharacter($member->class),
            $member instanceof UnicodePropNode => self::propertyCharacter($member->prop),
            default => null,
        };
    }

    /**
     * An ASCII character the property holds in every Unicode release.
     */
    private static function propertyCharacter(string $property): ?string
    {
        foreach (['a', 'A', '0', ' ', '!'] as $candidate) {
            if (true === self::propertyHolds($property, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether the property, as the parser keeps it ("L", "{^Lu}"), holds
     * the ASCII character; null for a property the answer is not read for.
     * The general categories of ASCII characters and their script have not
     * moved across Unicode releases.
     */
    private static function propertyHolds(string $property, string $candidate): ?bool
    {
        $name = Ascii::lower(str_replace([' ', '_', '-'], '', trim($property, '{}')));
        $negated = str_starts_with($name, '^');
        $name = ltrim($name, '^');

        $holds = match ($name) {
            'l', 'l&', 'lc', 'letter', 'latin', 'latn' => Ascii::isAlpha($candidate),
            'll', 'lowercaseletter' => Ascii::isAlpha($candidate) && Ascii::lower($candidate) === $candidate,
            'lu', 'uppercaseletter' => Ascii::isAlpha($candidate) && Ascii::upper($candidate) === $candidate,
            'n', 'nd', 'number', 'decimalnumber' => Ascii::isDigit($candidate),
            'z', 'zs', 'separator', 'spaceseparator' => ' ' === $candidate,
            'any' => true,
            default => null,
        };

        return null === $holds ? null : $holds !== $negated;
    }

    /**
     * Whether a member may hold the ASCII candidate, in either case; null
     * when the member does not say.
     */
    private function mayHold(NodeInterface $member, string $candidate): ?bool
    {
        $variants = array_unique([$candidate, Ascii::lower($candidate), Ascii::upper($candidate)]);
        $codePoint = static fn (NodeInterface $node): ?int => match (true) {
            // A byte that is no UTF-8 character has no code point.
            $node instanceof LiteralNode && 1 === mb_strlen($node->value, 'UTF-8') => false === mb_ord($node->value, 'UTF-8') ? null : mb_ord($node->value, 'UTF-8'),
            $node instanceof CharLiteralNode => $node->codePoint,
            default => null,
        };

        if ($member instanceof RangeNode) {
            [$low, $high] = [$codePoint($member->start), $codePoint($member->end)];
            if (null === $low || null === $high) {
                return null;
            }

            foreach ($variants as $variant) {
                if (\ord($variant) >= $low && \ord($variant) <= $high) {
                    return true;
                }
            }

            return false;
        }

        if ($member instanceof LiteralNode || $member instanceof CharLiteralNode) {
            $point = $codePoint($member);
            if (null === $point) {
                return null;
            }

            // A letter past ASCII may fold to an ASCII one ("\x{212A}" is
            // "k"): only ASCII members are read.
            return $point > 0x7F ? null : \in_array(\chr($point), $variants, true);
        }

        if ($member instanceof CharTypeNode) {
            return match ($member->value) {
                'd' => Ascii::isDigit($candidate),
                'D' => !Ascii::isDigit($candidate),
                'w' => Ascii::isAlnum($candidate) || '_' === $candidate,
                'W' => !(Ascii::isAlnum($candidate) || '_' === $candidate),
                's' => ' ' === $candidate,
                'S' => ' ' !== $candidate,
                'h' => ' ' === $candidate,
                'H' => ' ' !== $candidate,
                default => null,
            };
        }

        if ($member instanceof UnicodePropNode) {
            $holds = false;
            foreach ($variants as $variant) {
                $answer = self::propertyHolds($member->prop, $variant);
                if (null === $answer) {
                    return null;
                }

                $holds = $holds || $answer;
            }

            return $holds;
        }

        return $member instanceof PosixClassNode ? self::posixHolds($member->class, $candidate) : null;
    }

    private static function posixCharacter(string $class): ?string
    {
        return match ($class) {
            'alpha', 'alnum', 'lower', 'word', 'graph', 'print', 'ascii' => 'a',
            'upper' => 'A',
            'digit', 'xdigit' => '0',
            'space', 'blank' => ' ',
            'punct' => '!',
            default => null,
        };
    }

    private static function posixHolds(string $class, string $candidate): ?bool
    {
        $negated = str_starts_with($class, '^');
        $holds = match ($negated ? substr($class, 1) : $class) {
            'alpha', 'lower', 'upper' => Ascii::isAlpha($candidate),
            'alnum' => Ascii::isAlnum($candidate),
            'digit' => Ascii::isDigit($candidate),
            'xdigit' => Ascii::isHexDigit($candidate),
            'space', 'blank' => ' ' === $candidate,
            'punct' => ' ' !== $candidate && !Ascii::isAlnum($candidate),
            'word' => Ascii::isAlnum($candidate) || '_' === $candidate,
            'graph' => ' ' !== $candidate,
            'print' => true,
            'ascii' => true,
            default => null,
        };

        return null === $holds ? null : $holds !== $negated;
    }
}
