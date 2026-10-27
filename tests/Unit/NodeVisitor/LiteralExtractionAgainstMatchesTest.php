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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\Analysis\LiteralSet;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every match PHP finds must start with one of the extracted prefixes and
 * end with one of the suffixes, and be one of them when the set says it is
 * complete; an empty list says nothing. The cases come from the PCRE2 test
 * suite (testinput1, 2 and 4), where the extraction claimed too much.
 */
final class LiteralExtractionAgainstMatchesTest extends TestCase
{
    #[Test]
    #[DataProvider('provideMatches')]
    public function test_the_literals_hold_what_php_matches(string $pattern, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject, $matches), $pattern);
        $match = $matches[0];

        $set = Regex::create(['cache' => null])->literals($pattern)->literalSet;

        $this->assertTrue(self::startsWithOne($match, $set->prefixes), \sprintf('%s: "%s" starts with none of %s', $pattern, $match, json_encode($set->prefixes)));
        $this->assertTrue(self::endsWithOne($match, $set->suffixes), \sprintf('%s: "%s" ends with none of %s', $pattern, $match, json_encode($set->suffixes)));
        if ($set->complete) {
            $this->assertContains($match, $set->prefixes, $pattern);
        }
    }

    /**
     * A subject the pattern matches holds one of the search patterns: they
     * prefilter the subject, before preg_match() runs.
     */
    #[Test]
    #[DataProvider('provideMatches')]
    #[DataProvider('provideSubjectsAroundTheMatch')]
    public function test_a_subject_the_pattern_matches_holds_one_of_the_search_patterns(string $pattern, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);

        $patterns = Regex::create(['cache' => null])->literals($pattern)->patterns;
        if ([] === $patterns) {
            return;
        }

        $held = array_filter($patterns, static fn (string $fragment): bool => 1 === preg_match('/'.$fragment.'/', $subject));
        $this->assertNotSame([], $held, \sprintf('%s matches "%s", which holds none of %s', $pattern, addcslashes($subject, "\n"), json_encode($patterns)));
    }

    /**
     * Where the match sits in the subject, and the flags that move its
     * ends: the engine's answers on PHP 8.4.27 (PCRE2 10.49).
     *
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideSubjectsAroundTheMatch(): iterable
    {
        yield 'a match inside the subject' => ['pattern' => '/user-\\d{4}/', 'subject' => 'id=user-1234'];
        yield 'a line start under m' => ['pattern' => '/^foo/m', 'subject' => "bar\nfoo"];
        yield 'a line end under m' => ['pattern' => '/foo$/m', 'subject' => "foo\nbar"];
        yield 'an end before a final newline' => ['pattern' => '/foo$/', 'subject' => "xfoo\n"];
        yield 'an end pinned by \\z' => ['pattern' => '/bar\\z/', 'subject' => 'xxbar'];
        yield 'a match where the search starts' => ['pattern' => '/\\Gfoo/', 'subject' => 'foox'];
        yield 'a branch of two' => ['pattern' => '/abc|xyz/', 'subject' => '--xyz--'];
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideMatches(): iterable
    {
        // A lookaround does not consume what it looks at.
        yield 'lookahead' => ['pattern' => '/^(?=ab(de))(abd)(e)/', 'subject' => 'abde'];
        yield 'negative lookahead' => ['pattern' => '/foo(?!bar)(.*)/', 'subject' => 'foolish see?'];
        yield 'negative lookahead with branches' => ['pattern' => '/^(?!(ab)de|x)(abd)(f)/', 'subject' => 'abdf'];
        yield 'trailing lookahead' => ['pattern' => '/\w+(?=\t)/', 'subject' => "brown\tfox"];
        yield 'lookbehind' => ['pattern' => '/(?<=(foo))bar\1/', 'subject' => 'foobarfoo'];

        // A branch about which nothing is known makes the union unknown.
        yield 'branch with no literal' => ['pattern' => '/(?:b)|(?::+)/', 'subject' => '::'];
        yield 'branch that may be empty' => ['pattern' => '/^(ba|b*){1,2}?bc/', 'subject' => 'bbabc'];
        yield 'branch with a repeated tail' => ['pattern' => '/(ab|ab*)bc/', 'subject' => 'abc'];
        yield 'last branch with no literal' => ['pattern' => '/^(?:aaa(*THEN)\w{6}|bbb(*THEN)\w{5}|ccc(*THEN)\w{4}|\w{3})/', 'subject' => 'ddd'];
        yield 'recursion in branches' => ['pattern' => '/^(\d+|\((?1)([+*-])(?1)\)|-(?1))$/', 'subject' => '12'];

        // "(*ACCEPT)" ends the match where it stands.
        yield 'accept' => ['pattern' => '/(A(A|B(*ACCEPT)|C)D)(E)/', 'subject' => 'AB'];
        yield 'accept in a script run' => ['pattern' => '/(*sr:a(*ACCEPT))b/', 'subject' => 'a'];
        yield 'accept in an atomic script run' => ['pattern' => '/(*asr:a(*ACCEPT))b/', 'subject' => 'a'];
        yield 'accept in a conditional in a script run' => ['pattern' => '/(*sr:(a)?(?(1)(*ACCEPT)|c))b/', 'subject' => 'a'];

        // More variants than the set keeps: the set cannot claim to list them.
        yield 'counted class' => ['pattern' => '/^[abc]{12}/', 'subject' => 'cccccccccccc'];
        yield 'caseless words' => ['pattern' => '/(?i:saturday|sunday)/', 'subject' => 'SATURDAY'];

        // Caseless matching folds what the ASCII table does not know.
        yield 'caseless non-ASCII letter' => ['pattern' => '/ⱥ/iu', 'subject' => 'Ⱥ'];

        // In UTF mode, "k" also matches the Kelvin sign and "s" the long s.
        yield 'caseless k in UTF mode' => ['pattern' => '/AskZ/iu', 'subject' => "As\u{212A}Z"];
        yield 'caseless s in UTF mode' => ['pattern' => '/sss/iu', 'subject' => "s\u{17F}S"];
        yield 'caseless class holding s in UTF mode' => ['pattern' => '/[RST]+/iu', 'subject' => "Ss\u{17F}"];

        // An option setting holds to the end of the group it stands in.
        yield 'caseless from the middle' => ['pattern' => '/a(?i)b/', 'subject' => 'aB'];
        yield 'caseless reset by a caret' => ['pattern' => '/(?i)a(?^)b/', 'subject' => 'Ab'];
        yield 'caseless turned off by a group with more options' => ['pattern' => '/(?i)a(?s-mi)b/', 'subject' => 'Ab'];
    }

    #[Test]
    public function test_a_define_group_matches_nothing_in_place(): void
    {
        $this->assertSame(1, preg_match('/(?(DEFINE)(?<n>a))foo(?&n)/', 'fooa'));

        $set = Regex::create(['cache' => null])->literals('/(?(DEFINE)(?<n>a))foo(?&n)/')->literalSet;

        $this->assertSame(['foo'], $set->prefixes);
    }

    #[Test]
    public function test_a_truncated_cross_product_keeps_only_sound_prefixes(): void
    {
        $left = new LiteralSet(array_map(static fn (int $i): string => 'p'.$i, range(1, 20)), [], true);
        $right = new LiteralSet(array_map(static fn (int $i): string => 's'.$i, range(1, 20)), array_map(static fn (int $i): string => 's'.$i, range(1, 20)), true);

        $product = $left->concat($right);

        // 400 combinations do not fit: every match still starts with a
        // prefix of the left side, and the set is not complete.
        $this->assertFalse($product->complete);
        $this->assertContains('p20', $product->prefixes);
        $this->assertContains('s20', $product->suffixes);
    }

    /**
     * @param array<string> $prefixes
     */
    private static function startsWithOne(string $match, array $prefixes): bool
    {
        return [] === $prefixes || [] !== array_filter($prefixes, static fn (string $p): bool => str_starts_with($match, $p));
    }

    /**
     * @param array<string> $suffixes
     */
    private static function endsWithOne(string $match, array $suffixes): bool
    {
        return [] === $suffixes || [] !== array_filter($suffixes, static fn (string $s): bool => str_ends_with($match, $s));
    }
}
