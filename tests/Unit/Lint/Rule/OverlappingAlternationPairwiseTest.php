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
use PHPRegex\Linter\Rule\OverlappingAlternationRule;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The overlap reported for an alternation is the one a comparison of every
 * pair of its branches meets first: a pair of literal branches before any
 * other, then the first pair in the order of the alternation. The
 * comparison is made here two branches at a time, each pair linted as an
 * alternation of its own, and the alternation as a whole has to give the
 * finding of the first pair that has one.
 *
 * The branches are ones a pair reads as the whole alternation does: no
 * flag set in a branch carries into the next, and where the first
 * characters are asked of PCRE, for a branch starting with a class above
 * ASCII, a last branch that overlaps nothing makes each pair ask as well.
 */
final class OverlappingAlternationPairwiseTest extends TestCase
{
    /**
     * The findings of each pattern the test has linted: a pair comes back
     * in many alternations of one test.
     *
     * @var array<string, list<string>>
     */
    private array $findings = [];

    /**
     * @param list<string> $branches
     */
    #[Test]
    #[DataProvider('provideAlternations')]
    public function test_the_finding_is_the_one_of_the_first_overlapping_pair(array $branches, string $flags, ?string $expected): void
    {
        $pairwise = $this->pairwise($branches, $flags);

        $this->assertSame(null === $expected ? [] : [$expected], $pairwise);
        $this->assertSame($pairwise, $this->findings(self::alternation($branches, $flags)));
    }

