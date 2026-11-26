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
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HirTranslatorTest extends TestCase
{
    use PcreGates;

    #[Test]
    public function test_adjacent_characters_are_one_literal_and_plain_groups_are_gone(): void
    {
        $hir = $this->translate('/a(?:bc)d/');

        $this->assertInstanceOf(LiteralHir::class, $hir);
        $this->assertSame([0x61, 0x62, 0x63, 0x64], $hir->codePoints);
    }

    #[Test]
    public function test_long_literal_runs_keep_all_code_points_and_bounded_properties(): void
    {
        $points = array_merge(array_fill(0, 8000, 0xE9), [0x7A]);
        $subject = str_repeat('é', 8000).'z';
        $pattern = '/'.$subject.'/u';
        $this->assertSame(1, preg_match($pattern, $subject));
        $hir = $this->translate($pattern);

        $this->assertInstanceOf(LiteralHir::class, $hir);
        $this->assertSame($points, $hir->codePoints);
        $this->assertSame([8001, 8001], [$hir->properties->minLength, $hir->properties->maxLength]);
        $this->assertNull($hir->properties->literal);
        $this->assertSame(array_slice($points, 0, 256), $hir->properties->prefix);
        $this->assertSame(array_slice($points, -256), $hir->properties->suffix);
        $this->assertSame([0, 16001], [$hir->startPosition, $hir->endPosition]);
    }

    #[Test]
    public function test_literal_runs_flush_at_assertions_and_across_flattened_sequences(): void
    {
        $pattern = '/ab(?:c\\Bde)f(?#ignored)g/';
        // "\B" holds between "c" and "d": the pattern matches.
        $this->assertSame(1, preg_match($pattern, 'abcdefg'));
        $hir = $this->translate($pattern);

        $this->assertInstanceOf(ConcatHir::class, $hir);
        $this->assertCount(3, $hir->parts);
        $this->assertInstanceOf(LiteralHir::class, $hir->parts[0]);
        $this->assertSame([97, 98, 99], $hir->parts[0]->codePoints);
        $this->assertSame([0, 6], [$hir->parts[0]->startPosition, $hir->parts[0]->endPosition]);
        $this->assertInstanceOf(AssertionHir::class, $hir->parts[1]);
        $this->assertInstanceOf(LiteralHir::class, $hir->parts[2]);
        $this->assertSame([100, 101, 102, 103], $hir->parts[2]->codePoints);
        $this->assertSame([8, 24], [$hir->parts[2]->startPosition, $hir->parts[2]->endPosition]);
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
     * A possessive quantifier never gives back what it matched, once
     * included: "/^(?:a|ab){1}+c\z/" refuses "abc", which "/^(?:a|ab)c\z/"
     * takes, as an atomic group does.
     */
    #[Test]
    #[DataProvider('providePossessiveSingleRepeats')]
    public function test_a_possessive_single_repeat_stays_possessive(string $pattern): void
    {
        $this->assertSame(0, preg_match($pattern, 'abc'));
        $this->assertSame(1, preg_match($pattern, 'ac'));
        $this->assertSame(0, preg_match('/^(?>a|ab)c\z/', 'abc'));
        $this->assertSame(1, preg_match('/^(?:a|ab)c\z/', 'abc'));

        $hir = $this->translate($pattern);

        $this->assertInstanceOf(ConcatHir::class, $hir);
        $repeat = $hir->parts[1];
        $this->assertInstanceOf(RepetitionHir::class, $repeat);
        $this->assertSame([1, 1, Greed::Possessive], [$repeat->min, $repeat->max, $repeat->greed]);
        $this->assertInstanceOf(AlternationHir::class, $repeat->body);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePossessiveSingleRepeats(): iterable
    {
        yield 'one' => ['pattern' => '/^(?:a|ab){1}+c\z/'];
        yield 'one to one' => ['pattern' => '/^(?:a|ab){1,1}+c\z/'];
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

    /**
     * Whether a "|" stands before the "no" branch, an empty one included,
     * asked of the engine first: PCRE refuses a conditional of three
     * branches, so one more "|" before its ")" compiles only where none
     * was written.
     */
    #[Test]
    #[DataProvider('provideConditionalBars')]
    public function test_a_conditional_keeps_whether_a_bar_was_written_before_its_no_branch(string $pattern, bool $written): void
    {
        $this->assertSame(1, preg_match($pattern, 'ab'), 'Oracle: PCRE compiles '.$pattern.' and matches "ab".');
        $close = strrpos($pattern, ')');
        $this->assertIsInt($close);
        $oneMoreBar = substr_replace($pattern, '|z', $close, 0);
        $this->assertSame(!$written, EngineProbe::compiles($oneMoreBar), \sprintf('Oracle: PCRE reads %s bar before the no branch of %s: %s.', $written ? 'a' : 'no', $pattern, EngineProbe::warning($oneMoreBar) ?? 'compiled'));

        $hir = $this->translate($pattern);

        $this->assertInstanceOf(ConcatHir::class, $hir);
        $conditional = $hir->parts[1];
        $this->assertInstanceOf(ConditionalHir::class, $conditional);
        $this->assertSame($written, $conditional->noBranchWritten);
    }

    /**
     * @return iterable<string, array{pattern: string, written: bool}>
     */
    public static function provideConditionalBars(): iterable
    {
        yield 'no bar' => ['pattern' => '/(a)?(?(1)b)/', 'written' => false];
        yield 'a bar before an empty branch' => ['pattern' => '/(a)?(?(1)b|)/', 'written' => true];
        yield 'a bar before a branch' => ['pattern' => '/(a)?(?(1)b|c)/', 'written' => true];
        yield 'no bar after an empty yes branch' => ['pattern' => '/(a)?(?(1))/', 'written' => false];
        yield 'a bar between two empty branches' => ['pattern' => '/(a)?(?(1)|)/', 'written' => true];
        yield 'no bar after a space x skips' => ['pattern' => '/(a)?(?(1)b )/x', 'written' => false];
        yield 'a bar after a space x skips' => ['pattern' => '/(a)?(?(1)b |)/x', 'written' => true];
    }

    /**
     * The bar the conditional keeps is the one PCRE reads: in a lookbehind,
     * it gives "(?(c)X)" the length of X alone, where "(?(c)X|)" may also
     * be empty, so the negative lookbehind lets "c" through only without
     * the bar.
     */
    #[Test]
    #[DataProvider('provideConditionalBarsInALookbehind')]
    public function test_the_bar_a_conditional_keeps_is_the_one_pcre_reads_in_a_lookbehind(string $pattern, bool $written, int $matches): void
    {
        $this->requiresCompile($pattern);
        $this->assertSame($matches, preg_match($pattern, 'c'));

        $bars = [];
        $this->walk($this->translate($pattern), static function (Hir $hir) use (&$bars): void {
            if ($hir instanceof ConditionalHir) {
                $bars[] = $hir->noBranchWritten;
            }
        });

        $this->assertSame([$written], $bars);
    }

    /**
     * @return iterable<string, array{pattern: string, written: bool, matches: int}>
     */
    public static function provideConditionalBarsInALookbehind(): iterable
    {
        yield 'no bar' => ['pattern' => '/(a)?(?<!(?(1)a))c/', 'written' => false, 'matches' => 1];
        yield 'a bar before an empty branch' => ['pattern' => '/(a)?(?<!(?(1)a|))c/', 'written' => true, 'matches' => 0];
        yield 'no bar after a space x skips' => ['pattern' => '/(a)?(?<!(?(1)a ))c/x', 'written' => false, 'matches' => 1];
        yield 'a bar after a space x skips' => ['pattern' => '/(a)?(?<!(?(1)a |))c/x', 'written' => true, 'matches' => 0];
    }

    #[Test]
    public function test_comments_definitions_and_option_settings_match_empty(): void
    {
        $this->assertInstanceOf(EmptyHir::class, $this->translate('/(?#note)(?i)(?(DEFINE)(?<x>a))/'));
    }

    /**
     * What a "(?(DEFINE)...)" holds runs only where a call runs it, under
     * the options in force where the DEFINE stands: the form leaves it out,
     * and the translator reads it apart, each DEFINE of the pattern last
     * translated in the order written.
     */
    #[Test]
    public function test_what_a_define_holds_is_read_apart_under_the_options_in_force_there(): void
    {
        $pattern = '/(?i:(?(DEFINE)(?<n>a)))(?(DEFINE)(?<m>b))(?&n)(?&m)/';
        // Oracle: the first group runs caseless, the second not.
        $this->assertSame(1, preg_match($pattern, 'Ab'));
        $this->assertSame(0, preg_match($pattern, 'aB'));

        $translator = new HirTranslator();
        $translator->translate(Regex::create(['cache' => null])->parse($pattern));
        $defined = $translator->defined();

        $this->assertCount(2, $defined);
        [$caseless, $plain] = $defined;
        $this->assertInstanceOf(CaptureHir::class, $caseless);
        $this->assertSame([1, 'n'], [$caseless->index, $caseless->name]);
        $this->assertInstanceOf(ClassHir::class, $caseless->body);
        $this->assertTrue($caseless->body->set->contains(0x41));
        $this->assertTrue($caseless->body->set->contains(0x61));
        $this->assertInstanceOf(CaptureHir::class, $plain);
        $this->assertSame([2, 'm'], [$plain->index, $plain->name]);
        $this->assertInstanceOf(LiteralHir::class, $plain->body);
        $this->assertSame([0x62], $plain->body->codePoints);

        $translator->translate(Regex::create(['cache' => null])->parse('/a/'));

        $this->assertSame([], $translator->defined());
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

    /**
     * Under (*CRLF) the newline is two characters, which no set of one
     * character says: the dot takes a "\r" and a "\n", each alone, and not
     * the "\r" a "\n" follows. The form says it with a lookahead.
     */
    #[Test]
    #[DataProvider('provideDotsUnderCrLf')]
    public function test_a_dot_under_crlf_takes_no_carriage_return_a_line_feed_follows(string $options, string $body, string $flags, bool $stopsAtCrLf): void
    {
        // The engine first: one character alone is always taken, and the
        // "\r" of a "\r\n" only where the dot takes every character.
        $anchored = '/'.$options.'\A(?:'.$body.')/'.$flags;
        foreach (["\r", "\n", 'a', "\ra"] as $subject) {
            $this->assertSame(1, preg_match($anchored, $subject), $anchored.' on '.json_encode($subject));
        }
        $this->assertSame($stopsAtCrLf ? 0 : 1, preg_match($anchored, "\r\n"), $anchored);
        $this->assertSame($stopsAtCrLf ? 0 : 1, preg_match('/(*NO_JIT)'.substr($anchored, 1), "\r\n"), $anchored);

        $hir = $this->translate('/'.$options.$body.'/'.$flags);

        if (!$stopsAtCrLf) {
            $this->assertInstanceOf(ClassHir::class, $hir);
            $this->assertTrue($hir->set->contains(0x0D));

            return;
        }

        $this->assertInstanceOf(ConcatHir::class, $hir);
        $this->assertCount(2, $hir->parts);
        [$look, $class] = $hir->parts;
        $this->assertInstanceOf(LookHir::class, $look);
        $this->assertSame(LookKind::NegativeAhead, $look->kind);
        $this->assertTrue($look->atomic);
        $this->assertInstanceOf(LiteralHir::class, $look->body);
        $this->assertSame([0x0D, 0x0A], $look->body->codePoints);
        $this->assertInstanceOf(ClassHir::class, $class);
        $this->assertTrue($class->set->contains(0x0D));
        $this->assertTrue($class->set->contains(0x0A));
        $this->assertSame([\strlen('/'.$options) - 1, \strlen('/'.$options.$body) - 1], [$class->startPosition, $class->endPosition]);
        $this->assertSame([$class->startPosition, $class->endPosition], [$look->startPosition, $look->endPosition]);
        $this->assertSame(1, $hir->properties->minLength);
        $this->assertSame(1, $hir->properties->maxLength);
        $this->assertFalse($hir->properties->regular);
    }

    /**
     * @return iterable<string, array{options: string, body: string, flags: string, stopsAtCrLf: bool}>
     */
    public static function provideDotsUnderCrLf(): iterable
    {
        yield 'a dot' => ['options' => '(*CRLF)', 'body' => '.', 'flags' => '', 'stopsAtCrLf' => true];
        yield 'no newline' => ['options' => '(*CRLF)', 'body' => '\N', 'flags' => '', 'stopsAtCrLf' => true];
        yield 'no newline under s' => ['options' => '(*CRLF)', 'body' => '\N', 'flags' => 's', 'stopsAtCrLf' => true];
        yield 'a dot under s' => ['options' => '(*CRLF)', 'body' => '.', 'flags' => 's', 'stopsAtCrLf' => false];
        yield 'a dot under m' => ['options' => '(*CRLF)', 'body' => '.', 'flags' => 'm', 'stopsAtCrLf' => true];
        yield 'a dot after another option' => ['options' => '(*UCP)(*CRLF)', 'body' => '.', 'flags' => '', 'stopsAtCrLf' => true];
        yield 'the last convention named' => ['options' => '(*LF)(*CRLF)', 'body' => '.', 'flags' => '', 'stopsAtCrLf' => true];
    }

    /**
     * An option set inside the pattern decides for the dots after it.
     */
    #[Test]
    public function test_a_dot_under_crlf_follows_the_s_in_force_where_it_stands(): void
    {
        $this->assertSame(1, preg_match('/(*CRLF)\A(?s).(?-s)./', "\r\n\ra"));
        $this->assertSame(0, preg_match('/(*CRLF)\A(?s).(?-s)./', "a\r\n"));
        $this->assertSame(0, preg_match('/(*NO_JIT)(*CRLF)\A(?s).(?-s)./', "a\r\n"));

        $hir = $this->translate('/(*CRLF)(?s).(?-s)./');

        $this->assertInstanceOf(ConcatHir::class, $hir);
        $this->assertCount(3, $hir->parts);
        $this->assertInstanceOf(ClassHir::class, $hir->parts[0]);
        $this->assertInstanceOf(LookHir::class, $hir->parts[1]);
        $this->assertInstanceOf(ClassHir::class, $hir->parts[2]);
    }

    /**
     * Each dot and "\N" of a pattern asks the one lookahead, so the automata
     * read a pattern of many dots through one promise.
     */
    #[Test]
    public function test_every_dot_under_crlf_shares_one_lookahead_body(): void
    {
        $this->assertSame(0, preg_match('/(*CRLF)\A.\N\z/', "a\r\n"));
        $this->assertSame(1, preg_match('/(*CRLF)\A.\N\z/', "\r\r"));

        $hir = $this->translate('/(*CRLF).\N/');

        $this->assertInstanceOf(ConcatHir::class, $hir);
        $this->assertCount(4, $hir->parts);
        $this->assertInstanceOf(LookHir::class, $hir->parts[0]);
        $this->assertInstanceOf(LookHir::class, $hir->parts[2]);
        $this->assertSame($hir->parts[0]->body, $hir->parts[2]->body);
        $this->assertSame([7, 8], [$hir->parts[0]->startPosition, $hir->parts[1]->endPosition]);
        $this->assertSame([8, 10], [$hir->parts[2]->startPosition, $hir->parts[3]->endPosition]);
    }

    /**
     * Under another convention the newline is one character, or several
     * each of one: the set of the dot says it all.
     */
    #[Test]
    #[DataProvider('provideDotsUnderOtherConventions')]
    public function test_a_dot_under_another_convention_is_a_set(string $pattern, bool $carriageReturn, bool $lineFeed): void
    {
        $this->assertSame($carriageReturn ? 1 : 0, preg_match($pattern, "\r"));
        $this->assertSame($lineFeed ? 1 : 0, preg_match($pattern, "\n"));

        $hir = $this->translate($pattern);

        $this->assertInstanceOf(ClassHir::class, $hir);
        $this->assertSame($carriageReturn, $hir->set->contains(0x0D));
        $this->assertSame($lineFeed, $hir->set->contains(0x0A));
    }

    /**
     * @return iterable<string, array{pattern: string, carriageReturn: bool, lineFeed: bool}>
     */
    public static function provideDotsUnderOtherConventions(): iterable
    {
        yield 'no convention' => ['pattern' => '/./', 'carriageReturn' => true, 'lineFeed' => false];
        yield 'a line feed' => ['pattern' => '/(*LF)./', 'carriageReturn' => true, 'lineFeed' => false];
        yield 'either or both' => ['pattern' => '/(*ANYCRLF)./', 'carriageReturn' => false, 'lineFeed' => false];
        yield 'any newline' => ['pattern' => '/(*ANY)\N/', 'carriageReturn' => false, 'lineFeed' => false];
        yield 'a line feed named after cr and lf' => ['pattern' => '/(*CRLF)(*LF)./', 'carriageReturn' => true, 'lineFeed' => false];
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
