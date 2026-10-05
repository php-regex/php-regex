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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Concatenated quantifiers around an atom guarded by negative lookaheads,
 * as in "(?:[a-z](?!__))*". The rule claims the rewrite in its hint keeps
 * every match; each row compares the pattern with that rewrite, anchored,
 * over every subject of up to six characters of "abxy1".
 */
final class ConcatenationGuardEdgeCaseTest extends TestCase
{
    private const RULE = 'regex.lint.quantifier.concatenation';

    /**
     * @return iterable<string, array{pattern: string, anchored: string, rewrite: string, reported: bool}>
     */
    public static function provideGuardedAtoms(): iterable
    {
        // The guard follows two characters, not one: the atom is no
        // single-character atom, and dropping it loses "a11".
        yield 'guard after a two-character atom' => [
            'pattern' => '/(?:a\d(?!x))*\d+/',
            'anchored' => '/^(?:a\d(?!x))*\d+$/',
            'rewrite' => '/^\d+$/',
            'reported' => false,
        ];
        // The same with the run's own set first: reading "\d" alone as
        // the atom would claim the term can go, and dropping it loses "1a1".
        yield 'guard after a two-character atom starting with the run' => [
            'pattern' => '/(?:\da(?!x))*\d+/',
            'anchored' => '/^(?:\da(?!x))*\d+$/',
            'rewrite' => '/^\d+$/',
            'reported' => false,
        ];
        // A guard holding a backreference reads more than the next
        // character: tightening ".{1,3}" to "." loses "aaa".
        yield 'guard holding a backreference' => [
            'pattern' => '/(a)(?:.(?!\1))*.{1,3}/',
            'anchored' => '/^(a)(?:.(?!\1))*.{1,3}$/',
            'rewrite' => '/^(a)(?:.(?!\1))*.$/',
            'reported' => false,
        ];
        yield 'guard holding a subroutine call' => [
            'pattern' => '/(a)(?:.(?!(?1)))*.{1,3}/',
            'anchored' => '/^(a)(?:.(?!(?1)))*.{1,3}$/',
            'rewrite' => '/^(a)(?:.(?!(?1)))*.$/',
            'reported' => false,
        ];
        // The guard goes with the characters the unbounded ".+" takes over:
        // dropping the guarded term keeps every match.
        yield 'guard on the side that gives its characters up' => [
            'pattern' => '/(a)(?:.(?!\1))*.+/',
            'anchored' => '/^(a)(?:.(?!\1))*.+$/',
            'rewrite' => '/^(a).+$/',
            'reported' => true,
        ];
        // A guard that can match nothing always fails: "(?!y?)" lets the
        // atom match nowhere, so "a+" cannot be tightened to "a" ("aa").
        yield 'guard that can match nothing' => [
            'pattern' => '/(?:.(?!y?))*a+/',
            'anchored' => '/^(?:.(?!y?))*a+$/',
            'rewrite' => '/^(?:.(?!y?))*a$/',
            'reported' => false,
        ];
        // An atom holding a lookaround is no single character of its set:
        // "a(?=x)" only takes an "a" before an "x" ("aa"), "(?<=x)a" only an
        // "a" after one ("xx").
        yield 'atom ending with a lookahead' => [
            'pattern' => '/(?:a(?=x))*a{1,3}/',
            'anchored' => '/^(?:a(?=x))*a{1,3}$/',
            'rewrite' => '/^(?:a(?=x))*a$/',
            'reported' => false,
        ];
        yield 'atom starting with a lookbehind' => [
            'pattern' => '/(?:(?<=x)a)*x{1,3}/',
            'anchored' => '/^(?:(?<=x)a)*x{1,3}$/',
            'rewrite' => '/^(?:(?<=x)a)*x$/',
            'reported' => false,
        ];
        // Dropping a run that can be empty hands its last character back to
        // the guarded loop, whose guard may refuse what follows: "bax".
        yield 'guard on the unbounded side before a run that can be empty' => [
            'pattern' => '/(?:[a-y](?!x))+a*x/',
            'anchored' => '/^(?:[a-y](?!x))+a*x$/',
            'rewrite' => '/^(?:[a-y](?!x))+x$/',
            'reported' => false,
        ];
        // A lookbehind inside the guard reads text the guard never consumes:
        // its first characters are not the guard's ("baa").
        yield 'guard holding a lookbehind' => [
            'pattern' => '/(?:[a-y](?!(?<!b)a))+a{1,3}/',
            'anchored' => '/^(?:[a-y](?!(?<!b)a))+a{1,3}$/',
            'rewrite' => '/^(?:[a-y](?!(?<!b)a))+a$/',
            'reported' => false,
        ];
        // Under i the guard's "A" is also "a", so it trips
        // before a character of the next set: tightening "[a-z]+" loses "ba".
        yield 'guard of the other case under i' => [
            'pattern' => '/^(?:[a-z](?!A))*[a-z]+$/i',
            'anchored' => '/^(?:[a-z](?!A))*[a-z]+$/i',
            'rewrite' => '/^(?:[a-z](?!A))*[a-z]$/i',
            'reported' => false,
        ];
        yield 'guard of the other case under inline (?i)' => [
            'pattern' => '/(?i)^(?:[a-z](?!A))*[a-z]+$/',
            'anchored' => '/(?i)^(?:[a-z](?!A))*[a-z]+$/',
            'rewrite' => '/(?i)^(?:[a-z](?!A))*[a-z]$/',
            'reported' => false,
        ];
        yield 'guard of a POSIX upper class under i' => [
            'pattern' => '/^(?:[a-z](?![[:upper:]]))*[a-z]+$/i',
            'anchored' => '/^(?:[a-z](?![[:upper:]]))*[a-z]+$/i',
            'rewrite' => '/^(?:[a-z](?![[:upper:]]))*[a-z]$/i',
            'reported' => false,
        ];
        // Without i "A" is no lowercase letter: the guard always holds.
        yield 'guard of the other case without i' => [
            'pattern' => '/^(?:[a-z](?!A))*[a-z]+$/',
            'anchored' => '/^(?:[a-z](?!A))*[a-z]+$/',
            'rewrite' => '/^(?:[a-z](?!A))*[a-z]$/',
            'reported' => true,
        ];
        // An i turned on inside the guard folds its "A" as well: tightening
        // "[a-z]+" loses "aa".
        yield 'guard with an inline (?i) inside it' => [
            'pattern' => '/^(?:[a-z](?!(?i)A))*[a-z]+$/',
            'anchored' => '/^(?:[a-z](?!(?i)A))*[a-z]+$/',
            'rewrite' => '/^(?:[a-z](?!(?i)A))*[a-z]$/',
            'reported' => false,
        ];
        yield 'guard with a scoped (?i:...) inside it' => [
            'pattern' => '/^(?:[a-z](?!(?i:A)))*[a-z]+$/',
            'anchored' => '/^(?:[a-z](?!(?i:A)))*[a-z]+$/',
            'rewrite' => '/^(?:[a-z](?!(?i:A)))*[a-z]$/',
            'reported' => false,
        ];
        // An i scoped to an earlier group ends with it, and an i turned off
        // inside the guard is no i: in both the guard is case-sensitive and
        // holds before every lowercase letter.
        yield 'i scoped to a group before the guarded loop' => [
            'pattern' => '/^(?i:x)(?:[a-z](?!A))*[a-z]+$/',
            'anchored' => '/^(?i:x)(?:[a-z](?!A))*[a-z]+$/',
            'rewrite' => '/^(?i:x)(?:[a-z](?!A))*[a-z]$/',
            'reported' => true,
        ];
        yield 'guard with an i turned off inside it' => [
            'pattern' => '/^(?:[a-z](?!(?-i)A))*[a-z]+$/',
            'anchored' => '/^(?:[a-z](?!(?-i)A))*[a-z]+$/',
            'rewrite' => '/^(?:[a-z](?!(?-i)A))*[a-z]$/',
            'reported' => true,
        ];
    }

