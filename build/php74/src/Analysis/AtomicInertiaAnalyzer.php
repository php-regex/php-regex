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

namespace PHPRegex\Parser\Analysis;

use PHPRegex\Parser\Hir\AlternationHir;
use PHPRegex\Parser\Hir\AssertionHir;
use PHPRegex\Parser\Hir\AssertionKind;
use PHPRegex\Parser\Hir\AtomicHir;
use PHPRegex\Parser\Hir\CaptureHir;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassHir;
use PHPRegex\Parser\Hir\ConcatHir;
use PHPRegex\Parser\Hir\ConditionalHir;
use PHPRegex\Parser\Hir\Greed;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\LiteralHir;
use PHPRegex\Parser\Hir\LookHir;
use PHPRegex\Parser\Hir\LookKind;
use PHPRegex\Parser\Hir\OpaqueHir;
use PHPRegex\Parser\Hir\RepetitionHir;
use PHPRegex\Parser\Node\SubroutineNode;

/**
 * When a commitment changes nothing: a possessive quantifier, an atomic
 * group or "\R" (an atomic group in disguise) reads the same strings, and
 * the same matches, as its backtracking spelling when nothing that follows
 * it could take what it would have to give back.
 *
 * The rule reads the first characters of what follows: the rest of the
 * sequence, through followers that may be skipped, and past the sequence's
 * end whatever follows the sequence itself. A follow that cannot be worked
 * out (null) proves nothing. A commitment with another one further along
 * its sequence is not proven either: each proof reads what follows its own
 * commitment, and the two do not compose.
 *
 * Three shapes are proven:
 *
 * - a possessive quantifier, or an atomic group holding a greedy one,
 *   whose atom matches one fixed way (a class, a literal, a sequence of
 *   them) or is an unbounded run of one class, and whose first characters
 *   no follower starts with: what an iteration gives back no follower
 *   takes, and the next iteration takes it all the same;
 * - an atomic group whose body matches one fixed way: there is nothing to
 *   backtrack into;
 * - an atomic group whose body is an alternation of fixed branches, "\R"
 *   among them, where no branch tried first may match a proper prefix of a
 *   later one, and a later branch that matches a proper prefix of an
 *   earlier one stops where no follower starts: "\R" is "\r\n" or one
 *   vertical space, tried in that order, so it is proven unless a "\n" may
 *   follow it.
 *
 * What a zero-width follower does depends on what is read. Over a whole
 * subject, as the solver reads it, the characters given back must still be
 * consumed by a follower, which no follower starts with: an assertion or a
 * lookaround between them may refuse a match, never make one, and is
 * skipped. A search, as a printer for another engine reads it, may end its
 * match where the commitment gave back, so an assertion that may hold
 * there and not further on proves nothing: "^a++(?=a)" never matches where
 * "^a+(?=a)" matches "aa". Only the ends of the subject, "\z", "\Z" and
 * "$" without /m, hold at no place given back that the run passed, and
 * "$" under /m is read as the "\n" it stands before. That reading is the
 * newline "\n"'s: under another convention, "(*CRLF)" first, a run that
 * takes the "\r" of a final "\r\n" leaves a "\n" no line ends before, so
 * "\Z" and "$" prove nothing there.
 *
 * A lookahead's body ends where it ends, and needs nothing after it. A
 * lookbehind's body must end where the lookbehind stands: PCRE2's
 * interpreter lets a commitment run past that place and fail, its JIT
 * reads the subject as ending there, and only a commitment that cannot run
 * on reads the same under both. A lookaround the match may backtrack into,
 * "(*napla:...)" or "(?*...)", is not read past at all.
 *
 * What a group holds may count beyond what follows it. Nothing is proven
 * in a pattern that calls a group, "(?1)" or "(?R)": the call runs the
 * group's commitments again before other characters. A script run checks
 * its body once it has matched, so nothing in it is proven. Where a
 * conditional tests a group, a commitment holding a capture is not proven:
 * which branch set which group may be what the conditional reads.
 *
 * A printer's plain spelling runs on a backtracking engine: a run of runs,
 * "(?:a+)++", is proven over a whole subject only. Printed plain, it would
 * try every split of the run before rejecting a subject.
 *
 * @internal
 */
