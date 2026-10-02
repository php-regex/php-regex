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

namespace PHPRegex\Tests\Unit\Hir;

use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\Hir\Properties;
use PHPRegex\Parser\Hir\Utf8;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The properties of a pattern's Hir, and that they hold for every match PHP
 * reports on a few subjects.
 */
final class HirPropertiesTest extends TestCase
{
    /**
     * @param array{0: int, 1: int|null} $lengths
     */
    #[Test]
    #[DataProvider('provideLengths')]
    public function test_lengths_and_nullability(string $pattern, array $lengths, ?bool $nullable): void
    {
        $properties = $this->properties($pattern);

        $this->assertSame($lengths, [$properties->minLength, $properties->maxLength], $pattern);
        $this->assertSame($nullable, $properties->nullable, $pattern);
    }

    /**
     * @return iterable<string, array{string, array{0: int, 1: int|null}, bool|null}>
     */
    public static function provideLengths(): iterable
    {
        yield 'literal' => ['/abc/', [3, 3], false];
        yield 'code points under u' => ['/é+/u', [1, null], false];
        yield 'bytes without u' => ['/é/', [2, 2], false];
        yield 'optional' => ['/ab?c{2,3}/', [3, 5], false];
        yield 'star' => ['/(?:ab)*/', [0, null], true];
        yield 'alternation' => ['/a|bcd|/', [0, 3], true];
        yield 'zero repeats' => ['/a{0}b/', [1, 1], false];
        yield 'anchors read nothing' => ['/^\bab$/', [2, 2], false];
        yield 'lookarounds read nothing' => ['/(?=abc)a(?<!x)/', [1, 1], false];
        yield 'newline sequence' => ['/a\Rb/', [3, 4], false];
        yield 'grapheme cluster' => ['/\X/u', [1, null], false];
        yield 'backreference' => ['/(a)\1/', [1, null], false];
        yield 'only a backreference' => ['/\1(a)?/', [0, null], null];
        yield 'conditional' => ['/^(\()?blah(?(1)(\)))$/', [4, 6], false];
        yield 'accept ends the match' => ['/a(*ACCEPT)bc/', [1, 3], false];
        yield 'accept in a lookahead' => ['/(?=a(*ACCEPT))ab/', [2, 2], false];
        yield 'accept first' => ['/(*ACCEPT)a/', [0, 1], null];
    }

    /**
     * @param list<int> $prefix
     * @param list<int> $suffix
     */
    #[Test]
    #[DataProvider('provideLiterals')]
    public function test_literal_prefix_and_suffix(string $pattern, array $prefix, array $suffix, ?string $literal): void
    {
        $properties = $this->properties($pattern);

        $this->assertSame($prefix, $properties->prefix, $pattern);
        $this->assertSame($suffix, $properties->suffix, $pattern);
        $this->assertSame(null === $literal ? null : self::codePoints($literal), $properties->literal, $pattern);
    }

    /**
     * @return iterable<string, array{string, list<int>, list<int>, string|null}>
     */
    public static function provideLiterals(): iterable
    {
        yield 'literal' => ['/abc/', self::codePoints('abc'), self::codePoints('abc'), 'abc'];
        yield 'common start of branches' => ['/foo(?:bar|baz)/', self::codePoints('fooba'), [], null];
        yield 'common end of branches' => ['/(?:xa|ya)\d/', [], [], null];
        yield 'shared end' => ['/(?:xa|ya)z/', [], self::codePoints('az'), null];
        yield 'anchors do not stop a literal' => ['/^ab$/', self::codePoints('ab'), self::codePoints('ab'), 'ab'];
        yield 'fixed repeat' => ['/(?:ab){3}/', self::codePoints('ababab'), self::codePoints('ababab'), 'ababab'];
        yield 'open repeat' => ['/x(?:ab){2,}y/', self::codePoints('xabab'), self::codePoints('aby'), null];
        yield 'optional start' => ['/a?bc/', [], self::codePoints('bc'), null];
        yield 'caseless has no literal' => ['/ab/i', [], [], null];
        yield 'single character class' => ['/[a]b/', self::codePoints('ab'), self::codePoints('ab'), 'ab'];
        yield 'accept' => ['/ab(*ACCEPT)cd/', self::codePoints('ab'), [], null];
        yield 'unicode' => ['/éa/u', [0xE9, 0x61], [0xE9, 0x61], 'éa'];
    }

    #[Test]
    public function test_first_and_last_sets(): void
    {
        $properties = $this->properties('/(?:a|b?c)\d*[xy]/');

        $this->assertSame([[0x61, 0x63]], $properties->first?->ranges);
        $this->assertSame([[0x78, 0x79]], $properties->last?->ranges);
    }

    #[Test]
    public function test_caseless_sets_hold_what_pcre_folds(): void
    {
        $properties = $this->properties('/(?:k|s)+/iu');

        // The Kelvin sign and the long s match too.
        $this->assertSame([[0x4B, 0x4B], [0x53, 0x53], [0x6B, 0x6B], [0x73, 0x73], [0x17F, 0x17F], [0x212A, 0x212A]], $properties->first?->ranges);
    }