    #[Test]
    #[DataProvider('provideGuardedAtoms')]
    public function test_concatenation_with_a_guarded_atom_agrees_with_the_engine(string $pattern, string $anchored, string $rewrite, bool $reported): void
    {
        $this->assertSame($reported, self::sameMatches($anchored, $rewrite), 'Oracle disagrees with the row.');

        $this->assertSame($reported, \in_array(self::RULE, self::lint($pattern), true), $pattern);
    }

    /**
     * @return list<string>
     */
    private static function lint(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);

        return array_values(array_map(static fn ($issue): string => $issue->id, $linter->getIssues()));
    }

    private static function sameMatches(string $left, string $right): bool
    {
        $subjects = [''];
        $layer = [''];
        for ($length = 1; $length <= 6; $length++) {
            $next = [];
            foreach ($layer as $prefix) {
                foreach (str_split('abxy1') as $character) {
                    $next[] = $prefix.$character;
                }
            }
            $layer = $next;
            array_push($subjects, ...$layer);
        }

        foreach ($subjects as $subject) {
            $leftResult = preg_match($left, $subject, $leftMatch);
            $rightResult = preg_match($right, $subject, $rightMatch);
            if ($leftResult !== $rightResult || ($leftMatch[0] ?? null) !== ($rightMatch[0] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