final class AtomicInertiaAnalyzer
{
    /**
     */
    private bool $wholeSubject = true;
    /**
     */
    private bool $lineFeedNewline = true;

    /**
     * @param bool $wholeSubject    whether the pattern is read against a whole
     *                              subject (the solver's full match), or as a
     *                              search that may end anywhere (a printer,
     *                              whose plain spelling another backtracking
     *                              engine runs)
     * @param bool $lineFeedNewline whether lines end at "\n", the default
     *                              newline convention, as "$" and "\Z" read
     */
    public function __construct(bool $wholeSubject = true, bool $lineFeedNewline = true)
    {
        $this->wholeSubject = $wholeSubject;
        $this->lineFeedNewline = $lineFeedNewline;
    }

    /**
     * For each part of a sequence, the first characters of everything after
     * it: the rest of the sequence, through the followers that may be
     * skipped, and what follows the sequence itself. All followers
     * skippable and nothing beyond the sequence, an empty set; a follower
     * or continuation whose first characters cannot be worked out, null.
     * Worked out in one pass from the end of the sequence.
     *
     * @param list<Hir> $parts
     *
     * @return list<CharSet|null>
     */
    public function followSets(array $parts, ?CharSet $beyond): array
    {
        $sets = [];
        $rest = $beyond;
        for ($i = \count($parts) - 1; $i >= 0; --$i) {
            $sets[] = $rest;
            $rest = null === $rest ? null : $this->followSetBefore($parts[$i], $rest);
        }

        return array_reverse($sets);
    }

    /**
     * For each part of a sequence, whether a possessive quantifier or an
     * atomic group occurs in it or in a part further along.
     *
     * @param list<Hir> $parts
     *
     * @return list<bool>
     */
    public function commitmentsFrom(array $parts): array
    {
        $from = [];
        $ahead = false;
        for ($i = \count($parts) - 1; $i >= 0; --$i) {
            $ahead = $ahead || self::containsCommitment($parts[$i]);
            $from[] = $ahead;
        }

        return array_reverse($from);
    }

    /**
     * The possessive quantifier or the atomic group a subtree ends with,
     * through captures and sequences, or null. An assertion, an
     * alternation or anything else in the tail is a barrier: what follows
     * it is not what follows the commitment.
     *
     * @return AtomicHir|RepetitionHir|null
     */
    public function commitmentAtEnd(Hir $node)
    {
        while (true) {
            if ($node instanceof RepetitionHir) {
                return Greed::Possessive() === $node->greed ? $node : null;
            }

            if ($node instanceof AtomicHir) {
                return $node;
            }

            if ($node instanceof ConcatHir) {
                $node = $node->parts[\count($node->parts) - 1] ?? null;
                if (null === $node) {
                    return null;
                }

                continue;
            }

            if ($node instanceof CaptureHir) {
                $node = $node->body;

                continue;
            }

            return null;
        }
    }

    /**
     * Whether the commitment a sequence part ends with is proven, before the
     * characters that follow it, when another commitment does or does not
     * occur beyond the part right after it.
     *
     * @param AtomicHir|RepetitionHir $commitment
     */
    public function commitmentIsInert($commitment, ?CharSet $follow, bool $commitsBeyond): bool
    {
        if ($commitsBeyond) {
            return false;
        }

        return $commitment instanceof RepetitionHir
            ? $this->possessiveIsInert($commitment, $follow)
            : $this->atomicIsInert($commitment, $follow);
    }

    /**
     * What follows the end of a lookaround's body. A lookahead's body needs
     * nothing after it: an empty set. A lookbehind's body must end where
     * the lookbehind stands, and the interpreter lets a commitment run past
     * that place and fail there, where its plain spelling stops in time:
     * "(?<=\R)" does not hold between "\r" and "\n". Whatever character
     * stands there may follow, so every character does. A lookaround the
     * match may backtrack into is read again once what follows it fails:
     * null, nothing known.
     */
    public function lookaroundBodyFollow(LookHir $look): ?CharSet
    {
        if (!$look->atomic) {
            return null;
        }

        return LookKind::Behind() === $look->kind || LookKind::NegativeBehind() === $look->kind ? CharSet::universe(true) : CharSet::empty();
    }

