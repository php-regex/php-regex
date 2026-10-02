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

use PHPRegex\Parser\Hir\AlternationHir;
use PHPRegex\Parser\Hir\AssertionHir;
use PHPRegex\Parser\Hir\AssertionKind;
use PHPRegex\Parser\Hir\AtomicHir;
use PHPRegex\Parser\Hir\CaptureHir;
use PHPRegex\Parser\Hir\ClassHir;
use PHPRegex\Parser\Hir\ConcatHir;
use PHPRegex\Parser\Hir\ConditionalHir;
use PHPRegex\Parser\Hir\EmptyHir;
use PHPRegex\Parser\Hir\Greed;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\Hir\LiteralHir;
use PHPRegex\Parser\Hir\LookHir;
use PHPRegex\Parser\Hir\LookKind;
use PHPRegex\Parser\Hir\OpaqueHir;
use PHPRegex\Parser\Hir\RepetitionHir;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HirTranslatorTest extends TestCase
{
    #[Test]
    public function test_adjacent_characters_are_one_literal_and_plain_groups_are_gone(): void
    {
        $hir = $this->translate('/a(?:bc)d/');

        $this->assertInstanceOf(LiteralHir::class, $hir);
        $this->assertSame([0x61, 0x62, 0x63, 0x64], $hir->codePoints);
    }

    #[Test]
    public function test_a_literal_counts_code_points_under_u_and_bytes_without(): void
    {
        $unicode = $this->translate('/é/u');
        $bytes = $this->translate('/é/');

        $this->assertInstanceOf(LiteralHir::class, $unicode);
        $this->assertSame([0xE9], $unicode->codePoints);
        $this->assertInstanceOf(LiteralHir::class, $bytes);
        $this->assertSame([0xC3, 0xA9], $bytes->codePoints);
    }

    #[Test]
    public function test_a_caseless_letter_is_the_set_pcre_folds_it_to(): void
    {
        $hir = $this->translate('/k/iu');

        $this->assertInstanceOf(ClassHir::class, $hir);
        // "K", "k" and the Kelvin sign.
        $this->assertSame([[0x4B, 0x4B], [0x6B, 0x6B], [0x212A, 0x212A]], $hir->set->ranges);
    }

    #[Test]
    public function test_a_caseless_letter_without_u_folds_within_ascii(): void
    {
        $hir = $this->translate('/k/i');

        $this->assertInstanceOf(ClassHir::class, $hir);
        $this->assertSame([[0x4B, 0x4B], [0x6B, 0x6B]], $hir->set->ranges);
    }

    #[Test]
    public function test_pcre_folds_one_character_at_a_time(): void
    {
        // "ß" does not match "ss": PCRE has no full case folding.
        $hir = $this->translate('/ß/iu');

        $this->assertInstanceOf(ClassHir::class, $hir);
        $this->assertFalse($hir->set->contains(0x73));
        $this->assertTrue($hir->set->contains(0x1E9E));
    }

    #[Test]
    public function test_a_unicode_property_is_the_set_of_the_running_engine(): void
    {
        $hir = $this->translate('/\p{L}/u');

        $this->assertInstanceOf(ClassHir::class, $hir);
        $this->assertTrue($hir->set->contains(0x61));
        $this->assertTrue($hir->set->contains(0x4E2D));
        $this->assertFalse($hir->set->contains(0x31));
    }

    #[Test]
    public function test_a_posix_class_and_a_class_operation_are_sets_too(): void
    {
        $posix = $this->translate('/[[:alpha:]]/');
        $this->assertInstanceOf(ClassHir::class, $posix);
        $this->assertSame([[0x41, 0x5A], [0x61, 0x7A]], $posix->set->ranges);

        $negated = $this->translate('/[^a-c]/');
        $this->assertInstanceOf(ClassHir::class, $negated);
        $this->assertSame([[0x00, 0x60], [0x64, 0xFF]], $negated->set->ranges);
    }

    #[Test]
    public function test_an_option_setting_holds_until_the_end_of_its_group_and_in_later_branches(): void
    {
        $hir = $this->translate('/(?:x(?i)b|a)c/');

        $this->assertInstanceOf(ConcatHir::class, $hir);
        $alternation = $hir->parts[0];
        $this->assertInstanceOf(AlternationHir::class, $alternation);
        $branch = $alternation->branches[0];
        $this->assertInstanceOf(ConcatHir::class, $branch);
        $this->assertInstanceOf(ClassHir::class, $branch->parts[1]);
        // "(?i)" reaches the next branch, not past the group.
        $this->assertInstanceOf(ClassHir::class, $alternation->branches[1]);
        $this->assertInstanceOf(LiteralHir::class, $hir->parts[1]);
    }

    #[Test]
    public function test_a_scoped_option_holds_only_inside_its_group(): void
    {
        $hir = $this->translate('/(?i:a)b/');

        $this->assertInstanceOf(ConcatHir::class, $hir);
        $this->assertInstanceOf(ClassHir::class, $hir->parts[0]);
        $this->assertInstanceOf(LiteralHir::class, $hir->parts[1]);
    }

    /**
     * @param list<int> $numbers
     */
    #[Test]
    #[DataProvider('provideGroupNumbers')]
    public function test_capturing_groups_carry_their_pcre_number(string $pattern, array $numbers): void
    {
        $found = [];
        $this->walk($this->translate($pattern), static function (Hir $hir) use (&$found): void {
            if ($hir instanceof CaptureHir) {
                $found[] = $hir->index;
            }
        });

        $this->assertSame($numbers, $found);
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function provideGroupNumbers(): iterable
    {
        yield 'in order' => ['/(a)(b(c))/', [1, 2, 3]];
        yield 'named' => ['/(?<x>a)(b)/', [1, 2]];
        yield 'branch reset' => ['/(?|(a)|(b)(c))(d)/', [1, 1, 2, 3]];
        yield 'after a conditional' => ['/(a)(?(1)(b)|(c))(d)/', [1, 2, 3, 4]];
    }

    #[Test]
    public function test_a_repetition_keeps_its_bounds_and_greed(): void
    {
        $greedy = $this->translate('/a{2,5}/');
        $this->assertInstanceOf(RepetitionHir::class, $greedy);
        $this->assertSame([2, 5, Greed::Greedy], [$greedy->min, $greedy->max, $greedy->greed]);

        $lazy = $this->translate('/a*?/');
        $this->assertInstanceOf(RepetitionHir::class, $lazy);
        $this->assertSame([0, null, Greed::Lazy], [$lazy->min, $lazy->max, $lazy->greed]);

        $possessive = $this->translate('/a++/');
        $this->assertInstanceOf(RepetitionHir::class, $possessive);
        $this->assertSame(Greed::Possessive, $possessive->greed);
    }

    #[Test]
    public function test_ungreedy_swaps_greed_but_not_possessiveness(): void
    {
        $hir = $this->translate('/a*b*?c*+/U');

        $this->assertInstanceOf(ConcatHir::class, $hir);
        $greeds = array_map(static fn (Hir $part): ?Greed => $part instanceof RepetitionHir ? $part->greed : null, $hir->parts);
        $this->assertSame([Greed::Lazy, Greed::Greedy, Greed::Possessive], $greeds);
    }

    #[Test]
    public function test_a_single_repeat_is_its_body(): void
    {
        $this->assertInstanceOf(LiteralHir::class, $this->translate('/a{1}/'));
    }

    /**
     * @param list<AssertionKind> $kinds
     */
    #[Test]
    #[DataProvider('provideAssertions')]
    public function test_anchors_take_the_meaning_their_options_give(string $pattern, array $kinds): void
    {
        $found = [];
        $this->walk($this->translate($pattern), static function (Hir $hir) use (&$found): void {
            if ($hir instanceof AssertionHir) {
                $found[] = $hir->kind;
            }
        });

        $this->assertSame($kinds, $found);
    }

    /**
     * @return iterable<string, array{string, list<AssertionKind>}>
     */
    public static function provideAssertions(): iterable
    {
        yield 'default' => ['/^a$/', [AssertionKind::SubjectStart, AssertionKind::EndOrFinalNewline]];
        yield 'multiline' => ['/^a$/m', [AssertionKind::LineStart, AssertionKind::LineEnd]];
        yield 'dollar end only' => ['/a$/D', [AssertionKind::SubjectEnd]];
        yield 'inline multiline' => ['/^a(?m)^b$/', [AssertionKind::SubjectStart, AssertionKind::LineStart, AssertionKind::LineEnd]];
        yield 'escapes' => ['/\A\b\B\G\z\Z\K/', [
            AssertionKind::SubjectStart,
            AssertionKind::WordBoundary,
            AssertionKind::NotWordBoundary,
            AssertionKind::MatchStart,
            AssertionKind::SubjectEnd,
            AssertionKind::EndOrFinalNewline,
            AssertionKind::ResetMatchStart,
        ]];
    }

    #[Test]
    public function test_lookarounds_and_atomic_groups_stay(): void
    {
        $hir = $this->translate('/(?<!a)(?>b|c)(?=d)/');

        $this->assertInstanceOf(ConcatHir::class, $hir);
        [$behind, $atomic, $ahead] = $hir->parts;
        $this->assertInstanceOf(LookHir::class, $behind);
        $this->assertSame(LookKind::NegativeBehind, $behind->kind);
        $this->assertTrue($behind->atomic);
        $this->assertInstanceOf(AtomicHir::class, $atomic);
        $this->assertInstanceOf(LookHir::class, $ahead);
        $this->assertSame(LookKind::Ahead, $ahead->kind);
    }

    #[Test]
    public function test_a_newline_sequence_is_an_atomic_choice(): void
    {
        $hir = $this->translate('/\R/');

        $this->assertInstanceOf(AtomicHir::class, $hir);
        $this->assertInstanceOf(AlternationHir::class, $hir->body);
        [$crlf, $single] = $hir->body->branches;
        $this->assertInstanceOf(LiteralHir::class, $crlf);
        $this->assertSame([0x0D, 0x0A], $crlf->codePoints);
        $this->assertInstanceOf(ClassHir::class, $single);
        $this->assertSame([[0x0A, 0x0D], [0x85, 0x85]], $single->set->ranges);
    }

    #[Test]
    public function test_what_the_form_does_not_take_apart_keeps_its_ast_node(): void
    {
        $hir = $this->translate('/(a)\1/');

        $this->assertInstanceOf(ConcatHir::class, $hir);
        $this->assertInstanceOf(OpaqueHir::class, $hir->parts[1]);
        $this->assertInstanceOf(BackrefNode::class, $hir->parts[1]->node);
    }

    #[Test]
    public function test_a_conditional_keeps_its_condition_and_both_branches(): void
    {
        $hir = $this->translate('/(a)?(?(1)b|cd)/');

        $this->assertInstanceOf(ConcatHir::class, $hir);
        $conditional = $hir->parts[1];
        $this->assertInstanceOf(ConditionalHir::class, $conditional);
        $this->assertInstanceOf(LiteralHir::class, $conditional->yes);
        $this->assertInstanceOf(LiteralHir::class, $conditional->no);
    }

    #[Test]
    public function test_comments_definitions_and_option_settings_match_empty(): void
    {
        $this->assertInstanceOf(EmptyHir::class, $this->translate('/(?#note)(?i)(?(DEFINE)(?<x>a))/'));
    }

    #[Test]
    public function test_start_of_pattern_options_reach_the_sets(): void
    {
        $dot = $this->translate('/(*CR)./');
        $this->assertInstanceOf(ClassHir::class, $dot);
        // With CR alone as the newline, a line feed is any other character.
        $this->assertTrue($dot->set->contains(0x0A));
        $this->assertFalse($dot->set->contains(0x0D));

        $utf = $this->translate('/(*UTF)é/');
        $this->assertInstanceOf(LiteralHir::class, $utf);
        $this->assertSame([0xE9], $utf->codePoints);

        // A limit stands in the run, with its value; the run ends at the first
        // item that is not an option, here the mark, which stays a verb.
        $limited = $this->translate('/(*LIMIT_MATCH=10)(*UTF)(*MARK:m)é/');
        $this->assertInstanceOf(ConcatHir::class, $limited);
        $this->assertInstanceOf(OpaqueHir::class, $limited->parts[0]);
        $this->assertInstanceOf(LiteralHir::class, $limited->parts[1]);
        $this->assertSame([0xE9], $limited->parts[1]->codePoints);
    }

    #[Test]
    public function test_nodes_keep_the_position_of_the_ast_node_they_come_from(): void
    {
        $hir = $this->translate('/ab(c)/');

        $this->assertInstanceOf(ConcatHir::class, $hir);
        $this->assertSame([0, 2], [$hir->parts[0]->startPosition, $hir->parts[0]->endPosition]);
        $this->assertSame([2, 5], [$hir->parts[1]->startPosition, $hir->parts[1]->endPosition]);
    }

    private function translate(string $regex): Hir
    {
        return (new HirTranslator())->translate(Regex::create(['cache' => null])->parse($regex));
    }

    /**
     * @param callable(Hir): void $visit
     */
    private function walk(Hir $hir, callable $visit): void
    {
        $visit($hir);
        foreach ($hir->children() as $child) {
            $this->walk($child, $visit);
        }
    }
}
