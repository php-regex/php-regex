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

namespace PHPRegex\Tests\Unit\Parser\Analysis;

use PHPRegex\Parser\Analysis\AtomicInertiaAnalyzer;
use PHPRegex\Parser\Analysis\InertCommitments;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ConcatHir;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which possessive quantifiers, atomic groups and "\R" commit to nothing,
 * by the offset each starts at in the pattern body. Each row is read by
 * PCRE first: written plain, the commitments proven find the matches of
 * the pattern on every subject given, and a commitment refused, written
 * plain, finds another match on the subject given, unless the refusal is
 * only the rule's limit.
 */
final class AtomicInertiaAnalyzerTest extends TestCase
{
    /**
     * @param list<int>                                  $possessives
     * @param list<int>                                  $atomics
     * @param list<string>                               $subjects
     * @param array{plain: string, subject: string}|null $parting
     */
    #[Test]
    #[DataProvider('provideSearchVerdicts')]
    public function test_finds_the_commitments_a_search_may_print_plain(string $pattern, array $possessives, array $atomics, ?string $plain, array $subjects, ?array $parting): void
    {
        if (null !== $plain) {
            foreach ($subjects as $subject) {
                $this->assertSame(self::span($pattern, $subject), self::span($plain, $subject), $plain.' on '.json_encode($subject));
            }
        }

        if (null !== $parting) {
            $this->assertNotSame(self::span($pattern, $parting['subject']), self::span($parting['plain'], $parting['subject']), $parting['plain'].' on '.json_encode($parting['subject']));
        }

        $inert = self::inert($pattern, false);

        foreach ($possessives as $offset) {
            $this->assertTrue($inert->isInertPossessive($offset), $pattern.' at '.$offset);
        }

        foreach ($atomics as $offset) {
            $this->assertTrue($inert->isInertAtomic($offset), $pattern.' at '.$offset);
        }

        $this->assertSame([] === $possessives && [] === $atomics, $inert->isEmpty(), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, possessives: list<int>, atomics: list<int>, plain: string|null, subjects: list<string>, parting: array{plain: string, subject: string}|null}>
     */
    public static function provideSearchVerdicts(): iterable
    {
        yield 'a possessive run to the end' => ['pattern' => '/^\d++$/', 'possessives' => [1], 'atomics' => [], 'plain' => '/^\d+$/', 'subjects' => ['123', "12\n", '12a', ''], 'parting' => null];
        yield 'an atomic run before a dash' => ['pattern' => '/^(?>\d+)-x$/', 'possessives' => [], 'atomics' => [1], 'plain' => '/^(?:\d+)-x$/', 'subjects' => ['12-x', '1-x', '12x', '-x'], 'parting' => null];
        yield 'a line break before a letter' => ['pattern' => '/a\Rb/', 'possessives' => [], 'atomics' => [1], 'plain' => '/a(?:\r\n|[\n\x0B\f\r\x85])b/', 'subjects' => ["a\r\nb", "a\nb", "a\rb", "a\r\rb", 'ab'], 'parting' => null];
        yield 'a fixed atomic body' => ['pattern' => '/(?>abc)c/', 'possessives' => [], 'atomics' => [0], 'plain' => '/(?:abc)c/', 'subjects' => ['abcc', 'abc', 'xabcc'], 'parting' => null];
        yield 'a longer branch first' => ['pattern' => '/(?>ab|a)c/', 'possessives' => [], 'atomics' => [0], 'plain' => '/(?:ab|a)c/', 'subjects' => ['abc', 'ac', 'abac', 'ab'], 'parting' => null];
        yield 'a run at the end of a lookahead' => ['pattern' => '/(?=a++)a/', 'possessives' => [3], 'atomics' => [], 'plain' => '/(?=a+)a/', 'subjects' => ['a', 'aa', 'b'], 'parting' => null];
        yield 'a shorter branch first' => ['pattern' => '/(?>a|ab)c/', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/(?:a|ab)c/', 'subject' => 'abc']];
        yield 'a run the follower shares' => ['pattern' => '/a*+a/', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/a*a/', 'subject' => 'aa']];
        yield 'a newline after a line break' => ['pattern' => "/\\R\n/", 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/(?:\r\n|[\n\x0B\f\r\x85])\n/', 'subject' => "\r\n"]];
        yield 'a lazy atomic run' => ['pattern' => '/(?>a+?)b/', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/(?:a+?)b/', 'subject' => 'aab']];
        yield 'a lazy run repeated in an atomic group' => ['pattern' => '/(?>(?:a+?){2})b/', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/(?:(?:a+?){2})b/', 'subject' => 'aaab']];
        yield 'a lazy run repeated possessively' => ['pattern' => '/(?:a+?){2}+b/', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/(?:a+?){2}b/', 'subject' => 'aaab']];
        yield 'a lookahead after a run' => ['pattern' => '/a++(?=a)/', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/a+(?=a)/', 'subject' => 'aa']];
        yield 'a word boundary after a run' => ['pattern' => '/(?>a+)\B/', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/(?:a+)\B/', 'subject' => 'aa']];
        yield 'a line end under m' => ['pattern' => '/\s++$/m', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/\s+$/m', 'subject' => " \n x"]];
        // The first run commits to nothing either, but its proof would read
        // past the second one: the rule's limit, with no subject to part on.
        yield 'a commitment further along' => ['pattern' => '/a++b\d++c/', 'possessives' => [4], 'atomics' => [], 'plain' => '/a++b\d+c/', 'subjects' => ['ab12c', 'aab1c', 'abc', 'ab1'], 'parting' => null];
    }

    /**
     * Each proof reads what follows its own commitment, and the proofs do
     * not compose: a commitment with another one further along its
     * sequence than the part right after it is not proven.
     */
    #[Test]
    public function test_leaves_a_commitment_with_another_further_along(): void
    {
        $inert = self::inert('/a++b\d++c/', false);

        $this->assertFalse($inert->isInertPossessive(0));
        $this->assertTrue($inert->isInertPossessive(4));
    }

    /**
     * Over a whole subject, a zero-width follower refuses a match or lets
     * it be, but the characters given back must still be taken by a
     * follower that starts otherwise: it is skipped.
     */
    #[Test]
    public function test_skips_a_zero_width_follower_over_a_whole_subject(): void
    {
        foreach (['', 'a', 'aa', 'ab', 'ba'] as $subject) {
            $this->assertSame(preg_match('/^(?:a+(?=a))\z/', $subject), preg_match('/^(?:a++(?=a))\z/', $subject), json_encode($subject).' whole');
            $this->assertSame(preg_match('/^(?:a+\B)\z/', $subject), preg_match('/^(?:(?>a+)\B)\z/', $subject), json_encode($subject).' whole');
        }

        $this->assertNotSame(self::span('/a+(?=a)/', 'aa'), self::span('/a++(?=a)/', 'aa'));

        $this->assertTrue(self::inert('/a++(?=a)/', true)->isInertPossessive(0));
        $this->assertTrue(self::inert('/(?>a+)\B/', true)->isInertAtomic(0));
        $this->assertFalse(self::inert('/a++(?=a)/', false)->isInertPossessive(0));
    }

    /**
     * What follows each part of a sequence is worked out in one pass from
     * its end: the first characters of the next part, through the parts
     * that may be skipped, then what follows the sequence. Under a search,
     * a lookahead proves nothing, and nothing before it is known.
     */
    #[Test]
    public function test_works_out_what_follows_each_part_of_a_sequence(): void
    {
        $parts = self::parts('/ab?c*d/');
        $beyond = CharSet::single(0x78);

        $this->assertSame(
            [self::key('bcd'), self::key('cd'), self::key('d'), self::key('x')],
            array_map(static fn (?CharSet $set): ?string => $set?->key(), (new AtomicInertiaAnalyzer())->followSets($parts, $beyond)),
        );
        $this->assertSame([null, null, null, null], (new AtomicInertiaAnalyzer())->followSets($parts, null));

        $this->assertSame(
            [null, self::key('b'), CharSet::empty()->key(), CharSet::empty()->key()],
            array_map(static fn (?CharSet $set): ?string => $set?->key(), (new AtomicInertiaAnalyzer(wholeSubject: false))->followSets(self::parts('/a(?=b)b$/'), CharSet::empty())),
        );
    }

    /**
     * Whether a commitment occurs at each part of a sequence or further
     * along, worked out in one pass from its end.
     */
    #[Test]
    public function test_finds_where_a_commitment_is_still_ahead(): void
    {
        $analyzer = new AtomicInertiaAnalyzer();

        $this->assertSame([true, true, true, false], $analyzer->commitmentsFrom(self::parts('/a++b(?>c)d/')));
        $this->assertSame([false, false, false, false], $analyzer->commitmentsFrom(self::parts('/ab?c*d/')));
    }

    /**
     * @return list<Hir>
     */
    private static function parts(string $pattern): array
    {
        $hir = (new HirTranslator())->translate(RegexParser::create()->parse($pattern));
        self::assertInstanceOf(ConcatHir::class, $hir);

        return $hir->parts;
    }

    private static function key(string $characters): string
    {
        $set = CharSet::empty();
        foreach (mb_str_split($characters) as $character) {
            $set = $set->union(CharSet::single(mb_ord($character)));
        }

        return $set->key();
    }

    private static function inert(string $pattern, bool $wholeSubject): InertCommitments
    {
        $hir = (new HirTranslator())->translate(RegexParser::create()->parse($pattern));

        return (new AtomicInertiaAnalyzer($wholeSubject))->inertCommitments($hir);
    }

    /**
     * Where preg_match() finds the first match, as offsets into the
     * subject, or null.
     *
     * @return array{int, int}|null
     */
    private static function span(string $pattern, string $subject): ?array
    {
        $result = preg_match($pattern, $subject, $match, \PREG_OFFSET_CAPTURE);
        self::assertNotFalse($result, $pattern);

        return 1 === $result ? [$match[0][1], $match[0][1] + \strlen($match[0][0])] : null;
    }
}
