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
use PHPRegex\Tests\Support\PcreGates;
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
    use PcreGates;

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
        yield 'a line break in an optional group' => ['pattern' => '/(?:\n\R)?x/', 'possessives' => [], 'atomics' => [5], 'plain' => '/(?:\n(?:\r\n|[\n\x0B\f\r\x85]))?x/', 'subjects' => ["\n\r\nx", "\n\rx", "\n\nx", 'x', "\n\r\n\nx"], 'parting' => null];
        yield 'a run inside an atomic group' => ['pattern' => '/(?>a\d++)x/', 'possessives' => [4], 'atomics' => [], 'plain' => '/(?>a\d+)x/', 'subjects' => ['a12x', 'ax', 'a1x2', 'a1'], 'parting' => null];
        yield 'a run in a branch' => ['pattern' => '/(?:\d++x|y)/', 'possessives' => [3], 'atomics' => [], 'plain' => '/(?:\d+x|y)/', 'subjects' => ['12x', 'y', '1', 'x'], 'parting' => null];
        yield 'two runs side by side' => ['pattern' => '/a++\d++/', 'possessives' => [0, 3], 'atomics' => [], 'plain' => '/a+\d+/', 'subjects' => ['aa12', 'a', '1', 'aa1b'], 'parting' => null];
        yield 'branches of one length' => ['pattern' => '/(?>a|[ab])c/', 'possessives' => [], 'atomics' => [0], 'plain' => '/(?:a|[ab])c/', 'subjects' => ['ac', 'bc', 'abc', 'c'], 'parting' => null];
        yield 'branches that start apart' => ['pattern' => '/(?>ab|c)b/', 'possessives' => [], 'atomics' => [0], 'plain' => '/(?:ab|c)b/', 'subjects' => ['abb', 'cb', 'ab', 'cbb'], 'parting' => null];
        yield 'a caseless letter first' => ['pattern' => '/(?>(?i:a)|bc)d/', 'possessives' => [], 'atomics' => [0], 'plain' => '/(?:(?i:a)|bc)d/', 'subjects' => ['ad', 'Ad', 'bcd', 'bd'], 'parting' => null];
        yield 'a run before the subject end' => ['pattern' => '/a++\z/', 'possessives' => [0], 'atomics' => [], 'plain' => '/a+\z/', 'subjects' => ['aa', 'aab', 'ba'], 'parting' => null];
        yield 'a run before a match start reset' => ['pattern' => '/a++\Kb/', 'possessives' => [0], 'atomics' => [], 'plain' => '/a+\Kb/', 'subjects' => ['aab', 'ab', 'b', 'aa'], 'parting' => null];
        yield 'a run before a line end under m' => ['pattern' => '/[a\t\x0B]++$/m', 'possessives' => [0], 'atomics' => [], 'plain' => '/[a\t\x0B]+$/m', 'subjects' => ["a\t\n", "a\x0B\nb", "a\tb", 'aa'], 'parting' => null];
        yield 'a run before a group' => ['pattern' => '/a++(b)/', 'possessives' => [0], 'atomics' => [], 'plain' => '/a+(b)/', 'subjects' => ['aab', 'ab', 'aa'], 'parting' => null];
        yield 'a line break before its own group again' => ['pattern' => '/(?:\n\R)+x/', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/(?:\n(?:\r\n|[\n\x0B\f\r\x85]))+x/', 'subject' => "\n\r\n\rx"]];
        yield 'a line break closing a group' => ['pattern' => '/(x\R)\n/', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/(x(?:\r\n|[\n\x0B\f\r\x85]))\n/', 'subject' => "x\r\n"]];
        yield 'a caseless pair before a cased letter' => ['pattern' => '/(?>AB|a)(?-i)b/i', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/(?:AB|a)(?-i)b/i', 'subject' => 'ab']];
        yield 'a branch holding a lookahead' => ['pattern' => '/a++(?:b|(?=a))/', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/a+(?:b|(?=a))/', 'subject' => 'aa']];
        yield 'a run in a lookahead the match backtracks into' => ['pattern' => '/^(?*(a++))\1a$/', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/^(?*(a+))\1a$/', 'subject' => 'aa']];
        yield 'a run in a script run' => ['pattern' => '/^(*sr:a.++)/u', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/^(*sr:a.+)/u', 'subject' => 'aaα']];
        yield 'captures a conditional tells apart' => ['pattern' => '/^(?>(a)|(a))(?(2)b|c)$/', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/^(?:(a)|(a))(?(2)b|c)$/', 'subject' => 'ab']];
        yield 'captures a later conditional tells apart' => ['pattern' => '/^(?>(a)|(a))x(?(2)b|c)$/', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/^(?:(a)|(a))x(?(2)b|c)$/', 'subject' => 'axb']];
        yield 'a run a call runs again' => ['pattern' => '/^(?1)a(a++)b$/', 'possessives' => [], 'atomics' => [], 'plain' => null, 'subjects' => [], 'parting' => ['plain' => '/^(?1)a(a+)b$/', 'subject' => 'aaaab']];
        yield 'captures no conditional tests' => ['pattern' => '/(?>(a)|(b))c/', 'possessives' => [], 'atomics' => [0], 'plain' => '/(?:(a)|(b))c/', 'subjects' => ['ac', 'bc', 'abc', 'c'], 'parting' => null];
        yield 'a run after a backreference' => ['pattern' => '/(x)\1a++b/', 'possessives' => [5], 'atomics' => [], 'plain' => '/(x)\1a+b/', 'subjects' => ['xxaab', 'xxab', 'xab', 'xxb'], 'parting' => null];
        yield 'branches holding no capture before a conditional' => ['pattern' => '/(a)?(?>b|[bc])(?(1)d|e)/', 'possessives' => [], 'atomics' => [4], 'plain' => '/(a)?(?:b|[bc])(?(1)d|e)/', 'subjects' => ['abd', 'be', 'ce', 'acd', 'bd', 'abe'], 'parting' => null];
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
     * A run of runs, "(?:a+)++", reads the same matches as its plain
     * spelling before a follower it shares nothing with, and the solver
     * reads it so. A printer leaves it committed: written plain, a
     * backtracking engine tries every split of the run before it rejects a
     * subject, "aaaa…a!" against "^(?:a+)+b$".
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideRunsOfRuns')]
    public function test_leaves_a_run_of_runs_committed_for_a_printer(string $pattern, string $plain, array $subjects): void
    {
        foreach ($subjects as $subject) {
            $this->assertSame(self::span($pattern, $subject), self::span($plain, $subject), $plain.' on '.json_encode($subject));
        }

        $this->assertTrue(self::inert($pattern, false)->isEmpty(), $pattern.' searched');
        $this->assertFalse(self::inert($pattern, true)->isEmpty(), $pattern.' over a whole subject');
    }

    /**
     * @return iterable<string, array{pattern: string, plain: string, subjects: list<string>}>
     */
    public static function provideRunsOfRuns(): iterable
    {
        yield 'a possessive run of a class' => ['pattern' => '/(?:[ab]+)++c/', 'plain' => '/(?:[ab]+)+c/', 'subjects' => ['abc', 'c', 'abab', 'bac']];
        yield 'an atomic run of a letter' => ['pattern' => '/(?>(?:a+)+)b/', 'plain' => '/(?:(?:a+)+)b/', 'subjects' => ['aab', 'b', 'aaa!', 'ab']];
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
     * "\Z" and "$" stand before the newline the pattern's convention names.
     * Under "(*CRLF)" a run that takes the "\r" of a final "\r\n" leaves a
     * "\n" no line ends before, so a line end proves nothing past a run;
     * a pattern without one keeps its proof under any convention.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideNewlineConventions')]
    public function test_reads_line_ends_under_the_patterns_newline_convention(string $pattern, string $plain, array $subjects, bool $inert): void
    {
        $parting = array_filter($subjects, static fn (string $subject): bool => self::span($pattern, $subject) !== self::span($plain, $subject));
        $this->assertSame($inert, [] === $parting, $pattern.' against '.$plain);

        $this->assertSame(!$inert, InertCommitments::of(RegexParser::create()->parse($pattern))->isEmpty(), $pattern);

        $hir = (new HirTranslator())->translate(RegexParser::create()->parse($pattern));
        $lineFeed = !str_contains($pattern, '(*CRLF)');
        $this->assertSame(!$inert, (new AtomicInertiaAnalyzer(wholeSubject: false, lineFeedNewline: $lineFeed))->inertCommitments($hir)->isEmpty(), $pattern.' read by the analyzer');
    }

    /**
     * @return iterable<string, array{pattern: string, plain: string, subjects: list<string>, inert: bool}>
     */
    public static function provideNewlineConventions(): iterable
    {
        yield 'a final carriage return and line feed' => ['pattern' => '/(*CRLF)^[a\r]++\Z/', 'plain' => '/(*CRLF)^[a\r]+\Z/', 'subjects' => ["a\r\n", 'a', "a\r", "\r\n"], 'inert' => false];
        yield 'a line end under m' => ['pattern' => '/(*CRLF)^[a\r]++$/m', 'plain' => '/(*CRLF)^[a\r]+$/m', 'subjects' => ["a\r\nb", "a\nb", 'a'], 'inert' => false];
        yield 'the line feed named' => ['pattern' => '/(*LF)^[a\r]++\Z/', 'plain' => '/(*LF)^[a\r]+\Z/', 'subjects' => ["a\r\n", "a\r", 'a', "a\n"], 'inert' => true];
        yield 'no line end' => ['pattern' => '/(*CRLF)a++b/', 'plain' => '/(*CRLF)a+b/', 'subjects' => ['aab', 'ab', 'b'], 'inert' => true];
    }

    /**
     * A lookbehind's body must end where the lookbehind stands. The
     * interpreter lets a commitment run past that place and fail there,
     * where its plain spelling stops in time: "(?<=\R)" does not hold
     * between "\r" and "\n". The JIT reads the subject as ending there and
     * holds it. A commitment the body ends with is proven only when it
     * cannot run on, whatever stands after the lookbehind.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideLookbehindEnds')]
    public function test_reads_a_lookbehind_as_ending_before_any_character(string $pattern, string $plain, array $subjects, bool $inert): void
    {
        $this->requiresPcre2('10.43', 'A lookbehind branch of more than one length');

        $parting = array_filter($subjects, static fn (string $subject): bool => self::span(self::interpreted($pattern), $subject) !== self::span(self::interpreted($plain), $subject));
        $this->assertSame($inert, [] === $parting, $pattern.' against '.$plain.' under the interpreter');

        $this->assertSame(!$inert, self::inert($pattern, false)->isEmpty(), $pattern.' searched');
        $this->assertSame(!$inert, self::inert($pattern, true)->isEmpty(), $pattern.' over a whole subject');
    }

    /**
     * @return iterable<string, array{pattern: string, plain: string, subjects: list<string>, inert: bool}>
     */
    public static function provideLookbehindEnds(): iterable
    {
        yield 'a line break' => ['pattern' => '/(?<=\R)/', 'plain' => '/(?<=(?:\r\n|[\n\x0B\f\r\x85]))/', 'subjects' => ["\r\n", "a\r\nb", "\n"], 'inert' => false];
        yield 'a bounded run' => ['pattern' => '/(?<=a{1,2}+)/', 'plain' => '/(?<=a{1,2})/', 'subjects' => ['aa', 'a', 'ba'], 'inert' => false];
        yield 'a longer branch first' => ['pattern' => '/(?<=(?>ab|a))/', 'plain' => '/(?<=(?:ab|a))/', 'subjects' => ['ab', 'a'], 'inert' => false];
        yield 'a line break after a letter' => ['pattern' => '/(?<=x\R)/', 'plain' => '/(?<=x(?:\r\n|[\n\x0B\f\r\x85]))/', 'subjects' => ["x\r\n", "x\n"], 'inert' => false];
        yield 'a negative lookbehind' => ['pattern' => '/\r(?<!\R)/', 'plain' => '/\r(?<!(?:\r\n|[\n\x0B\f\r\x85]))/', 'subjects' => ["\r\n", "\r"], 'inert' => false];
        yield 'a line break before a letter' => ['pattern' => '/(?<=\Rx)/', 'plain' => '/(?<=(?:\r\n|[\n\x0B\f\r\x85])x)/', 'subjects' => ["\r\nx", "\nx", "\rx", "\r\rx", 'x'], 'inert' => true];
        yield 'a fixed atomic body' => ['pattern' => '/(?<=(?>ab))/', 'plain' => '/(?<=(?:ab))/', 'subjects' => ['ab', 'abab', 'a'], 'inert' => true];
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
     * The pattern read by the interpreter, whatever pcre.jit says.
     */
    private static function interpreted(string $pattern): string
    {
        return $pattern[0].'(*NO_JIT)'.substr($pattern, 1);
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
