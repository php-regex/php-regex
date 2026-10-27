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

namespace PHPRegex\Tests\Unit\Transpiler;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPRegex\Transpiler\TranspileOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Python's re takes atomic groups and possessive quantifiers from 3.11: a
 * pattern that only runs on 3.11 or later gets them, one that may run on an
 * older Python has them refused. Each pattern was checked with Python 3.11.0,
 * 3.11.4, 3.11.5, 3.11.18, 3.12.0, 3.12.8 and 3.13.18: re.compile(pattern,
 * re.ASCII).search(subject) gives the spans preg_match() gives, the whole
 * match first, then every group by number, null for one unset.
 *
 * A possessive quantifier is the atomic group around the greedy repetition
 * to PCRE, and is written so where Python's own goes wrong: before 3.11.5 a
 * possessive repetition inside another runs past its end
 * ("\A(?:[^\"]++|\"(?:[^\"]*+|\"\")*+\")++" matches all of 'aa"'), and from
 * 3.11 to 3.12.7, and in 3.13.0, one over a group loses the capture an
 * earlier repetition made ("(?:(a)|b)*+c" on "abc" gives group 1 the span
 * (1, 1)) or raises SystemError ("(?:(a)|b|c)++" on "abc").
 */
final class PythonMinimumVersionTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    public function test_no_minimum_python_version_is_assumed_by_default(): void
    {
        $this->assertNull((new TranspileOptions())->pythonVersion);
    }

    /**
     * @param list<?array{int, int}>|null $spans byte offsets, as preg_match() gives them
     */
    #[Test]
    #[DataProvider('provideEmittedFromPython311')]
    public function test_emits_atomic_groups_and_possessive_quantifiers_from_python_3_11(string $pattern, string $before3115, string $python, string $subject, ?array $spans): void
    {
        $this->assertSame($spans, self::spans($pattern, $subject), 'Oracle: '.$pattern);

        foreach (['3.11' => $before3115, '3.11.0' => $before3115, '3.11.4' => $before3115, '3.11.5' => $python, '3.12' => $python, '3.14' => $python, '4.0' => $python] as $version => $expected) {
            $result = Regex::create(['cache' => null])->transpile($pattern, 'python', new TranspileOptions(pythonVersion: (string) $version));

            $this->assertSame($expected, $result->pattern, 'Python '.$version);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, before3115: string, python: string, subject: string, spans: list<?array{int, int}>|null}>
     */
    public static function provideEmittedFromPython311(): iterable
    {
        yield 'an atomic group' => ['pattern' => '/(?>a|ab)c/', 'before3115' => '(?>a|ab)c', 'python' => '(?>a|ab)c', 'subject' => 'abc', 'spans' => null];
        yield 'an atomic repetition' => ['pattern' => '/(?>a+)a/', 'before3115' => '(?>a+)a', 'python' => '(?>a+)a', 'subject' => 'aaa', 'spans' => null];
        yield 'a repeated atomic group with a group' => ['pattern' => '/(?>(a)|b)+c/', 'before3115' => '(?>(a)|b)+c', 'python' => '(?>(a)|b)+c', 'subject' => 'abc', 'spans' => [[0, 3], [0, 1]]];
        yield 'an atomic group in a lookbehind' => ['pattern' => '/(?<=(?>ab))c/', 'before3115' => '(?<=(?>ab))c', 'python' => '(?<=(?>ab))c', 'subject' => 'abc', 'spans' => [[2, 3]]];
        yield 'a possessive plus' => ['pattern' => '/a++a/', 'before3115' => '(?>a+)a', 'python' => 'a++a', 'subject' => 'aaa', 'spans' => null];
        yield 'a possessive star' => ['pattern' => '/a*+b/', 'before3115' => '(?>a*)b', 'python' => 'a*+b', 'subject' => 'aab', 'spans' => [[0, 3]]];
        yield 'a possessive option' => ['pattern' => '/a?+a/', 'before3115' => '(?>a?)a', 'python' => 'a?+a', 'subject' => 'a', 'spans' => null];
        yield 'a possessive range' => ['pattern' => '/a{1,3}+a/', 'before3115' => '(?>a{1,3})a', 'python' => 'a{1,3}+a', 'subject' => 'aaaa', 'spans' => [[0, 4]]];
        yield 'a possessive count' => ['pattern' => '/a{2}+b/', 'before3115' => '(?>a{2})b', 'python' => 'a{2}+b', 'subject' => 'aab', 'spans' => [[0, 3]]];
        yield 'a possessive open range' => ['pattern' => '/a{2,}+a/', 'before3115' => '(?>a{2,})a', 'python' => 'a{2,}+a', 'subject' => 'aaa', 'spans' => null];
        yield 'a possessive repetition of a class' => ['pattern' => '/[ab]*+b/', 'before3115' => '(?>[ab]*)b', 'python' => '[ab]*+b', 'subject' => 'aab', 'spans' => null];
        yield 'a possessive repetition of a group without capture' => ['pattern' => '/(?:a|ab)++c/', 'before3115' => '(?>(?:a|ab)+)c', 'python' => '(?:a|ab)++c', 'subject' => 'abc', 'spans' => null];
        // /U swaps greedy and lazy, and leaves a possessive quantifier alone.
        // Before 3.11.5 a possessive repetition inside another runs past its end.
        yield 'a possessive repetition inside another' => ['pattern' => '/\A(?:[^"]++|"(?:[^"]*+|"")*+")++/', 'before3115' => '\A(?>(?:(?>[^"]+)|"(?>(?:(?>[^"]*)|"")*)")+)', 'python' => '\A(?:[^"]++|"(?:[^"]*+|"")*+")++', 'subject' => 'aa"', 'spans' => [[0, 2]]];
        yield 'a possessive quantifier under U' => ['pattern' => '/a++/U', 'before3115' => '(?>a+)', 'python' => 'a++', 'subject' => 'aaa', 'spans' => [[0, 3]]];
        // A group under a possessive quantifier: the atomic group around the greedy repetition.
        yield 'a possessive star over a group' => ['pattern' => '/(?:(a)|b)*+c/', 'before3115' => '(?>(?:(a)|b)*)c', 'python' => '(?>(?:(a)|b)*)c', 'subject' => 'abc', 'spans' => [[0, 3], [0, 1]]];
        yield 'a possessive plus over a group' => ['pattern' => '/(?:(a)|b|c)++/', 'before3115' => '(?>(?:(a)|b|c)+)', 'python' => '(?>(?:(a)|b|c)+)', 'subject' => 'abc', 'spans' => [[0, 3], [0, 1]]];
        yield 'a possessive count over a group' => ['pattern' => '/(?:(a)|b){2}+c/', 'before3115' => '(?>(?:(a)|b){2})c', 'python' => '(?>(?:(a)|b){2})c', 'subject' => 'abc', 'spans' => [[0, 3], [0, 1]]];
        yield 'a possessive capture' => ['pattern' => '/(a|ab)++c/', 'before3115' => '(?>(a|ab)+)c', 'python' => '(?>(a|ab)+)c', 'subject' => 'abc', 'spans' => null];
        yield 'a possessive option over a group' => ['pattern' => '/(a)?+a/', 'before3115' => '(?>(a)?)a', 'python' => '(?>(a)?)a', 'subject' => 'a', 'spans' => null];
        yield 'a possessive repetition of an empty group' => ['pattern' => '/(a?)*+b/', 'before3115' => '(?>(a?)*)b', 'python' => '(?>(a?)*)b', 'subject' => 'aab', 'spans' => [[0, 3], [2, 2]]];
    }

    #[Test]
    #[DataProvider('provideRefusedBeforePython311')]
    public function test_refuses_atomic_groups_and_possessive_quantifiers_before_python_3_11(string $pattern, ?string $version, string $message): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains($message);

        Regex::create(['cache' => null])->transpile($pattern, 'python', new TranspileOptions(pythonVersion: $version));
    }

    /**
     * @return iterable<string, array{pattern: string, version: ?string, message: string}>
     */
    public static function provideRefusedBeforePython311(): iterable
    {
        yield 'an atomic group for any Python' => ['pattern' => '/(?>a)b/', 'version' => null, 'message' => 'Atomic groups are not supported in Python re.'];
        yield 'an atomic group for Python 3.10' => ['pattern' => '/(?>a)b/', 'version' => '3.10', 'message' => 'Atomic groups are not supported in Python re.'];
        yield 'a possessive quantifier for any Python' => ['pattern' => '/a++b/', 'version' => null, 'message' => 'Possessive quantifiers are not supported in Python standard re module.'];
        yield 'a possessive quantifier for Python 3.10.12' => ['pattern' => '/a*+b/', 'version' => '3.10.12', 'message' => 'Possessive quantifiers are not supported in Python standard re module.'];
        yield 'a possessive quantifier for Python 2.7' => ['pattern' => '/a?+b/', 'version' => '2.7', 'message' => 'Possessive quantifiers are not supported in Python standard re module.'];
    }

    #[Test]
    public function test_the_python_version_leaves_javascript_alone(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains('Atomic groups are not supported in JavaScript.');

        Regex::create(['cache' => null])->transpile('/(?>a)b/', 'javascript', new TranspileOptions(pythonVersion: '3.11'));
    }

    #[Test]
    #[DataProvider('provideMalformedVersions')]
    public function test_refuses_a_python_version_that_is_no_release_number(string $version): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains('"pythonVersion" must be a Python release, as "3.11" or "3.11.4"');

        new TranspileOptions(pythonVersion: $version);
    }

    /**
     * @return iterable<string, array{version: string}>
     */
    public static function provideMalformedVersions(): iterable
    {
        yield 'a word' => ['version' => 'latest'];
        yield 'a major version alone' => ['version' => '3'];
        yield 'an empty string' => ['version' => ''];
        yield 'a release candidate' => ['version' => '3.11rc1'];
        yield 'a trailing dot' => ['version' => '3.11.'];
    }

    /**
     * @return list<?array{int, int}>|null
     */
    private static function spans(string $pattern, string $subject): ?array
    {
        if (1 !== preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        return array_values(array_map(static fn (array $match): ?array => null === $match[0] ? null : [$match[1], $match[1] + \strlen($match[0])], array_filter($matches, is_int(...), \ARRAY_FILTER_USE_KEY)));
    }
}
