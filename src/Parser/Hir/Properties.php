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

/**
 * What every match of a Hir node has in common, worked out once, bottom up,
 * when the node is built.
 *
 * Lengths count the characters of the alphabet: code points under /u, bytes
 * without it, and measure the text a match reads, "\K" or not. Each property
 * holds for every match the engine can report, whatever the subject: a bound
 * is a bound, a set holds at least every character it stands for, and null
 * says the property could not be worked out (a backreference, a subroutine
 * call, an option the model leaves out).
 *
 * @internal
 */
final readonly class Properties
{
    /**
     * Literals longer than this are cut: a prefix a few hundred characters
     * long already says what it has to.
     */
    public const MAX_LITERAL = 256;

    /**
     * @param int            $minLength    no match reads fewer characters
     * @param int|null       $maxLength    no match reads more; null when unbounded or unknown
     * @param bool|null      $nullable     whether a match can read nothing; null when unknown
     * @param CharSet|null   $first        the characters a match that reads any starts with; null when unknown
     * @param CharSet|null   $last         the characters a match that reads any ends with; null when unknown
     * @param list<int>|null $literal      the one text every match reads, when there is one
     * @param list<int>      $prefix       the text every match starts with
     * @param list<int>      $suffix       the text every match ends with
     * @param int            $captureCount the capturing groups written inside the node
     * @param bool           $regular      whether the node is a regular expression in the
     *                                     textbook sense: characters, concatenation,
     *                                     alternation and greedy or lazy repetition only
     * @param bool           $accepts      whether a "(*ACCEPT)" inside may end the match there
     */
    public function __construct(
        public int $minLength,
        public ?int $maxLength,
        public ?bool $nullable,
        public ?CharSet $first,
        public ?CharSet $last,
        public ?array $literal,
        public array $prefix,
        public array $suffix,
        public int $captureCount,
        public bool $regular,
        public bool $accepts,
    ) {}

    public static function empty(): self
    {
        return self::zeroWidth(true);
    }

    /**
     * An assertion, a lookaround, a verb: reads nothing when it matches.
     */
    public static function zeroWidth(bool $regular = false, int $captureCount = 0, bool $accepts = false): self
    {
        $none = CharSet::empty();

        return new self(0, 0, true, $none, $none, $accepts ? null : [], [], [], $captureCount, $regular, $accepts);
    }

    /**
     * A node nothing is known about but the groups written in it.
     */
    public static function unknown(int $captureCount = 0): self
    {
        return new self(0, null, null, null, null, null, [], [], $captureCount, false, false);
    }

    /**
     * @param list<int> $codePoints
     */
    public static function literal(array $codePoints): self
    {
        $length = \count($codePoints);
        if (0 === $length) {
            return self::empty();
        }

        $first = CharSet::single($codePoints[0]);
        $last = CharSet::single($codePoints[$length - 1]);
        $text = self::cut($codePoints);

        return new self($length, $length, false, $first, $last, $length <= self::MAX_LITERAL ? $codePoints : null, $text, self::cutEnd($codePoints), 0, true, false);
    }

    public static function characterClass(CharSet $set): self
    {
        $single = self::singleOf($set);
        $text = null === $single ? [] : [$single];

        return new self(1, 1, false, $set, $set, null === $single ? null : $text, $text, $text, 0, true, false);
    }

    /**
     * @param list<self> $parts
     */
    public static function concat(array $parts): self
    {
        $min = 0;
        $max = 0;
        $accepted = false;
        $nullable = true;
        $falseBeforeAccept = false;
        $literal = [];
        $captures = 0;
        $regular = true;

        foreach ($parts as $part) {
            // Past a "(*ACCEPT)" the match may already be over.
            if (!$accepted) {
                $min = self::add($min, $part->minLength);
                if (false === $part->nullable) {
                    $falseBeforeAccept = true;
                }
            }

            $accepted = $accepted || $part->accepts;
            $max = null === $max || null === $part->maxLength ? null : self::add($max, $part->maxLength);
            $nullable = true === $nullable && true === $part->nullable;
            $literal = null === $literal || null === $part->literal || $part->accepts ? null : [...$literal, ...$part->literal];
            $captures += $part->captureCount;
            $regular = $regular && $part->regular;
        }

        if (null !== $literal && \count($literal) > self::MAX_LITERAL) {
            $literal = null;
        }

        return new self(
            $min,
            $max,
            $falseBeforeAccept ? false : ($nullable ? true : null),
            self::firstOf($parts),
            $accepted ? self::unionOf(array_map(static fn (self $part): ?CharSet => $part->last, $parts)) : self::firstOf(array_reverse($parts), true),
            $literal,
            self::prefixOf($parts),
            $accepted ? [] : self::suffixOf($parts),
            $captures,
            $regular,
            $accepted,
        );
    }

    /**
     * @param list<self> $branches
     */
    public static function alternation(array $branches): self
    {
        $min = null;
        $max = 0;
        $nullable = false;
        $unknown = false;
        $literal = $branches[0]->literal ?? null;
        $prefix = null;
        $suffix = null;
        $captures = 0;
        $regular = true;
        $accepts = false;

        foreach ($branches as $branch) {
            $min = null === $min ? $branch->minLength : min($min, $branch->minLength);
            $max = null === $max || null === $branch->maxLength ? null : max($max, $branch->maxLength);
            $nullable = $nullable || true === $branch->nullable;
            $unknown = $unknown || null === $branch->nullable;
            $literal = $literal === $branch->literal ? $literal : null;
            $prefix = null === $prefix ? $branch->prefix : self::commonPrefix($prefix, $branch->prefix);
            $suffix = null === $suffix ? $branch->suffix : array_reverse(self::commonPrefix(array_reverse($suffix), array_reverse($branch->suffix)));
            $captures += $branch->captureCount;
            $regular = $regular && $branch->regular;
            $accepts = $accepts || $branch->accepts;
        }

        return new self(
            $min ?? 0,
            $max,
            $nullable ? true : ($unknown ? null : false),
            self::unionOf(array_map(static fn (self $branch): ?CharSet => $branch->first, $branches)),
            self::unionOf(array_map(static fn (self $branch): ?CharSet => $branch->last, $branches)),
            $literal,
            $prefix ?? [],
            $suffix ?? [],
            $captures,
            $regular,
            $accepts,
        );
    }

    public static function repetition(self $body, int $min, ?int $max, Greed $greed): self
    {
        if (0 === $max) {
            return self::zeroWidth(true, $body->captureCount);
        }

        $literal = null;
        $prefix = [];
        $suffix = [];
        if ($min > 0 && null !== $body->literal && !$body->accepts) {
            // Every copy reads the same text: the first ones start the match,
            // the last one ends it.
            $copies = self::repeat($body->literal, $min);
            $literal = $min === $max ? $copies : null;
            $prefix = self::cut($copies ?? $body->literal);
            $suffix = self::cutEnd($min === $max ? ($copies ?? $body->literal) : $body->literal);
        } elseif ($min > 0) {
            // The match ends with the last copy, unless a "(*ACCEPT)" ends it
            // inside one.
            $prefix = $body->prefix;
            $suffix = $body->accepts ? [] : $body->suffix;
        }

        return new self(
            self::multiply($body->minLength, $min) ?? \PHP_INT_MAX,
            null === $max || null === $body->maxLength ? (0 === $body->maxLength ? 0 : null) : self::multiply($body->maxLength, $max),
            0 === $min ? true : $body->nullable,
            $body->first,
            $body->last,
            $literal,
            $prefix,
            $suffix,
            $body->captureCount,
            $body->regular && Greed::Possessive !== $greed,
            $body->accepts,
        );
    }

    /**
     * Either branch, as the condition decides; the groups written in the
     * condition count too.
     */
    public static function conditional(self $yes, self $no, int $conditionCaptureCount): self
    {
        $either = self::alternation([$yes, $no]);

        return new self($either->minLength, $either->maxLength, $either->nullable, $either->first, $either->last, $either->literal, $either->prefix, $either->suffix, $either->captureCount + $conditionCaptureCount, false, $either->accepts);
    }

    public function withCapture(): self
    {
        return new self($this->minLength, $this->maxLength, $this->nullable, $this->first, $this->last, $this->literal, $this->prefix, $this->suffix, $this->captureCount + 1, $this->regular, $this->accepts);
    }

    /**
     * The same matches, read by a construct the textbook model has no
     * place for: an atomic group, a script run.
     */
    public function irregular(): self
    {
        return new self($this->minLength, $this->maxLength, $this->nullable, $this->first, $this->last, $this->literal, $this->prefix, $this->suffix, $this->captureCount, false, $this->accepts);
    }

    /**
     * The characters every match starts with: those of each part until one
     * that always reads something. Read on the parts reversed, the ones it
     * ends with.
     *
     * @param list<self> $parts
     */
    private static function firstOf(array $parts, bool $last = false): ?CharSet
    {
        $set = CharSet::empty();
        foreach ($parts as $part) {
            $own = $last ? $part->last : $part->first;
            if (null === $own) {
                return null;
            }

            $set = $set->union($own);
            if (false === $part->nullable) {
                break;
            }
        }

        return $set;
    }

    /**
     * @param list<CharSet|null> $sets
     */
    private static function unionOf(array $sets): ?CharSet
    {
        $union = CharSet::empty();
        foreach ($sets as $set) {
            if (null === $set) {
                return null;
            }

            $union = $union->union($set);
        }

        return $union;
    }

    /**
     * The text of each part that always reads the same one, then the start
     * of the first that does not.
     *
     * @param list<self> $parts
     *
     * @return list<int>
     */
    private static function prefixOf(array $parts): array
    {
        $prefix = [];
        foreach ($parts as $part) {
            if (null !== $part->literal && !$part->accepts) {
                $prefix = [...$prefix, ...$part->literal];
                if (\count($prefix) >= self::MAX_LITERAL) {
                    break;
                }

                continue;
            }

            $prefix = [...$prefix, ...$part->prefix];

            break;
        }

        return self::cut($prefix);
    }

    /**
     * @param list<self> $parts
     *
     * @return list<int>
     */
    private static function suffixOf(array $parts): array
    {
        $suffix = [];
        foreach (array_reverse($parts) as $part) {
            if (null !== $part->literal) {
                $suffix = [...$part->literal, ...$suffix];
                if (\count($suffix) >= self::MAX_LITERAL) {
                    break;
                }

                continue;
            }

            $suffix = [...$part->suffix, ...$suffix];

            break;
        }

        return self::cutEnd($suffix);
    }

    /**
     * @param list<int> $left
     * @param list<int> $right
     *
     * @return list<int>
     */
    private static function commonPrefix(array $left, array $right): array
    {
        $common = [];
        $length = min(\count($left), \count($right));
        for ($index = 0; $index < $length && $left[$index] === $right[$index]; $index++) {
            $common[] = $left[$index];
        }

        return $common;
    }

    /**
     * @param list<int> $text
     *
     * @return list<int>|null null when the copies would be longer than a literal is kept
     */
    private static function repeat(array $text, int $times): ?array
    {
        if ([] === $text) {
            return [];
        }

        if (\count($text) * $times > self::MAX_LITERAL) {
            return null;
        }

        $copies = [];
        for ($copy = 0; $copy < $times; $copy++) {
            $copies = [...$copies, ...$text];
        }

        return $copies;
    }

    /**
     * @param list<int> $text
     *
     * @return list<int>
     */
    private static function cut(array $text): array
    {
        return \count($text) > self::MAX_LITERAL ? \array_slice($text, 0, self::MAX_LITERAL) : $text;
    }

    /**
     * @param list<int> $text
     *
     * @return list<int>
     */
    private static function cutEnd(array $text): array
    {
        return \count($text) > self::MAX_LITERAL ? \array_slice($text, -self::MAX_LITERAL) : $text;
    }

    private static function singleOf(CharSet $set): ?int
    {
        return 1 === \count($set->ranges) && $set->ranges[0][0] === $set->ranges[0][1] ? $set->ranges[0][0] : null;
    }

    private static function add(int $left, int $right): int
    {
        return $left > \PHP_INT_MAX - $right ? \PHP_INT_MAX : $left + $right;
    }

    /**
     * @return int|null null when the product does not fit an integer
     */
    private static function multiply(int $length, int $times): ?int
    {
        if (0 === $length || 0 === $times) {
            return 0;
        }

        return $length > intdiv(\PHP_INT_MAX, $times) ? null : $length * $times;
    }
}