    /**
     * @return iterable<string, array{branches: list<string>, flags: string, expected: ?string}>
     */
    public static function provideAlternations(): iterable
    {
        $literal = static fn (string $one, string $other): string => \sprintf('%s: Alternation branches "%s" and "%s" overlap.', OverlappingAlternationRule::ID_OVERLAP, $one, $other);
        $charset = static fn (string $one, string $other): string => \sprintf('%s: Alternation branches "%s" and "%s" have overlapping character sets, which may cause unnecessary backtracking.', OverlappingAlternationRule::ID_CHARSET, $one, $other);

        yield 'equal branches' => ['branches' => ['ab', 'ab'], 'flags' => '', 'expected' => $charset('ab', 'ab')];
        yield 'equal branches around another' => ['branches' => ['ab', 'cd', 'ab'], 'flags' => '', 'expected' => $charset('ab', 'ab')];
        yield 'equal branches before one they start' => ['branches' => ['ab', 'ab', 'abc'], 'flags' => '', 'expected' => $literal('ab', 'abc')];
        yield 'a branch that starts the next' => ['branches' => ['foo', 'foobar'], 'flags' => '', 'expected' => $literal('foo', 'foobar')];
        yield 'a branch started by the next' => ['branches' => ['foobar', 'foo'], 'flags' => '', 'expected' => $literal('foobar', 'foo')];
        yield 'branches differing in their last character' => ['branches' => ['abc1', 'abc2', 'abc3'], 'flags' => '', 'expected' => null];
        yield 'a branch that starts two others' => ['branches' => ['ab1', 'abc', 'ab'], 'flags' => '', 'expected' => $literal('ab1', 'ab')];
        yield 'the pair of the first branch, not the nearest texts' => ['branches' => ['ac', 'abc', 'ab', 'a'], 'flags' => '', 'expected' => $literal('ac', 'a')];
        yield 'a pair after branches that overlap nothing' => ['branches' => ['x', 'y', 'ab', 'z', 'abc'], 'flags' => '', 'expected' => $literal('ab', 'abc')];
        yield 'two cases, read as written' => ['branches' => ['Ab', 'ab', 'aB'], 'flags' => '', 'expected' => null];
        yield 'two cases under i' => ['branches' => ['cd', 'Ab', 'ab'], 'flags' => 'i', 'expected' => $literal('Ab', 'ab')];
        yield 'a prefix in another case under i' => ['branches' => ['ABC', 'x', 'ab'], 'flags' => 'i', 'expected' => $literal('ABC', 'ab')];
        yield 'one branch caseless' => ['branches' => ['AB', '(?i:ab)', 'ab'], 'flags' => '', 'expected' => $literal('AB', 'ab')];
        yield 'classes that meet' => ['branches' => ['[ab]x', '[cd]y', '[bc]z'], 'flags' => '', 'expected' => $charset('[ab]x', '[bc]z')];
        yield 'classes apart' => ['branches' => ['[ab]x', '[cd]y', '[ef]z'], 'flags' => '', 'expected' => null];
        yield 'a class and a text' => ['branches' => ['ab', '[ab]x'], 'flags' => '', 'expected' => $charset('ab', '[ab]x')];
        yield 'a literal pair after a pair of classes' => ['branches' => ['[ab]x', '[bc]y', 'a', 'ab'], 'flags' => '', 'expected' => $literal('a', 'ab')];
        yield 'a type and a class' => ['branches' => ['\d+', 'x', '[0-4]a'], 'flags' => '', 'expected' => $charset('\\d+', '[0-4]a')];
        yield 'a quantified letter and a text' => ['branches' => ['a+', 'b', 'ab'], 'flags' => '', 'expected' => $charset('a+', 'ab')];
        yield 'a quantified group and a text' => ['branches' => ['(?:ab)+', 'abc'], 'flags' => '', 'expected' => $charset('(?:ab)+', 'abc')];
        yield 'an optional letter before a text' => ['branches' => ['a?bc', 'bd', 'ad'], 'flags' => '', 'expected' => $charset('a?bc', 'bd')];
        yield 'an empty branch' => ['branches' => ['', 'ab', 'abc'], 'flags' => '', 'expected' => $literal('ab', 'abc')];
        yield 'an empty branch between texts told apart' => ['branches' => ['ab', '', 'ac'], 'flags' => '', 'expected' => null];
        yield 'texts read through groups' => ['branches' => ['(ab)c', 'x', 'abc'], 'flags' => '', 'expected' => $charset('(ab)c', 'abc')];
        yield 'a text followed by a group' => ['branches' => ['callable-(?:string|object)', 'class-string', 'callable'], 'flags' => '', 'expected' => $charset('callable-(?:string|object)', 'callable')];
        yield 'bytes above ASCII' => ['branches' => ["\xE9a", "\xE8", "\xE9"], 'flags' => '', 'expected' => $literal('\xE9a', '\xE9')];
        yield 'a byte class above ASCII beside texts' => ['branches' => ['[\xC0-\xFF]a', 'ab', 'ac'], 'flags' => '', 'expected' => null];
        yield 'texts above ASCII under u' => ['branches' => ['été', 'école', 'étés'], 'flags' => 'u', 'expected' => $literal('été', 'étés')];
        yield 'texts above ASCII told apart under u' => ['branches' => ['été', 'école', 'étable'], 'flags' => 'u', 'expected' => null];
        yield 'two cases above ASCII under iu' => ['branches' => ['èa', 'Éa', 'éab'], 'flags' => 'iu', 'expected' => $literal('Éa', 'éab')];
        yield 'a class and a text above ASCII under u' => ['branches' => ['éa\d', 'èb', '[éè]c'], 'flags' => 'u', 'expected' => $charset('éa\\d', '[éè]c')];
        yield 'texts above ASCII before a digit under u' => ['branches' => ['éa\d', 'éb\d', 'éab\d'], 'flags' => 'u', 'expected' => $charset('éa\\d', 'éab\\d')];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideAlternationsReadAsAWhole')]
    public function test_the_finding_stays_where_a_pair_alone_is_read_otherwise(string $pattern, array $expected): void
    {
        $this->assertSame($expected, $this->findings($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, expected: list<string>}>
     */
    public static function provideAlternationsReadAsAWhole(): iterable
    {
        $charset = static fn (string $one, string $other): string => \sprintf('%s: Alternation branches "%s" and "%s" have overlapping character sets, which may cause unnecessary backtracking.', OverlappingAlternationRule::ID_CHARSET, $one, $other);

        yield 'a bare (?i) carried into the next branches' => [
            'pattern' => '/(?:(?i)ab|x|AB)+/',
            'expected' => [OverlappingAlternationRule::ID_OVERLAP.': Alternation branches "ab" and "AB" overlap.'],
        ];
        // The byte folds as the target's tables say, asked of PCRE:
        // preg_match("/\xE9/i", "c") is 0 in the C locale.
        yield 'a byte above ASCII read caselessly, folded by PCRE' => [
            'pattern' => "/(?:\xE9+|c|b+)+/i",
            'expected' => [],
        ];
        yield 'a class above ASCII that makes PCRE give every set' => [
            'pattern' => '/(?:[éè]a|x|é)+/u',
            'expected' => [$charset('[éè]a', 'é')],
        ];
        yield 'properties, one holding the other' => [
            'pattern' => '/(?:\p{Lu}|éa|\p{L})+/u',
            'expected' => [$charset('\\p{Lu}', '\\p{L}')],
        ];
        yield 'a branch PCRE is not asked about, which leaves the others unjudged' => [
            'pattern' => '/(?:\p{Lu}|é?a|\p{L})+/u',
            'expected' => [],
        ];
        yield 'no quantifier around the alternation' => ['pattern' => '/(?:a|ab|[ab]c)/', 'expected' => []];
        yield 'a possessive quantifier around the alternation' => ['pattern' => '/(?:a|ab|[ab]c)++/', 'expected' => []];
    }

    /**
     * @param list<string> $atoms the branches an alternation draws from
     */
    #[Test]
    #[DataProvider('provideGeneratedFamilies')]
    public function test_generated_alternations_give_the_finding_of_the_first_overlapping_pair(array $atoms, string $flags, ?string $last, int $seed): void
    {
        $state = $seed;
        $next = static function (int $below) use (&$state): int {
            $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;

            return ($state >> 8) % $below;
        };

        $reported = 0;
        for ($case = 0; $case < 150; $case++) {
            // Half of the alternations start every branch alike.
            $start = 0 === $next(2) ? $atoms[0].$atoms[1] : '';
            $branches = [];
            for ($count = 2 + $next(5); $count > 0; $count--) {
                $branches[] = $start.$atoms[$next(\count($atoms))];
            }

            $pairwise = $this->pairwise($branches, $flags, $last);
            $reported += \count($pairwise);
            $pattern = self::alternation([...$branches, ...(array) $last], $flags);
            $this->assertSame($pairwise, $this->findings($pattern), $pattern);
        }

        // The family holds both answers in number.
        $this->assertGreaterThan(30, $reported);
        $this->assertLessThan(140, $reported);
    }

    /**
     * @return iterable<string, array{atoms: list<string>, flags: string, last: ?string, seed: int}>
     */
    public static function provideGeneratedFamilies(): iterable
    {
        $ascii = ['a', 'b', 'ab', 'ba', 'abc', 'abd', 'bab', 'A', 'aB', 'c1', 'c2', 'c12', '[ab]x', '[cd]', '\d', 'a+', 'b?c', '(?:ab)c', '(ba)', '', 'd', 'e', 'f', 'g', 'h', 'da', 'eb', 'fc'];
        yield 'bytes' => ['atoms' => $ascii, 'flags' => '', 'last' => null, 'seed' => 1];
        yield 'bytes under i' => ['atoms' => $ascii, 'flags' => 'i', 'last' => null, 'seed' => 2];

        $texts = ['é', 'è', 'éa', 'èa', 'éab', 'éac', 'É', 'éA', 'ê1', 'ê2', 'ê12', 'ï', 'î', 'ô', 'ö', 'û', 'ïa', 'îb', 'ôc', 'σ', 'ς', 'σa', 'ü', 'ù', 'à', 'â'];
        yield 'code points, texts alone' => ['atoms' => $texts, 'flags' => 'u', 'last' => null, 'seed' => 3];
        yield 'code points, texts alone under i' => ['atoms' => $texts, 'flags' => 'iu', 'last' => null, 'seed' => 4];

        $unicode = [...$texts, '[éè]x', '[êë]', '\p{Lu}é', 'é+', '(?:éa)b', 'k', 'K', 's'];
        yield 'code points asked of PCRE' => ['atoms' => $unicode, 'flags' => 'u', 'last' => '[\x{2603}]', 'seed' => 5];
        yield 'code points asked of PCRE under i' => ['atoms' => $unicode, 'flags' => 'iu', 'last' => '[\x{2603}]', 'seed' => 6];
    }

    /**
     * Every branch with every later one, each pair as an alternation of
     * its own: the finding of the first pair of literal branches that
     * overlap, or else of the first pair that has one. A last branch that
     * overlaps nothing is added to each pair when the whole has it.
     *
     * @param list<string> $branches
     *
     * @return list<string>
     */
    private function pairwise(array $branches, string $flags, ?string $last = null): array
    {
        $first = [];
        $total = \count($branches);
        for ($i = 0; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                $found = $this->findings(self::alternation([$branches[$i], $branches[$j], ...(array) $last], $flags));
                if ([] !== $found && str_starts_with($found[0], OverlappingAlternationRule::ID_OVERLAP)) {
                    return $found;
                }

                $first = [] === $first ? $found : $first;
            }
        }

        return $first;
    }

    /**
     * @param list<string> $branches
     */
    private static function alternation(array $branches, string $flags): string
    {
        return '/(?:'.implode('|', $branches).')+/'.$flags;
    }

    /**
     * @return list<string>
     */
    private function findings(string $pattern): array
    {
        if (!isset($this->findings[$pattern])) {
            $this->assertNotFalse(@preg_match($pattern, ''), $pattern);

            $linter = new PatternLinter();
            Regex::create(['cache' => null])->parse($pattern)->accept($linter);

            $found = [];
            foreach ($linter->getIssues() as $issue) {
                if (\in_array($issue->id, [OverlappingAlternationRule::ID_OVERLAP, OverlappingAlternationRule::ID_CHARSET], true)) {
                    $found[] = $issue->id.': '.$issue->message;
                }
            }

            $this->findings[$pattern] = $found;
        }

        return $this->findings[$pattern];
    }
}