    #[Test]
    public function test_an_unknown_part_makes_the_first_set_unknown(): void
    {
        $this->assertNull($this->properties('/(a)?\1b/')->first);
        $this->assertNotNull($this->properties('/(a)\1b/')->first);
    }

    #[Test]
    public function test_capture_count_and_regularity(): void
    {
        $this->assertSame(3, $this->properties('/(a)(?:(b)|(c))/')->captureCount);
        $this->assertSame(2, $this->properties('/(a)(?=(b))/')->captureCount);

        $this->assertTrue($this->properties('/(a|b)*?c+/')->regular);
        $this->assertFalse($this->properties('/a++/')->regular);
        $this->assertFalse($this->properties('/(?>a)/')->regular);
        $this->assertFalse($this->properties('/^a/')->regular);
        $this->assertFalse($this->properties('/(a)\1/')->regular);
    }

    #[Test]
    public function test_a_long_literal_is_cut(): void
    {
        $properties = $this->properties('/'.str_repeat('a', Properties::MAX_LITERAL + 10).'/');

        $this->assertSame(Properties::MAX_LITERAL + 10, $properties->minLength);
        $this->assertNull($properties->literal);
        $this->assertCount(Properties::MAX_LITERAL, $properties->prefix);
    }

    #[Test]
    public function test_huge_repeats_do_not_overflow(): void
    {
        $properties = $this->properties('/(?:(?:(?:(?:a{65535}){65535}){65535}){65535}){65535}/');

        $this->assertSame(\PHP_INT_MAX, $properties->minLength);
        $this->assertNull($properties->maxLength);
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideMatches')]
    public function test_the_properties_hold_every_match_php_reports(string $pattern, array $subjects): void
    {
        $properties = $this->properties($pattern);
        $unicode = str_contains(substr($pattern, (int) strrpos($pattern, '/')), 'u');
        $matched = 0;

        foreach ($subjects as $subject) {
            $this->assertNotFalse(preg_match_all($pattern, $subject, $matches));
            foreach ($matches[0] as $text) {
                $characters = Utf8::decode($text, $unicode);
                $this->assertNotNull($characters);
                $length = \count($characters);
                $context = \sprintf('%s on %s, match %s', $pattern, json_encode($subject), json_encode($text));
                $matched++;

                $this->assertGreaterThanOrEqual($properties->minLength, $length, $context);
                if (null !== $properties->maxLength) {
                    $this->assertLessThanOrEqual($properties->maxLength, $length, $context);
                }

                if (false === $properties->nullable) {
                    $this->assertGreaterThan(0, $length, $context);
                }

                if ($length > 0 && null !== $properties->first) {
                    $this->assertTrue($properties->first->contains($characters[0]), $context);
                }

                if ($length > 0 && null !== $properties->last) {
                    $this->assertTrue($properties->last->contains($characters[$length - 1]), $context);
                }

                $this->assertSame($properties->prefix, \array_slice($characters, 0, \count($properties->prefix)), $context);
                if ([] !== $properties->suffix) {
                    $this->assertSame($properties->suffix, \array_slice($characters, -\count($properties->suffix)), $context);
                }

                if (null !== $properties->literal) {
                    $this->assertSame($properties->literal, $characters, $context);
                }
            }
        }

        $this->assertGreaterThan(0, $matched, $pattern);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideMatches(): iterable
    {
        yield 'kelvin sign' => ['/k+/iu', ["\u{212A}k", 'K', 'xk']];
        yield 'long s' => ['/(?:s|t)+$/iu', ["a\u{17F}T", 'ss']];
        yield 'letters' => ['/\p{L}+\d/u', ['héllo9', '中文1']];
        yield 'word under u' => ['/\w+/u', ['été', 'naïve_1']];
        yield 'option in a branch' => ['/(?:x(?i)b|a)c/', ['Ac', 'xBc', 'ac']];
        yield 'newline sequence' => ['/a\Rb/', ["a\r\nb", "a\nb"]];
        yield 'accept' => ['/a(*ACCEPT)bc/', ['abc', 'a']];
        yield 'accept in a branch' => ['/(A(A|B(*ACCEPT)|C)D)(E)/', ['AB', 'AADE']];
        yield 'conditional' => ['/^(\()?blah(?(1)(\)))$/', ['blah', '(blah)']];
        yield 'backreference' => ['/(a|bc)\1/', ['aa', 'bcbc']];
        yield 'lazy' => ['/<.+?>/', ['<a><b>']];
        yield 'possessive' => ['/\d++[a-f]/', ['123a', '9f']];
        yield 'lookbehind' => ['/(?<=@)\w+/', ['me@host']];
        yield 'multiline' => ['/^\w+$/m', ["ab\ncd"]];
        yield 'dot under s' => ['/a.b/s', ["a\nb"]];
        yield 'caseless class' => ['/[a-c]+/i', ['xABCx']];
        yield 'ascii only fold without u' => ['/k/i', ['K']];
    }

    private function properties(string $regex): Properties
    {
        return (new HirTranslator())->translate(Regex::create(['cache' => null])->parse($regex))->properties;
    }

    /**
     * @return list<int>
     */
    private static function codePoints(string $text): array
    {
        return Utf8::decode($text, true) ?? [];
    }
}
