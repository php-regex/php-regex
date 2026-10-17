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

namespace PHPRegex\Tests\Support;

/**
 * The rows both doors into the match assertion run: the constraint
 * (assertThat + MatchesPattern) and the trait (assertPatternMatches), so the
 * two entry points cannot drift apart on what the vocabulary accepts.
 *
 * Every row is grounded in the running engine under
 * PREG_UNMATCHED_AS_NULL | PREG_OFFSET_CAPTURE: a group that participates is
 * [string value, int byte offset], one that does not is a null value at
 * offset -1, and offsets count bytes even under /u.
 */
trait MatchesPatternCases
{
    /**
     * @return iterable<string, array{pattern: string, subject: string, expected: array<int|string, mixed>}>
     */
    public static function provideMatchExpectations(): iterable
    {
        yield 'plain boolean match' => ['pattern' => '/world/', 'subject' => 'hello world', 'expected' => []];
        yield 'empty pattern matches anything' => ['pattern' => '//', 'subject' => 'anything', 'expected' => []];
        yield 'empty pattern on the empty subject' => ['pattern' => '/^$/', 'subject' => '', 'expected' => [0 => '']];
        yield 'non slash delimiter' => ['pattern' => '#a+#', 'subject' => 'aaa', 'expected' => []];
        // Under x the layout between the atoms is not part of the pattern.
        yield 'x modifier drops the layout' => ['pattern' => '/ a b /x', 'subject' => 'ab', 'expected' => []];
        // n turns capturing groups off: the plain match still answers, and
        // only group 0 exists for the vocabulary to speak of.
        yield 'n modifier plain match' => ['pattern' => '/a/n', 'subject' => 'a', 'expected' => []];
        // The verb holds ) as a delimiter no more than * or _: the guard
        // re-delimits the pattern around it.
        yield 'held-by-verb delimiter re-delimited' => ['pattern' => ')a+)', 'subject' => 'aaa', 'expected' => []];
        // Every replacement delimiter appears in the body, so the verb finds
        // no place and the attempt runs on a fresh cache key instead.
        yield 'every replacement delimiter used falls back to a fresh cache key' => ['pattern' => "*\x01#~%!@;,a+*", 'subject' => "\x01#~%!@;,aaa", 'expected' => []];
        // Under n only NAMED groups capture, renumbered from 1: the engine
        // fills x, and the number the renumbering gave it, alike.
        yield 'named group captures under the n modifier' => ['pattern' => '/(?<x>b)/n', 'subject' => 'ab', 'expected' => ['x' => 'b']];
        // A digit-like key is always a group number, never a name: PCRE2
        // forbids digit-leading names, so '1' can only mean group 1.
        yield 'a digit-like key reads as the group number' => ['pattern' => '/(a)/', 'subject' => 'a', 'expected' => ['1' => 'a']];
        yield 'the named group is number 1 under the n modifier' => ['pattern' => '/(?<x>b)/n', 'subject' => 'ab', 'expected' => [1 => 'b']];
        yield 'a demoted un-named group leaves its number to the named ones' => ['pattern' => '/(a)(?<x>b)/n', 'subject' => 'ab', 'expected' => [1 => 'b']];
        yield 'named groups renumber around a demoted one' => ['pattern' => '/^(?<a>a)(b)(?<c>c)$/n', 'subject' => 'abc', 'expected' => ['a' => 'a', 2 => 'c']];
        yield 'participated and absent group by number' => ['pattern' => '/(a)(b)?/', 'subject' => 'a', 'expected' => [1 => true, 2 => false]];
        yield 'exact value by number' => ['pattern' => '/(a)(b)?/', 'subject' => 'ab', 'expected' => [1 => 'a']];
        yield 'value and byte offset by number' => ['pattern' => '/(a)(b)?/', 'subject' => 'ab', 'expected' => [1 => ['a', 0]]];
        // The engine fills group 0 as ['bc', 1] and group 1 as ['c', 2].
        yield 'whole match with its offset' => ['pattern' => '/b(c)/', 'subject' => 'abc', 'expected' => [0 => ['bc', 1], 1 => ['c', 2]]];
        yield 'named groups by exact value' => ['pattern' => '/(?<year>\d{4})-(?<month>\d{2})/', 'subject' => '2026-10', 'expected' => ['year' => '2026', 'month' => '10']];
        // "é" is two bytes: the b sits at byte 4, character 3. The offset
        // the vocabulary spells is the engine's, a byte count.
        yield 'named group with a byte offset under u' => ['pattern' => '/(?<tail>b)/u', 'subject' => 'éa b', 'expected' => ['tail' => ['b', 4]]];
        // The verb sets the UTF mode the modifier would: offsets stay bytes.
        yield 'utf verb counts bytes without the u modifier' => ['pattern' => '/(*UTF)b/', 'subject' => 'éa b', 'expected' => [0 => ['b', 4]]];
        // \K moves where the engine says the match starts; the offsets
        // reported are the engine's, not the pattern's arithmetic.
        yield 'keep-out verb moves the reported start' => ['pattern' => '/x\Ka/', 'subject' => 'xa', 'expected' => [0 => ['a', 1]]];
        yield 'inline case scope and its cancellation' => ['pattern' => '/(?i)A(?-i)B/', 'subject' => 'aB', 'expected' => []];
        // Group 1 never participates on "x" while group 2 matches the empty
        // string: '' and false are different answers.
        yield 'matched empty is the empty string, not false' => ['pattern' => '/(a)?(b*)/', 'subject' => 'x', 'expected' => [1 => false, 2 => '']];
        yield 'matched empty participates for any value' => ['pattern' => '/(a)?(b*)/', 'subject' => 'x', 'expected' => [2 => true]];
        // Branch reset numbers both captures 1: the engine fills [1 => 'b'].
        yield 'branch reset shares one number' => ['pattern' => '/(?|(a)|(b))/', 'subject' => 'b', 'expected' => [1 => 'b']];
        // The conditional took the no-group road, so group 1 stayed out.
        yield 'conditional leaves the group out' => ['pattern' => '/(a)?b(?(1)c|d)/', 'subject' => 'bd', 'expected' => [1 => false]];
    }
}
