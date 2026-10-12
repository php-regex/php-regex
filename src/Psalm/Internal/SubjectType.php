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
 * @internal
 */
final class SubjectType
{
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
