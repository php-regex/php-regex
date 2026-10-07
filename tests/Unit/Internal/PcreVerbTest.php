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

namespace PHPRegex\Tests\Unit\Internal;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Internal\PcreVerb;
use PHPRegex\Parser\Node\GroupType;
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
        yield 'as the condition' => ['pattern' => '/(?(**)a)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 3];
        yield 'as the condition after a callout' => ['pattern' => '/(?(?C1)(**a)b)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 7];
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
