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

namespace PHPRegex\Tests\Unit\Bridge\Infection;

use PHPRegex\Infection\Mutation\CandidateGenerator;
use PHPRegex\Infection\Mutation\Family;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The mutated patterns each family writes for a pattern, before anything is
 * compiled, proven or dropped: in the order of the tree, then in the order
 * of the family's operators, the original and repeated patterns left out.
 */
final class CandidateGeneratorTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideCandidates')]
    public function test_each_family_writes_its_mutations_in_tree_order(Family $family, string $pattern, array $expected): void
    {
        $this->assertSame($expected, (new CandidateGenerator())->candidates($family, $pattern));
    }

    /**
     * @return iterable<string, array{family: Family, pattern: string, expected: list<string>}>
     */
    public static function provideCandidates(): iterable
    {
        // Quantifiers: + and * swap, the bounds move by one, the repeat
        // goes, then the laziness turns.
        yield 'plus' => ['family' => Family::Quantifier, 'pattern' => '/a+/', 'expected' => ['/a*/', '/a/', '/a+?/']];
        yield 'star' => ['family' => Family::Quantifier, 'pattern' => '/a*/', 'expected' => ['/a+/', '/a/', '/a*?/']];
        yield 'optional' => ['family' => Family::Quantifier, 'pattern' => '/ab?/', 'expected' => ['/ab/', '/ab??/']];
        yield 'lazy plus' => ['family' => Family::Quantifier, 'pattern' => '/a+?/', 'expected' => ['/a*?/', '/a/', '/a+/']];
        yield 'possessive star' => ['family' => Family::Quantifier, 'pattern' => '/a*+/', 'expected' => ['/a++/', '/a/', '/a*/']];
        yield 'bounded repeat' => ['family' => Family::Quantifier, 'pattern' => '/\d{2,4}/', 'expected' => ['/\d{3,4}/', '/\d{1,4}/', '/\d{2,3}/', '/\d{2,5}/', '/\d/', '/\d{2,4}?/']];
        yield 'range collapsing to one' => ['family' => Family::Quantifier, 'pattern' => '/a{1,2}/', 'expected' => ['/a{2,2}/', '/a{0,2}/', '/a{1,1}/', '/a{1,3}/', '/a/', '/a{1,2}?/']];
        yield 'exact count' => ['family' => Family::Quantifier, 'pattern' => '/a{3}/', 'expected' => ['/a{2}/', '/a{4}/', '/a/', '/a{3}?/']];
        yield 'exact zero' => ['family' => Family::Quantifier, 'pattern' => '/ab{0}/', 'expected' => ['/ab{1}/', '/ab/', '/ab{0}?/']];
        yield 'exact one' => ['family' => Family::Quantifier, 'pattern' => '/a{1}/', 'expected' => ['/a{0}/', '/a{2}/', '/a/', '/a{1}?/']];
        yield 'open range from one' => ['family' => Family::Quantifier, 'pattern' => '/a{1,}/', 'expected' => ['/a{2,}/', '/a{0,}/', '/a/', '/a{1,}?/']];
        yield 'flat bounded repeat' => ['family' => Family::Quantifier, 'pattern' => '/a{2,2}/', 'expected' => ['/a{1,2}/', '/a{2,3}/', '/a/', '/a{2,2}?/']];
        yield 'bounded repeat from zero' => ['family' => Family::Quantifier, 'pattern' => '/a{0,3}/', 'expected' => ['/a{1,3}/', '/a{0,2}/', '/a{0,4}/', '/a/', '/a{0,3}?/']];
        yield 'open range' => ['family' => Family::Quantifier, 'pattern' => '/a{2,}/', 'expected' => ['/a{3,}/', '/a{1,}/', '/a/', '/a{2,}?/']];
        yield 'open range from zero' => ['family' => Family::Quantifier, 'pattern' => '/a{0,}/', 'expected' => ['/a{1,}/', '/a/', '/a{0,}?/']];
        yield 'lazy range' => ['family' => Family::Quantifier, 'pattern' => '/a{2,3}?/', 'expected' => ['/a{3,3}?/', '/a{1,3}?/', '/a{2,2}?/', '/a{2,4}?/', '/a/', '/a{2,3}/']];
        yield 'two quantifiers in tree order' => ['family' => Family::Quantifier, 'pattern' => '/a+b?/', 'expected' => ['/a*b?/', '/ab?/', '/a+?b?/', '/a+b/', '/a+b??/']];
        yield 'quantified group' => ['family' => Family::Quantifier, 'pattern' => '/(?:ab)+/', 'expected' => ['/(?:ab)*/', '/(?:ab)/', '/(?:ab)+?/']];
        yield 'nested quantifiers, outer first' => ['family' => Family::Quantifier, 'pattern' => '/(?:a+)?/', 'expected' => ['/(?:a+)/', '/(?:a+)??/', '/(?:a*)?/', '/(?:a)?/', '/(?:a+?)?/']];
        yield 'no quantifier' => ['family' => Family::Quantifier, 'pattern' => '/abc/', 'expected' => []];
        yield 'flags and delimiter kept' => ['family' => Family::Quantifier, 'pattern' => '#a+#i', 'expected' => ['#a*#i', '#a#i', '#a+?#i']];
        yield 'bracket delimiters' => ['family' => Family::Quantifier, 'pattern' => '{a+}', 'expected' => ['{a*}', '{a}', '{a+?}']];

        // Anchors and boundaries: each goes, then $ and \z swap.
        yield 'caret and dollar' => ['family' => Family::Anchor, 'pattern' => '/^ab$/', 'expected' => ['/ab$/', '/^ab/', '/^ab\z/']];
        yield 'subject anchors' => ['family' => Family::Anchor, 'pattern' => '/\Aab\z/', 'expected' => ['/ab\z/', '/\Aab/', '/\Aab$/']];
        yield 'capital Z' => ['family' => Family::Anchor, 'pattern' => '/ab\Z/', 'expected' => ['/ab/']];
        yield 'word boundaries' => ['family' => Family::Anchor, 'pattern' => '/\bab\B/', 'expected' => ['/ab\B/', '/\bab/']];
        yield 'anchor in a group' => ['family' => Family::Anchor, 'pattern' => '/(?:^a|b$)/', 'expected' => ['/(?:a|b$)/', '/(?:^a|b)/', '/(?:^a|b\z)/']];
        yield 'escaped dollar is no anchor' => ['family' => Family::Anchor, 'pattern' => '/a\$/', 'expected' => []];
        yield 'G is not mutated' => ['family' => Family::Anchor, 'pattern' => '/\Ga/', 'expected' => []];

        // Classes: the class turns, a member goes, a range narrows, a
        // character type turns.
        yield 'class' => ['family' => Family::CharClass, 'pattern' => '/[abc]/', 'expected' => ['/[^abc]/', '/[bc]/', '/[ac]/', '/[ab]/']];
        yield 'negated class' => ['family' => Family::CharClass, 'pattern' => '/[^ab]/', 'expected' => ['/[ab]/', '/[^b]/', '/[^a]/']];
        yield 'class range' => ['family' => Family::CharClass, 'pattern' => '/[a-z]/', 'expected' => ['/[^a-z]/', '/[b-z]/', '/[a-y]/']];
        yield 'range and member' => ['family' => Family::CharClass, 'pattern' => '/[a-c_]/', 'expected' => ['/[^a-c_]/', '/[_]/', '/[a-c]/', '/[b-c_]/', '/[a-b_]/']];
        yield 'one-wide range' => ['family' => Family::CharClass, 'pattern' => '/[a-b]/', 'expected' => ['/[^a-b]/', '/[b-b]/', '/[a-a]/']];
        yield 'flat range' => ['family' => Family::CharClass, 'pattern' => '/[a-a]/', 'expected' => ['/[^a-a]/']];
        yield 'range ending before a class metacharacter' => ['family' => Family::CharClass, 'pattern' => '/[0-^]/', 'expected' => ['/[^0-^]/', '/[1-^]/']];
        yield 'one end escaped' => ['family' => Family::CharClass, 'pattern' => '/[a-\x7a]/', 'expected' => ['/[^a-\x7a]/']];
        yield 'one end an escaped literal' => ['family' => Family::CharClass, 'pattern' => '/[+-\.]/', 'expected' => ['/[^+-\.]/']];
        yield 'printable bounds' => ['family' => Family::CharClass, 'pattern' => '/[ -~]/', 'expected' => ['/[^ -~]/', '/[!-~]/', '/[ -}]/']];
        yield 'delete is no printable end' => ['family' => Family::CharClass, 'pattern' => "/[a-\x7f]/", 'expected' => ["/[^a-\x7f]/"]];
        yield 'escaped low end' => ['family' => Family::CharClass, 'pattern' => '/[\x41-z]/', 'expected' => ['/[^\x41-z]/']];
        yield 'escaped range ends are left alone' => ['family' => Family::CharClass, 'pattern' => '/[\x41-\x5a]/', 'expected' => ['/[^\x41-\x5a]/']];
        yield 'character types' => ['family' => Family::CharClass, 'pattern' => '/\d\W\s/', 'expected' => ['/\D\W\s/', '/\d\w\s/', '/\d\W\S/']];
        yield 'type inside a class' => ['family' => Family::CharClass, 'pattern' => '/[\d_]/', 'expected' => ['/[^\d_]/', '/[_]/', '/[\d]/', '/[\D_]/']];
        yield 'other types are not mutated' => ['family' => Family::CharClass, 'pattern' => '/\h\R/', 'expected' => []];

        // Alternation: a branch goes, then neighbours swap.
        yield 'three branches' => ['family' => Family::Alternation, 'pattern' => '/(?:ab|cd|ef)/', 'expected' => ['/(?:cd|ef)/', '/(?:ab|ef)/', '/(?:ab|cd)/', '/(?:cd|ab|ef)/', '/(?:ab|ef|cd)/']];
        yield 'top level' => ['family' => Family::Alternation, 'pattern' => '/a|b/', 'expected' => ['/b/', '/a/', '/b|a/']];
        yield 'equal branches swap to the original' => ['family' => Family::Alternation, 'pattern' => '/a|a/', 'expected' => ['/a/']];
        yield 'class members are no alternation' => ['family' => Family::Alternation, 'pattern' => '/[ab]/', 'expected' => []];

        // Flags: each modifier goes, u never.
        yield 'flags' => ['family' => Family::Flag, 'pattern' => '/a/imsx', 'expected' => ['/a/msx', '/a/isx', '/a/imx', '/a/ims']];
        yield 'u is kept' => ['family' => Family::Flag, 'pattern' => '/a/iu', 'expected' => ['/a/u']];
        yield 'only u' => ['family' => Family::Flag, 'pattern' => '/a/u', 'expected' => []];
        yield 'no flag' => ['family' => Family::Flag, 'pattern' => '/a/', 'expected' => []];
        yield 'D and s' => ['family' => Family::Flag, 'pattern' => '/a$/Ds', 'expected' => ['/a$/s', '/a$/D']];

        // Escapes: an escaped metacharacter outside a class loses its escape.
        yield 'escaped dot and dollar' => ['family' => Family::Escape, 'pattern' => '/a\.b\$/', 'expected' => ['/a.b\$/', '/a\.b$/']];
        yield 'every listed metacharacter' => ['family' => Family::Escape, 'pattern' => '/a\^\|\?\*\+\{/', 'expected' => ['/a^\|\?\*\+\{/', '/a\^|\?\*\+\{/', '/a\^\|?\*\+\{/', '/a\^\|\?*\+\{/', '/a\^\|\?\*+\{/', '/a\^\|\?\*\+{/']];
        yield 'escape in a class is kept' => ['family' => Family::Escape, 'pattern' => '/[\.]/', 'expected' => []];
        yield 'other escapes are kept' => ['family' => Family::Escape, 'pattern' => '/\(\/\-/', 'expected' => []];
    }

    #[Test]
    public function test_the_generator_reads_the_pattern_for_the_parser_it_is_given(): void
    {
        // PCRE2 10.39 reads "{ 2,3 }" as text: there is no repeat to mutate.
        $this->assertSame([], (new CandidateGenerator(RegexParser::create(['pcre_version' => '10.39'])))->candidates(Family::Quantifier, '/a{ 2,3 }/'));
    }

    #[Test]
    public function test_the_pattern_is_rewritten_only_on_the_mutated_span(): void
    {
        $this->assertSame(
            ["/ a  # first\n b*  # second\n/x", "/ a  # first\n b  # second\n/x", "/ a  # first\n b+?  # second\n/x"],
            (new CandidateGenerator())->candidates(Family::Quantifier, "/ a  # first\n b+  # second\n/x"),
        );
    }

    #[Test]
    public function test_a_pattern_with_leading_whitespace_keeps_it(): void
    {
        $this->assertSame(['  /a*/', '  /a/', '  /a+?/'], (new CandidateGenerator())->candidates(Family::Quantifier, '  /a+/'));
    }
}
