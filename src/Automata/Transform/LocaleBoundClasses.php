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

namespace PHPRegex\Automata\Transform;

use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassHir;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\LiteralHir;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\PcreTarget;

/**
 * The classes of a pattern whose characters past ASCII the tables of the
 * locale decide, where the solvers cannot know those tables. Without "u",
 * PHP hands PCRE2 the tables of the LC_CTYPE locale a program sets: under
 * en_IE.ISO8859-1 "\w" takes "\xE9" and "/\xC9/i" takes "\xE9", which the
 * tables of C give neither. A target that names no locale but C may run
 * under any; one that names another was read under its tables.
 *
 * "\w", "\s", their negations and the POSIX classes are read from the
 * tables, and so is a class under a caseless option that holds some of the
 * letters and the bytes past ASCII but not all: the tables give each its
 * other case. "[^.]" under "i" holds all of them under every locale. The
 * digits, "\h", "\v", "\R", the dot and a class of characters written out
 * are the same under every locale.
 *
 * @internal
 */
final class LocaleBoundClasses
{
    private const TYPES = ['w', 'W', 's', 'S'];

    /**
     * Where each class whose members the tables decide starts, read once a
     * class is asked about.
     *
     * @var array<int, true>|null
     */
    private ?array $positions = null;

    /**
     * Whether a caseless option holds anywhere, read with the positions.
     */
    private bool $caseless = false;

    /**
     * The characters the tables may give another case: the ASCII letters
     * and the bytes past ASCII.
     */
    private ?CharSet $foldable = null;

    private function __construct(private readonly RegexNode $regex) {}

    /**
     * The classes the tables decide, or null when the tables the pattern
     * is read under are the ones it runs under.
     */
    public static function of(RegexNode $regex, PcreTarget $target): ?self
    {
        return str_contains($regex->flags, 'u') || CharacterTables::BUILT_IN !== $target->locale ? null : new self($regex);
    }

    /**
     * Whether the tables decide the characters past ASCII of the node: a
     * class they decide.
     */
    public function binds(Hir $node): bool
    {
        if (!$node instanceof ClassHir) {
            return false;
        }

        if (null === $this->positions) {
            $this->positions = self::positionsIn($this->regex);
            $this->caseless = str_contains($this->regex->flags, 'i') || self::turnsCaselessOn($this->regex->pattern);
        }

        if (isset($this->positions[$node->startPosition])) {
            return true;
        }

        if (!$this->caseless) {
            return false;
        }

        $this->foldable ??= CharSet::fromRanges([[0x41, 0x5A], [0x61, 0x7A], [0x80, 0xFF]]);
        $held = $node->set->intersect($this->foldable);

        return !$held->isEmpty() && $held->key() !== $this->foldable->key();
    }

    /**
     * Whether some node under the root, the root included, is a class the
     * tables decide.
     */
    public function bindsSomeIn(Hir $root): bool
    {
        $pending = [$root];
        while (null !== $node = array_pop($pending)) {
            if ($this->binds($node)) {
                return true;
            }

            array_push($pending, ...$node->children());
        }

        return false;
    }

    /**
     * Whether some class under the root the tables decide may read one of
     * the characters, as a class that stands right after a commitment
     * gives all it reads to what follows the commitment. A class of no
     * character under C's tables may read some under another's.
     */
    public function bindsSomeMeeting(Hir $root, CharSet $chars): bool
    {
        $pending = [$root];
        while (null !== $node = array_pop($pending)) {
            if ($node instanceof ClassHir && $this->binds($node) && ($node->set->isEmpty() || !$node->set->intersect($chars)->isEmpty())) {
                return true;
            }

            array_push($pending, ...$node->children());
        }

        return false;
    }

    /**
     * Whether the set holds a byte past ASCII, a code point from 0x80 to
     * 0xFF under UTF: the tables stop at 0xFF.
     */
    public static function pastAscii(CharSet $set): bool
    {
        return !$set->intersect(CharSet::range(0x80, 0xFF))->isEmpty();
    }

    /**
     * Whether a class or a literal under the root reads a byte past ASCII.
     */
    public static function readsPastAscii(Hir $root): bool
    {
        $pending = [$root];
        while (null !== $node = array_pop($pending)) {
            if (($node instanceof ClassHir && self::pastAscii($node->set))
                || ($node instanceof LiteralHir && [] !== array_filter($node->codePoints, static fn (int $codePoint): bool => $codePoint >= 0x80 && $codePoint <= 0xFF))) {
                return true;
            }

            array_push($pending, ...$node->children());
        }

        return false;
    }

    /**
     * @return array<int, true>
     */
    private static function positionsIn(RegexNode $regex): array
    {
        $positions = [];
        $pending = [$regex->pattern];
        while (null !== $node = array_pop($pending)) {
            if (($node instanceof CharTypeNode && \in_array($node->value, self::TYPES, true))
                || (($node instanceof CharClassNode || $node instanceof ExtendedCharClassNode) && self::holdsTableMember($node))) {
                $positions[$node->getStartPosition()] = true;

                continue;
            }

            array_push($pending, ...$node->getChildren());
        }

        return $positions;
    }

    private static function holdsTableMember(NodeInterface $class): bool
    {
        $pending = $class->getChildren();
        while (null !== $node = array_pop($pending)) {
            if ($node instanceof PosixClassNode || ($node instanceof CharTypeNode && \in_array($node->value, self::TYPES, true))) {
                return true;
            }

            array_push($pending, ...$node->getChildren());
        }

        return false;
    }

    /**
     * Whether a group turns "i" on, as "(?i)", "(?i:...)" or "(?^i)" do.
     */
    private static function turnsCaselessOn(NodeInterface $root): bool
    {
        $pending = [$root];
        while (null !== $node = array_pop($pending)) {
            if ($node instanceof GroupNode && null !== $node->flags && str_contains(explode('-', $node->flags)[0], 'i')) {
                return true;
            }

            array_push($pending, ...$node->getChildren());
        }

        return false;
    }
}
