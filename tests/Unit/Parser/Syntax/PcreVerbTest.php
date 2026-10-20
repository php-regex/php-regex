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

namespace PHPRegex\Tests\Unit\Parser\Syntax;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\PcreVerb;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Telling apart the four things "(*...)" can hold.
 */
final class PcreVerbTest extends TestCase
{
    #[Test]
    #[DataProvider('provideBacktrackingVerbs')]
    public function test_a_backtracking_verb_is_kept_as_it_is(string $text, string $name): void
    {
        $verb = PcreVerb::read($text);

        $this->assertSame($name, $verb->name);
        $this->assertNotInstanceOf(GroupType::class, $verb->assertion);
        $this->assertNull($verb->matchLimit);
        $this->assertFalse($verb->isScriptRun());
    }

    /**
     * @return iterable<string, array{text: string, name: string}>
     */
    public static function provideBacktrackingVerbs(): iterable
    {
        yield 'fail' => ['text' => 'FAIL', 'name' => 'FAIL'];
        yield 'skip' => ['text' => 'SKIP', 'name' => 'SKIP'];
        yield 'a named mark' => ['text' => 'MARK:here', 'name' => 'MARK:here'];

        // "(*:name)" and "(*=name)" are shorthands for a mark.
        yield 'a mark written short' => ['text' => ':here', 'name' => 'MARK:here'];
        yield 'a mark written with an equals sign' => ['text' => '=here', 'name' => 'MARK=here'];

        // PCRE knows assertions and script runs in lowercase only: any other
        // spelling is a verb it does not know.
        yield 'a lookahead not in lowercase' => ['text' => 'PLA:foo', 'name' => 'PLA:foo'];
        yield 'a script run not in lowercase' => ['text' => 'SR:foo', 'name' => 'SR:foo'];
    }

    #[Test]
    #[DataProvider('provideAssertions')]
    public function test_an_alphabetic_assertion_stands_for_a_group(string $text, GroupType $group, string $payload): void
    {
        $verb = PcreVerb::read($text);

        $this->assertSame($group, $verb->assertion);
        $this->assertSame($payload, $verb->payload);
    }

    /**
     * @return iterable<string, array{text: string, group: GroupType, payload: string}>
     */
    public static function provideAssertions(): iterable
    {
        yield 'a lookahead, short' => [
            'text' => 'pla:foo',
            'group' => GroupType::LookaheadPositive,
            'payload' => 'foo',
        ];
        yield 'a lookahead, spelled out' => [
            'text' => 'positive_lookahead:foo',
            'group' => GroupType::LookaheadPositive,
            'payload' => 'foo',
        ];
        yield 'a negative lookbehind' => [
            'text' => 'nlb:foo',
            'group' => GroupType::LookbehindNegative,
            'payload' => 'foo',
        ];
        yield 'an atomic group' => [
            'text' => 'atomic:a+',
            'group' => GroupType::Atomic,
            'payload' => 'a+',
        ];
    }

    #[Test]
    public function test_a_match_limit_carries_a_number(): void
    {
        $verb = PcreVerb::read('LIMIT_MATCH=4096');

        $this->assertSame(4096, $verb->matchLimit);
        $this->assertNotInstanceOf(GroupType::class, $verb->assertion);
    }

    #[Test]
    #[DataProvider('provideScriptRuns')]
    public function test_a_script_run_carries_a_sub_pattern(string $text, string $payload): void
    {
        $verb = PcreVerb::read($text);

        $this->assertTrue($verb->isScriptRun());
        $this->assertSame($payload, $verb->payload);
    }

    /**
     * @return iterable<string, array{text: string, payload: string}>
     */
    public static function provideScriptRuns(): iterable
    {
        yield 'spelled out' => ['text' => 'script_run:\d+', 'payload' => '\d+'];
        yield 'short' => ['text' => 'sr:\d+', 'payload' => '\d+'];
    }