    /**
     * Every commitment of the tree the rule proves, by the position it
     * starts at: what a printer for an engine without possessive
     * quantifiers or atomic groups may spell as greedy and plain. The end
     * of the pattern needs nothing more: a match that reached it holds,
     * and nothing backtracks into what came before it.
     */
    public function inertCommitments(Hir $root): InertCommitments
    {
        if (!self::containsCommitment($root) || self::holds($root, static fn (Hir $node): bool => $node instanceof OpaqueHir && $node->node instanceof SubroutineNode)) {
            return new InertCommitments();
        }

        $capturesTested = self::holds($root, static fn (Hir $node): bool => $node instanceof ConditionalHir);
        $possessives = [];
        $atomics = [];
        $this->collectSequence([$root], CharSet::empty(), $capturesTested, $possessives, $atomics);

        return new InertCommitments($possessives, $atomics);
    }

    /**
     * Whether a possessive quantifier reads as its greedy spelling, before
     * the characters given.
     */
    private function possessiveIsInert(RepetitionHir $possessive, ?CharSet $follow): bool
    {
        if (null === $follow) {
            return false;
        }

        $atom = $this->atomFirstSet($possessive->body);

        return null !== $atom && $atom->intersect($follow)->isEmpty();
    }

    /**
     * Whether an atomic group reads as its body, before the characters
     * given.
     */
    private function atomicIsInert(AtomicHir $atomic, ?CharSet $follow): bool
    {
        $body = self::unwrap($atomic->body);

        // One fixed way to match: nothing to backtrack into.
        if (null !== $this->fixedFirstSet($body)) {
            return true;
        }

        if (null === $follow) {
            return false;
        }

        if ($body instanceof RepetitionHir) {
            if (Greed::Lazy() === $body->greed) {
                return false;
            }

            // A greedy repetition held atomic is its possessive spelling.
            $atom = $this->atomFirstSet($body->body);

            return null !== $atom && $atom->intersect($follow)->isEmpty();
        }

        if ($body instanceof AlternationHir) {
            return $this->alternationIsInert($body, $follow);
        }

        return false;
    }

    /**
     * @param bool             $capturesTested whether a conditional of the pattern tests a group
     * @param array<int, true> $possessives
     * @param array<int, true> $atomics
     */
    private function collect(Hir $node, ?CharSet $follow, bool $capturesTested, array &$possessives, array &$atomics): void
    {
        if ($node instanceof LookHir) {
            $this->collectSequence([$node->body], $this->lookaroundBodyFollow($node), $capturesTested, $possessives, $atomics);

            return;
        }

        // A script run checks its body once the body has matched.
        if ($node instanceof OpaqueHir) {
            return;
        }

        if ($node instanceof RepetitionHir) {
            $bodyFollow = null === $follow ? null
                : (null === $node->max || $node->max > 1 ? ($nullsafeVariable1 = $node->body->properties->first) ? $nullsafeVariable1->union($follow) : null : $follow);
            $this->collect($node->body, $bodyFollow, $capturesTested, $possessives, $atomics);

            return;
        }

        if ($node instanceof ConcatHir) {
            $this->collectSequence($node->parts, $follow, $capturesTested, $possessives, $atomics);

            return;
        }

        // An atomic group, a capture, an alternation: each child is
        // followed by what follows the node.
        foreach ($node->children() as $child) {
            $this->collect($child, $follow, $capturesTested, $possessives, $atomics);
        }
    }

