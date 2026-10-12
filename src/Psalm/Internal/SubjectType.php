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

namespace PHPRegex\Psalm\Internal;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\RegexParser;
use Psalm\Type\Atomic\TNonEmptyString;
use Psalm\Type\Atomic\TNonFalsyString;
use Psalm\Type\Atomic\TString;

/**
 * What a string subject holds where preg_match() returned 1, asked of the
 * running engine: the call returns 1 only for a subject the pattern matches
 * from the offset, so a subject the pattern never matches is ruled out.
 * Only '' and '0' matter, the two strings that are falsy; '' is ruled out
 * where the call returns 0 on it, '0' where it returns 0 on it from each
 * offset that reaches into it.
 *
 * An offset past the end of the subject makes the call fail, whatever the
 * pattern: preg_match('/\G$/', '', $m, 0, 1) returns false. A negative
 * offset counts from the end and stops at 0. So '' is read from 0 only, and
 * '0' from 0 and from 1, where \G matches its end. A call that fails (a
 * backtrack limit the runtime may raise) rules nothing out.
 *
 * A pattern anchored at both ends may match only a few strings: then the
 * subject is one of them (stringsOfMatch()).
 *
 * @internal
 */
final class SubjectType
{
    /**
     * Past this many strings, the subject keeps the facts of its pattern, as
     * a group keeps its own past as many values.
     */
    private const MAX_STRINGS = 16;

    /**
     * @var array<string, non-empty-list<string>|null> by target, pattern and Psalm's literal length
     */
    private static array $strings = [];

    /**
     * The type of the subject where preg_match() returned 1, or null when
     * the pattern may match ''.
     *
     * @param int|null $offset the offset argument's value, 0 when there is none, null when it is not one constant
     */
    public static function ofMatch(string $pattern, ?int $offset): ?TString
    {
        if (self::mayMatch($pattern, '', self::offsetsInto(0, $offset))) {
            return null;
        }

        return self::mayMatch($pattern, '0', self::offsetsInto(1, $offset)) ? new TNonEmptyString() : new TNonFalsyString();
    }

    /**
     * Every string preg_match() returns 1 for from offset 0, when they are
     * at most MAX_STRINGS, each shorter than $maxLength bytes: where the
     * call returned 1, the subject is one of them. The solver reads the
     * pattern searched anywhere in the subject, so a "\n" that $ lets
     * through before the end is among them. Null when the solver cannot
     * read the pattern, when the strings are more or longer, or when the
     * running engine does not match one of them.
     *
     * @return non-empty-list<string>|null
     */
    public static function stringsOfMatch(string $pattern, RegexParser $parser, int $maxLength): ?array
    {
        $target = $parser->target();
        $key = $target->phpVersionId.'/'.$target->pcreVersion."\0".$pattern."\0".$maxLength;

        return \array_key_exists($key, self::$strings) ? self::$strings[$key] : self::$strings[$key] = self::readStrings($pattern, $parser, $maxLength);
    }

    /**
     * @return non-empty-list<string>|null
     */
    private static function readStrings(string $pattern, RegexParser $parser, int $maxLength): ?array
    {
        try {
            $language = (new LanguageSolver($parser))->language($pattern, new SolverOptions(matchMode: MatchMode::Partial));
            if (!$language->isFinite()) {
                return null;
            }

            $strings = [];
            foreach ($language->strings() as $string) {
                // The engine has the last word: a string it does not match drops them all.
                if (self::MAX_STRINGS === \count($strings) || \strlen($string) >= $maxLength || 1 !== @preg_match($pattern, $string)) {
                    return null;
                }

                $strings[] = $string;
            }
        } catch (ExceptionInterface) {
            return null; // Past the solver's regular subset or its budgets.
        }

        return [] === $strings ? null : $strings;
    }

    /**
     * The offsets the call may start from in a subject of that length: 0 to
     * the length, those a constant offset reaches.
     *
     * @return list<int>
     */
    private static function offsetsInto(int $length, ?int $offset): array
    {
        if (null === $offset) {
            return range(0, $length);
        }

        $start = $offset < 0 ? max(0, $length + $offset) : $offset;

        return $start <= $length ? [$start] : [];
    }

    /**
     * Whether the call may return 1 on the subject from one of the offsets:
     * true unless it returns 0 from each.
     *
     * @param list<int> $offsets
     */
    private static function mayMatch(string $pattern, string $subject, array $offsets): bool
    {
        foreach ($offsets as $offset) {
            if (0 !== @preg_match($pattern, $subject, $matches, 0, $offset)) {
                return true;
            }
        }

        return false;
    }
}
