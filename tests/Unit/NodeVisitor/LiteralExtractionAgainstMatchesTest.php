<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\LiteralSet;
use RegexParser\Regex;

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

        // More variants than the set keeps: the set cannot claim to list them.
        yield 'counted class' => ['pattern' => '/^[abc]{12}/', 'subject' => 'cccccccccccc'];
        yield 'caseless words' => ['pattern' => '/(?i:saturday|sunday)/', 'subject' => 'SATURDAY'];

        // Caseless matching folds what the ASCII table does not know.
        yield 'caseless non-ASCII letter' => ['pattern' => '/ⱥ/iu', 'subject' => 'Ⱥ'];

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