    /**
     * The parts of a sequence, each commitment a part ends with judged
     * against what follows it.
     *
     * @param list<Hir>        $parts
     * @param bool             $capturesTested whether a conditional of the pattern tests a group
     * @param array<int, true> $possessives
     * @param array<int, true> $atomics
     */
    private function collectSequence(array $parts, ?CharSet $follow, bool $capturesTested, array &$possessives, array &$atomics): void
    {
        $follows = $this->followSets($parts, $follow);
        $commitments = $this->commitmentsFrom($parts);
        foreach ($parts as $index => $part) {
            $partFollow = $follows[$index];
            $commitment = $this->commitmentAtEnd($part);
            if (null !== $commitment && !($capturesTested && self::holds($commitment, static fn (Hir $node): bool => $node instanceof CaptureHir))
                && $this->commitmentIsInert($commitment, $partFollow, $commitments[$index + 2] ?? false)) {
                if ($commitment instanceof RepetitionHir) {
                    $possessives[$commitment->startPosition] = true;
                } else {
                    $atomics[$commitment->startPosition] = true;
                }
            }

            $this->collect($part, $partFollow, $capturesTested, $possessives, $atomics);
        }
    }

    /**
     * An alternation of fixed branches, tried in order: proven when no
     * branch may match a proper prefix of a later one (the group would
     * keep the shorter and lose the longer, whatever follows), and a later
     * branch that may match a proper prefix of an earlier one stops where
     * no follower starts (the group keeps the longer, where a follower
     * would have needed the shorter).
     */
    private function alternationIsInert(AlternationHir $alternation, CharSet $follow): bool
    {
        $branches = [];
        foreach ($alternation->branches as $branch) {
            $sets = $this->fixedSets($branch);
            if (null === $sets || [] === $sets) {
                return false;
            }

            $branches[] = $sets;
        }

        foreach ($branches as $i => $earlier) {
            foreach (\array_slice($branches, $i + 1) as $later) {
                if (\count($earlier) < \count($later) && self::mayPrefix($earlier, $later)) {
                    return false;
                }

                if (\count($later) < \count($earlier) && self::mayPrefix($later, $earlier)
                    && !$earlier[\count($later)]->intersect($follow)->isEmpty()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether some string of the shorter fixed branch is a prefix of some
     * string of the longer.
     *
     * @param list<CharSet> $shorter
     * @param list<CharSet> $longer
     */
    private static function mayPrefix(array $shorter, array $longer): bool
    {
        foreach ($shorter as $at => $set) {
            if ($set->intersect($longer[$at])->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The character set of each position of a body that matches one fixed
     * way, a class, a literal or a sequence of them, through captures;
     * null otherwise.
     *
     * @return list<CharSet>|null
     */
    private function fixedSets(Hir $body): ?array
    {
        $body = self::unwrap($body);

        if ($body instanceof ClassHir) {
            return $body->set->isEmpty() ? null : [$body->set];
        }

        if ($body instanceof LiteralHir) {
            if ([] === $body->codePoints) {
                return null;
            }

            // A caseless literal reads several characters at one place:
            // its first set says which; a literal of one code point is
            // read by it alone.
            if (1 === \count($body->codePoints)) {
                $first = $body->properties->first;

                return null === $first || $first->isEmpty() ? null : [$first];
            }

            // A caseless literal reads more than its spelling at each
            // place: its first set gives it away, and the plain rule
            // reads it.
            $first = $body->properties->first;
            if (null === $first || $first->key() !== CharSet::single($body->codePoints[0])->key()) {
                return null;
            }

            $sets = [];
            foreach ($body->codePoints as $codePoint) {
                $sets[] = CharSet::single($codePoint);
            }

            return $sets;
        }

        if (!$body instanceof ConcatHir || [] === $body->parts) {
            return null;
        }

        $sets = [];
        foreach ($body->parts as $part) {
            $partSets = $this->fixedSets($part);
            if (null === $partSets) {
                return null;
            }

            array_push($sets, ...$partSets);
        }

        return $sets;
    }

    /**
     * The characters the atom of a repetition consumes first, when an
     * iteration gives nothing back that the follower or the next iteration
     * could take: a class or a literal, a sequence of them that matches one
     * fixed way, or an unbounded greedy run of one class (its characters
     * given back are taken by no follower that starts otherwise, and the
     * next iteration takes them all the same). A body that may match more
     * than one way, "ab?", "a{2,3}", "(?:bc|b)", an alternation, an empty
     * set, or anything that may match nothing first, proves nothing; nor
     * does a lazy run, which stops at its first character where the group
     * would keep it: "(?>(?:a+?){2})b" never matches "aaab" from its start.
     */
    private function atomFirstSet(Hir $atom): ?CharSet
    {
        $atom = self::unwrap($atom);

        if ($atom instanceof RepetitionHir) {
            // Printed plain, a run of runs tries every split of its run.
            if (!$this->wholeSubject || Greed::Greedy() !== $atom->greed || $atom->min < 1 || null !== $atom->max) {
                return null;
            }

            $body = self::unwrap($atom->body);

            return $body instanceof ClassHir || ($body instanceof LiteralHir && 1 === \count($body->codePoints)) ? $this->fixedFirstSet($body) : null;
        }

        return $this->fixedFirstSet($atom);
    }

    /**
     * The characters a body that matches one fixed way starts with: a class,
     * a literal, or a sequence of them, through captures; null otherwise.
     */
    private function fixedFirstSet(Hir $body): ?CharSet
    {
        $body = self::unwrap($body);

        if ($body instanceof ClassHir || $body instanceof LiteralHir) {
            $first = $body->properties->first;

            return null === $first || $first->isEmpty() ? null : $first;
        }

        if (!$body instanceof ConcatHir || [] === $body->parts) {
            return null;
        }

        $first = null;
        foreach ($body->parts as $part) {
            $partFirst = $this->fixedFirstSet($part);
            if (null === $partFirst) {
                return null;
            }

            $first ??= $partFirst;
        }

        return $first;
    }

    /**
     * The first characters of everything from the part on, before the
     * characters that follow it; null when they cannot be worked out.
     */
    private function followSetBefore(Hir $part, CharSet $rest): ?CharSet
    {
        if (!$this->wholeSubject) {
            $zeroWidth = $this->zeroWidthFirst($part);
            if (false === $zeroWidth) {
                return null;
            }

            if (null !== $zeroWidth) {
                return $zeroWidth->union($rest);
            }
        }

        $properties = $part->properties;
        if (null === $properties->first) {
            return null;
        }

        return true === $properties->nullable ? $properties->first->union($rest) : $properties->first;
    }

    /**
     * Under search semantics, what a zero-width part stands before: an
     * empty set for an end of the subject, "\n" for "$" under /m, false for
     * any other assertion or lookaround, or one held anywhere inside a
     * consuming part; null for a consuming part that holds none. "\Z" and
     * "$" read so under the newline "\n" alone.
     *
     * @return CharSet|false|null
     */
    private function zeroWidthFirst(Hir $part)
    {
        if ($part instanceof AssertionHir) {
            switch (true) {
                case $part->kind === AssertionKind::SubjectEnd():
                case $part->kind === AssertionKind::ResetMatchStart():
                    return CharSet::empty();
                case $part->kind === AssertionKind::EndOrFinalNewline():
                    return $this->lineFeedNewline ? CharSet::empty() : false;
                case $part->kind === AssertionKind::LineEnd():
                    return $this->lineFeedNewline ? CharSet::single(0x0A) : false;
                default:
                    return false;
            }
        }

        return self::holdsZeroWidth($part) ? false : null;
    }

    private static function holdsZeroWidth(Hir $node): bool
    {
        if ($node instanceof AssertionHir || $node instanceof LookHir) {
            return true;
        }

        foreach ($node->children() as $child) {
            if (self::holdsZeroWidth($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \Closure(Hir): bool $test
     */
    private static function holds(Hir $node, \Closure $test): bool
    {
        if ($test($node)) {
            return true;
        }

        foreach ($node->children() as $child) {
            if (self::holds($child, $test)) {
                return true;
            }
        }

        return false;
    }

    private static function unwrap(Hir $node): Hir
    {
        while ($node instanceof CaptureHir) {
            $node = $node->body;
        }

        return $node;
    }

    private static function containsCommitment(Hir $node): bool
    {
        if ($node instanceof AtomicHir) {
            return true;
        }

        if ($node instanceof RepetitionHir) {
            return Greed::Possessive() === $node->greed || self::containsCommitment($node->body);
        }

        foreach ($node->children() as $child) {
            if (self::containsCommitment($child)) {
                return true;
            }
        }

        return false;
    }
}