    #[Test]
    public function test_an_atomic_script_run_carries_an_atomic_sub_pattern(): void
    {
        foreach (['asr:\\d+', 'atomic_script_run:\\d+'] as $text) {
            $verb = PcreVerb::read($text);

            $this->assertTrue($verb->isScriptRun(), $text);
            $this->assertTrue($verb->atomicScriptRun, $text);
            $this->assertSame('\\d+', $verb->payload, $text);
        }

        $this->assertFalse(PcreVerb::read('sr:\\d+')->atomicScriptRun);
    }

    #[Test]
    public function test_a_script_run_takes_an_argument_in_lowercase_only(): void
    {
        foreach (['sr', 'script_run', 'asr', 'atomic_script_run'] as $name) {
            $this->assertTrue(PcreVerb::takesArgument($name), $name);
        }

        $this->assertFalse(PcreVerb::takesArgument('SR'));
        $this->assertFalse(PcreVerb::takesArgument('ASR'));
    }

    /**
     * "(**" opens no verb: a second "*" is no verb name, and "(?*" is the
     * only short spelling of a lookahead. PCRE refuses each one at the
     * first "*" ("(*VERB) not recognized or malformed"), read here from the
     * running engine.
     */
    #[Test]
    #[DataProvider('provideDoubleStars')]
    public function test_validate_refuses_a_double_star_where_pcre_does(string $pattern, ErrorCode $code, int $offset): void
    {
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
        $this->assertSame(['message' => '(*VERB) not recognized or malformed', 'offset' => $offset], $pcre, 'Oracle: '.$pattern);
        $this->assertContains($code->value, PcreMessageCodes::CODES[$pcre['message']], 'Oracle: '.$pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertSame([false, $code, $offset], [$result->isValid, $result->errorCode, $result->offset], \sprintf('%s: %s', $pattern, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideDoubleStars(): iterable
    {
        yield 'nothing after the stars' => ['pattern' => '~(**)~', 'code' => ErrorCode::VerbInvalid, 'offset' => 2];
        yield 'a letter after the stars' => ['pattern' => '~(**a)~', 'code' => ErrorCode::VerbInvalid, 'offset' => 2];
        yield 'a mark after the stars' => ['pattern' => '~(**MARK:a)~', 'code' => ErrorCode::VerbInvalid, 'offset' => 2];
        yield 'a short mark after the stars' => ['pattern' => '~(**:a)~', 'code' => ErrorCode::VerbInvalid, 'offset' => 2];
        yield 'after a newline verb' => ['pattern' => '~(*CR)(**a)~', 'code' => ErrorCode::VerbInvalid, 'offset' => 7];
        yield 'after two newline verbs' => ['pattern' => '~(*CR)(*CR)(**)~', 'code' => ErrorCode::VerbInvalid, 'offset' => 12];
        yield 'after (*UTF)' => ['pattern' => '~(*UTF)(**)~', 'code' => ErrorCode::VerbInvalid, 'offset' => 8];
        yield 'after a literal' => ['pattern' => '~a(**)~', 'code' => ErrorCode::VerbInvalid, 'offset' => 3];
        yield 'inside a lookahead' => ['pattern' => '~(?=(**))~', 'code' => ErrorCode::VerbInvalid, 'offset' => 5];
        yield 'inside an alphabetic lookahead' => ['pattern' => '~(*pla:(**))~', 'code' => ErrorCode::VerbInvalid, 'offset' => 8];
        yield 'a closed quote after the stars under x' => ['pattern' => '~(*CR)(**\Qa\E)~x', 'code' => ErrorCode::VerbInvalid, 'offset' => 7];
        yield 'a quoted parenthesis after the stars under x' => ['pattern' => '~(*CR)(**\Q)\E)~x', 'code' => ErrorCode::VerbInvalid, 'offset' => 7];
        yield 'a quoted parenthesis after the stars' => ['pattern' => '~(**\Q)\E)~', 'code' => ErrorCode::VerbInvalid, 'offset' => 2];
        yield 'a quote holding the parenthesis to the end under x' => ['pattern' => '~(*CR)(**\Q)~x', 'code' => ErrorCode::VerbInvalid, 'offset' => 7];
        yield 'an x comment holding a parenthesis after the stars' => ['pattern' => "~(*CR)(**#)\n)~x", 'code' => ErrorCode::VerbInvalid, 'offset' => 7];
        // Refused at PCRE's offset already, but as a verb left open: "(**"
        // is refused for what it is, whatever follows it.
        yield 'stars that never close' => ['pattern' => '~(**~', 'code' => ErrorCode::VerbInvalid, 'offset' => 2];
        yield 'a quote running to the end after the stars under x' => ['pattern' => '~(*CR)(**\Q…~x', 'code' => ErrorCode::VerbInvalid, 'offset' => 7];
    }

    /**
     * PCRE reads the pattern in one pass: an error it meets before the "(**"
     * is the one it reports. After "(?(", "(?<", "(?'", "(?P<", "(?&",
     * "(?P>", "(?P=" and the name forms of a condition, the "(" of "(**"
     * cannot start what PCRE expects there; a relative reference to no
     * group or a reversed count comes earlier still.
     *
     * The library agrees with the running engine on every release, the
     * offset and a code its message allows; the row holds what PCRE2 10.49
     * reports, checked against the engine when it is 10.49.
     */
    #[Test]
    #[DataProvider('provideErrorsBeforeADoubleStar')]
    public function test_validate_reports_the_error_pcre_meets_before_a_double_star(string $pattern, ErrorCode $code, int $offset): void
    {
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
        $this->assertArrayHasKey($pcre['message'], PcreMessageCodes::CODES, \sprintf('Oracle: %s, "%s" is not a message the code map knows.', $pattern, $pcre['message']));
        $allowed = PcreMessageCodes::CODES[$pcre['message']];
        $pinned = '10.49' === self::runningRelease();
        if ($pinned) {
            $this->assertSame($offset, $pcre['offset'], \sprintf('Oracle: %s (%s).', $pattern, $pcre['message']));
            $this->assertContains($code->value, $allowed, \sprintf('Oracle: %s does not name "%s".', $code->value, $pcre['message']));
        }

        $result = Regex::create(['cache' => null])->validate($pattern);
        $said = \sprintf('%s: PCRE says "%s" at %s, the library "%s" (%s) at %s.', $pattern, $pcre['message'], var_export($pcre['offset'], true), (string) $result->error, $result->errorCode?->value, var_export($result->offset, true));

        $this->assertFalse($result->isValid, $said);
        $this->assertSame($pcre['offset'], $result->offset, $said);
        $this->assertContains($result->errorCode?->value, $allowed, $said);
        if ($pinned) {
            $this->assertSame($code, $result->errorCode, $said);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideErrorsBeforeADoubleStar(): iterable
    {
        // PCRE: "atomic assertion expected after (?( or (?(?C)".
        yield 'as the condition' => ['pattern' => '/(?(**)a)/', 'code' => ErrorCode::ConditionalAssertionExpected, 'offset' => 3];
        yield 'as the condition after a callout' => ['pattern' => '/(?(?C1)(**a)b)/', 'code' => ErrorCode::ConditionalAssertionExpected, 'offset' => 7];
        // PCRE: "subpattern name expected", at the "(".
        yield 'in a group read as the condition' => ['pattern' => '/(?((**a)b)/', 'code' => ErrorCode::ConditionalInvalid, 'offset' => 3];
        yield 'as a group name in angle brackets' => ['pattern' => '/(?<(**>a)/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 3];
        yield 'as a group name in quotes' => ['pattern' => "/(?'(**')/", 'code' => ErrorCode::GroupNameExpected, 'offset' => 3];
        yield 'as a group name after (?P<' => ['pattern' => '/(?P<(**>a)/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 4];
        yield 'as a called name' => ['pattern' => '/(?&(**)/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 3];
        yield 'as a name called by (?P>' => ['pattern' => '/(?P>(**)/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 4];
        yield 'as a name referenced by (?P=' => ['pattern' => '/(?P=(**)/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 4];
        yield 'as a condition name in angle brackets' => ['pattern' => '/(?(<(**>)a)/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 4];
        yield 'as a condition name in quotes' => ['pattern' => "/(?('(**')a)/", 'code' => ErrorCode::GroupNameExpected, 'offset' => 4];
        yield 'as a recursion condition name' => ['pattern' => '/(?(R&(**)a)/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 5];
        // PCRE: "reference to non-existent subpattern".
        yield 'after a relative reference to no group' => ['pattern' => '/\\g{-1}(**)/', 'code' => ErrorCode::BackrefRelative, 'offset' => 2];
        // PCRE: "numbers out of order in {} quantifier".
        yield 'after a reversed count' => ['pattern' => '/a{3,2}(**)/', 'code' => ErrorCode::QuantifierInvalidRange, 'offset' => 5];
        // Not "(**" but the same order: an unclosed "(*" after the reference.
        yield 'an unclosed opener after a relative reference to no group' => ['pattern' => '/\\g{-1}(*/', 'code' => ErrorCode::BackrefRelative, 'offset' => 2];
        // Reported where PCRE reports it already: kept as a guard.
        yield 'a misnamed verb after a reversed count' => ['pattern' => '/a{3,2}(*FOO)/', 'code' => ErrorCode::QuantifierInvalidRange, 'offset' => 5];
    }

    /**
     * The message of a "(**" refusal names its position in the whole
     * pattern, as the offset does, in an alphabetic assertion's body as
     * anywhere: the body is read on its own, but the position it reports is
     * the pattern's (PCRE: "(*VERB) not recognized or malformed" at that
     * offset).
     */
    #[Test]
    #[DataProvider('provideDoubleStarsInBodies')]
    public function test_the_double_star_message_names_its_position_in_the_pattern(string $pattern, int $position): void
    {
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
        $this->assertSame(['message' => '(*VERB) not recognized or malformed', 'offset' => $position], $pcre, 'Oracle: '.$pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertSame([false, ErrorCode::VerbInvalid, $position], [$result->isValid, $result->errorCode, $result->offset], \sprintf('%s: %s', $pattern, (string) $result->error));
        $this->assertMatchesRegularExpression(\sprintf('/\bposition %d\b/', $position), (string) $result->error, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, position: int}>
     */
    public static function provideDoubleStarsInBodies(): iterable
    {
        yield 'first in an alphabetic lookahead' => ['pattern' => '/(*pla:(**))/', 'position' => 8];
        yield 'first in an alphabetic lookahead after text' => ['pattern' => '/xxxxxxxx(*pla:(**))/', 'position' => 16];
        yield 'after a letter in an alphabetic lookahead' => ['pattern' => '/(*pla:a(**))/', 'position' => 9];
        yield 'in a short lookahead' => ['pattern' => '/(?*(**))/', 'position' => 5];
        yield 'in a nested alphabetic lookahead' => ['pattern' => '/(*pla:(*pla:(**)))/', 'position' => 14];
        // Named where PCRE reports it already: kept as guards.
        yield 'after a letter' => ['pattern' => '/a(**)/', 'position' => 3];
        yield 'in a lookahead spelled (?=' => ['pattern' => '/(?=(**))/', 'position' => 5];
    }

    /**
     * The name of "(*name:" standing at an offset, made of the letters
     * given, a ":" right after them; null for anything else.
     */
    #[Test]
    #[DataProvider('provideNamesBeforeAColon')]
    public function test_the_name_of_an_opener_is_read_up_to_its_colon(string $pattern, int $at, string $letters, ?string $name): void
    {
        $this->assertSame($name, PcreVerb::nameBeforeColonAt($pattern, $at, $letters));
    }

    /**
     * @return iterable<string, array{pattern: string, at: int, letters: string, name: string|null}>
     */
    public static function provideNamesBeforeAColon(): iterable
    {
        yield 'alphabetic lookahead' => ['pattern' => '(*pla:a)', 'at' => 0, 'letters' => PcreVerb::LOWERCASE_NAME, 'name' => 'pla'];
        yield 'name with an underscore, further on' => ['pattern' => 'ab(*positive_lookbehind:', 'at' => 2, 'letters' => PcreVerb::LOWERCASE_NAME, 'name' => 'positive_lookbehind'];
        yield 'capitals are no alphabetic name' => ['pattern' => '(*MARK:a)', 'at' => 0, 'letters' => PcreVerb::LOWERCASE_NAME, 'name' => null];
        yield 'capitals and digits are word characters' => ['pattern' => '(*MARK1:a)', 'at' => 0, 'letters' => PcreVerb::WORD_NAME, 'name' => 'MARK1'];
        yield 'no colon after the name' => ['pattern' => '(*pla)', 'at' => 0, 'letters' => PcreVerb::LOWERCASE_NAME, 'name' => null];
        yield 'the pattern ends with the name' => ['pattern' => '(*pla', 'at' => 0, 'letters' => PcreVerb::LOWERCASE_NAME, 'name' => null];
        yield 'no name before the colon' => ['pattern' => '(*:a)', 'at' => 0, 'letters' => PcreVerb::LOWERCASE_NAME, 'name' => null];
        yield 'the pattern ends with the opener' => ['pattern' => 'a(*', 'at' => 1, 'letters' => PcreVerb::LOWERCASE_NAME, 'name' => null];
        yield 'a group opener' => ['pattern' => '(?=a)', 'at' => 0, 'letters' => PcreVerb::LOWERCASE_NAME, 'name' => null];
        yield 'not on a parenthesis' => ['pattern' => 'a(*pla:', 'at' => 0, 'letters' => PcreVerb::LOWERCASE_NAME, 'name' => null];
        yield 'past the end' => ['pattern' => '(*pla:', 'at' => 6, 'letters' => PcreVerb::LOWERCASE_NAME, 'name' => null];
    }

    /**
     * Where PCRE reports a "(*" no ")" closes: where its name ends, at the
     * end of the pattern after a name that takes ":", past the character
     * after an alphabetic name from PCRE2 10.47.
     */
    #[Test]
    #[DataProvider('provideUnclosedVerbs')]
    public function test_unclosed_offset_is_where_pcre_stops_reading_the_verb(string $pattern, int $start, string $release, bool $utf, int $offset): void
    {
        $this->assertSame($offset, PcreVerb::unclosedOffset($pattern, $start, new PcreTarget(80400, $release), $utf));
    }

    /**
     * @return iterable<string, array{pattern: string, start: int, release: string, utf: bool, offset: int}>
     */
    public static function provideUnclosedVerbs(): iterable
    {
        // PCRE2 10.49: "/(*MARK:a/" at offset 8, "/(*foo/" at 5,
        // "/(*LIMIT_MATCH=1x/" at 15, "/a(*LIMIT_MATCH=1x/" at 14,
        // "/(*fooé/u" at 7 and "/(*fooé/" at 6.
        yield 'a name that takes an argument' => ['pattern' => '(*MARK:a', 'start' => 0, 'release' => '10.49', 'utf' => false, 'offset' => 8];
        yield 'a name the pattern ends in' => ['pattern' => '(*foo', 'start' => 0, 'release' => '10.49', 'utf' => false, 'offset' => 5];
        yield 'a malformed limit' => ['pattern' => '(*LIMIT_MATCH=1x', 'start' => 0, 'release' => '10.49', 'utf' => false, 'offset' => 15];
        yield 'a malformed limit, before 10.45' => ['pattern' => '(*LIMIT_MATCH=1x', 'start' => 0, 'release' => '10.44', 'utf' => false, 'offset' => 16];
        yield 'a limit past the start of the pattern' => ['pattern' => 'a(*LIMIT_MATCH=1x', 'start' => 1, 'release' => '10.49', 'utf' => false, 'offset' => 14];
        yield 'a two-byte character after an alphabetic name' => ['pattern' => '(*fooé', 'start' => 0, 'release' => '10.49', 'utf' => true, 'offset' => 7];
        yield 'one byte of it without UTF' => ['pattern' => '(*fooé', 'start' => 0, 'release' => '10.49', 'utf' => false, 'offset' => 6];
        yield 'where the name ends, before 10.47' => ['pattern' => '(*fooé', 'start' => 0, 'release' => '10.44', 'utf' => true, 'offset' => 5];
        yield 'a capital name and a character after it' => ['pattern' => '(*FOO!', 'start' => 0, 'release' => '10.49', 'utf' => false, 'offset' => 5];
        yield 'an alphabetic name and a colon' => ['pattern' => '(*foo:a', 'start' => 0, 'release' => '10.49', 'utf' => false, 'offset' => 5];
        // "/(*/": the "*" repeats nothing, past it from 10.47.
        yield 'no name at the end' => ['pattern' => '(*', 'start' => 0, 'release' => '10.49', 'utf' => false, 'offset' => 2];
        yield 'no name at the end, before 10.47' => ['pattern' => '(*', 'start' => 0, 'release' => '10.44', 'utf' => false, 'offset' => 1];
        // Before PCRE2 10.33 the name is a run of letters.
        yield 'a digit ends the name before 10.33' => ['pattern' => '(*ab1', 'start' => 0, 'release' => '10.32', 'utf' => false, 'offset' => 4];
        yield 'a digit is in the name from 10.33' => ['pattern' => '(*ab1', 'start' => 0, 'release' => '10.33', 'utf' => false, 'offset' => 5];
    }

    #[Test]
    #[DataProvider('provideUnclosedNames')]
    public function test_unclosed_name_error_refuses_a_name_pcre_cannot_read(string $name, string $release, ?ErrorCode $code, ?int $offset): void
    {
        $error = PcreVerb::unclosedNameError('(*'.$name, 0, new PcreTarget(80400, $release));

        $this->assertSame([$code, $offset], [$error?->errorCode, $error?->position]);
    }

    /**
     * @return iterable<string, array{name: string, release: string, code: ErrorCode|null, offset: int|null}>
     */
    public static function provideUnclosedNames(): iterable
    {
        // PCRE2 10.49: 129 letters after "(*" are refused at offset 131, 128
        // are read.
        yield 'one character too long' => ['name' => str_repeat('a', 129), 'release' => '10.49', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 131];
        yield 'the longest name' => ['name' => str_repeat('a', 128), 'release' => '10.49', 'code' => null, 'offset' => null];
        yield 'digits count' => ['name' => str_repeat('a', 120).'012345678', 'release' => '10.49', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 131];
        yield 'too long before 10.44' => ['name' => str_repeat('a', 33), 'release' => '10.43', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 35];
        yield 'the longest name before 10.44' => ['name' => str_repeat('a', 32), 'release' => '10.43', 'code' => null, 'offset' => null];
        yield 'a digit first before 10.33' => ['name' => '1a', 'release' => '10.32', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 2];
        yield 'a digit first from 10.33' => ['name' => '1a', 'release' => '10.33', 'code' => null, 'offset' => null];
        yield 'too many letters before 10.33' => ['name' => str_repeat('a', 33), 'release' => '10.32', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 35];
        yield 'letters and a digit before 10.33' => ['name' => str_repeat('a', 32).'1', 'release' => '10.32', 'code' => null, 'offset' => null];
    }

    #[Test]
    public function test_unclosed_name_error_names_the_limit(): void
    {
        $error = PcreVerb::unclosedNameError('(*'.str_repeat('a', 129), 0, new PcreTarget(80400, '10.49'));

        $this->assertSame('Verb name too long at position 131: PCRE takes at most 128 characters.', $error?->getMessage());
    }

    #[Test]
    public function test_double_star_error_is_refused_at_the_second_star(): void
    {
        $error = PcreVerb::doubleStarError('(**)', 2);

        $this->assertSame(ErrorCode::VerbInvalid, $error->errorCode);
        $this->assertSame(2, $error->position);
        $this->assertSame('Unknown verb at position 2: "(**" opens no verb, PCRE wants a name after "(*".', $error->getMessage());
    }

    #[Test]
    public function test_a_script_run_with_nothing_in_it_is_a_plain_verb(): void
    {
        $verb = PcreVerb::read('sr:');

        $this->assertFalse($verb->isScriptRun());
        $this->assertSame('sr:', $verb->name);
    }

    /**
     * The major.minor release of the PCRE2 the running PHP links.
     */
    private static function runningRelease(): string
    {
        return implode('.', \array_slice(explode('.', explode(' ', \PCRE_VERSION)[0]), 0, 2));
    }
}
