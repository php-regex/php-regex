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

namespace PHPRegex\Tests\Unit\Lexer;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Lexing\PatternText;
use PHPRegex\Parser\Lexing\TargetSyntax;
use PHPRegex\Parser\Lexing\VerbNames;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How the name of a verb is judged where it stands, and where a name or the
 * condition of "(?(" is due.
 */
final class VerbNamesTest extends TestCase
{
    /**
     * Each refusal stands where the running PCRE2 reports it.
     */
    #[Test]
    #[DataProvider('provideRefusedNames')]
    public function test_a_name_pcre_refuses_is_refused_where_pcre_reports_it(string $pattern, string $name, int $start, ErrorCode $code, string $pcreMessage): void
    {
        $this->assertFalse(@preg_match('/'.$pattern.'/', ''));
        $this->assertStringContainsString($pcreMessage, preg_last_error_msg().(error_get_last()['message'] ?? ''));

        try {
            VerbNames::refuse(self::text($pattern), $name, $start, [], false);
            $this->fail(\sprintf('"%s" was not refused.', $pattern));
        } catch (LexerException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertNotNull($exception->position);
            $this->assertStringContainsString('at offset '.$exception->position, error_get_last()['message'] ?? '');
        }
    }

    /**
     * @return iterable<string, array{pattern: string, name: string, start: int, code: ErrorCode, pcreMessage: string}>
     */
    public static function provideRefusedNames(): iterable
    {
        yield 'a setting given an argument' => ['pattern' => '(*UTF:x)', 'name' => 'UTF:x', 'start' => 0, 'code' => ErrorCode::VerbInvalid, 'pcreMessage' => '(*VERB) not recognized'];
        yield 'a setting given an argument after text' => ['pattern' => 'a(*UTF:x)', 'name' => 'UTF:x', 'start' => 1, 'code' => ErrorCode::VerbInvalid, 'pcreMessage' => '(*VERB) not recognized'];
        yield 'a name longer than a group name' => ['pattern' => '(*'.str_repeat('A', 300).')', 'name' => str_repeat('A', 300), 'start' => 0, 'code' => ErrorCode::GroupNameTooLong, 'pcreMessage' => 'name is too long'];
    }

    #[Test]
    public function test_a_known_verb_is_left_alone(): void
    {
        $this->assertSame(0, preg_match('/(*MARK:x)a/', ''));

        VerbNames::refuse(self::text('(*MARK:x)a'), 'MARK:x', 0, [], false);
        VerbNames::refuse(self::text('(*:x)a'), '', 0, [], false);
    }

    /**
     * After "(?<" PCRE wants a name: the verb there is no verb, and is not
     * judged as one.
     */
    #[Test]
    public function test_a_name_where_a_group_name_is_due_is_not_judged_as_a_verb(): void
    {
        $text = self::text('(?<(*UTF:x)');

        $this->assertTrue(VerbNames::followsNameOpener($text, 3));
        $this->assertTrue(VerbNames::followsNameOpener(self::text('(?P=ab(*'), 6));
        $this->assertTrue(VerbNames::followsNameOpener(self::text('(?((*'), 3));
        $this->assertFalse(VerbNames::followsNameOpener(self::text('a(*'), 1));

        VerbNames::refuse($text, 'UTF:x', 3, [], false);
    }

    #[Test]
    public function test_the_condition_is_due_right_after_its_opener_or_its_callout(): void
    {
        $opener = new Token(TokenType::GroupModifierOpen, '(?', 0, 2);
        $callout = new Token(TokenType::Callout, '1', 2, 5);

        $this->assertTrue(VerbNames::opensCondition([$opener], 2));
        $this->assertTrue(VerbNames::opensCondition([$opener, $callout], 7));
        $this->assertFalse(VerbNames::opensCondition([$opener], 3));
        $this->assertFalse(VerbNames::opensCondition([], 0));
        $this->assertFalse(VerbNames::opensCondition([new Token(TokenType::GroupOpen, '(', 0, 1)], 1));
    }

    /**
     * PCRE reads comments, "\E" and "\Q\E" as nothing, and under "x" white
     * space and line comments too.
     */
    #[Test]
    #[DataProvider('provideNothingRead')]
    public function test_what_pcre_reads_as_nothing_is_skipped_backwards(string $pattern, int $position, bool $extended, int $expected): void
    {
        $this->assertSame($expected, VerbNames::nothingReadBefore(self::text($pattern), $position, $extended));
    }

    /**
     * @return iterable<string, array{pattern: string, position: int, extended: bool, expected: int}>
     */
    public static function provideNothingRead(): iterable
    {
        yield 'a comment' => ['pattern' => 'a(?#c)(*X)', 'position' => 6, 'extended' => false, 'expected' => 1];
        yield 'an empty quote and an end of quote' => ['pattern' => 'a\Q\E\E(*X)', 'position' => 7, 'extended' => false, 'expected' => 1];
        yield 'spaces under x' => ['pattern' => 'a  (*X)', 'position' => 3, 'extended' => true, 'expected' => 1];
        yield 'spaces without x' => ['pattern' => 'a  (*X)', 'position' => 3, 'extended' => false, 'expected' => 3];
        yield 'a line comment under x' => ['pattern' => "a#c\n(*X)", 'position' => 4, 'extended' => true, 'expected' => 1];
        yield 'an escaped E' => ['pattern' => 'a\\\\E(*X)', 'position' => 4, 'extended' => false, 'expected' => 4];
        yield 'the start' => ['pattern' => '(*X)', 'position' => 0, 'extended' => true, 'expected' => 0];
    }

    /**
     * PCRE2 before 10.33 reads a verb name as at most 32 letters, and
     * refuses the 33rd; from 10.33 a name is held to the length of a group
     * name.
     */
    #[Test]
    public function test_a_long_name_is_refused_by_the_rule_of_its_release(): void
    {
        $old = new PatternText(new TargetSyntax(PcreTarget::bundledWith(70300)), '(*'.str_repeat('A', 40).')', '', '(*'.str_repeat('A', 40).')', 0);

        try {
            VerbNames::refuseLongLetterRun($old, 40, 0);
            $this->fail('A name of 40 letters was read by PCRE2 10.32.');
        } catch (LexerException $exception) {
            $this->assertSame(ErrorCode::GroupNameTooLong, $exception->errorCode);
            $this->assertSame(35, $exception->position);
        }

        VerbNames::refuseLongLetterRun($old, 32, 0);
        VerbNames::refuseTooLong(self::text('(*'.str_repeat('A', 128).')'), 128, 0);
    }

    #[Test]
    public function test_a_condition_that_wants_a_name_is_refused_as_an_invalid_condition(): void
    {
        $exception = VerbNames::conditionWantsAName(3, '(?(*x)');

        $this->assertSame(ErrorCode::ConditionalInvalid, $exception->errorCode);
        $this->assertSame(3, $exception->position);
        $this->assertSame('Invalid conditional construct at position 3. Condition must be a group reference, lookaround, or (DEFINE).', $exception->getMessage());
    }

    private static function text(string $pattern): PatternText
    {
        return new PatternText(new TargetSyntax(PcreTarget::runtime()), $pattern, '', $pattern, 0);
    }
}
