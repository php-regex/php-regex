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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Explain\AsciiTreeRenderer;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(?(?C1)(?=a)...)": a callout may come before the assertion of a
 * condition. PCRE skips a comment, an empty "\Q\E" and, under "x",
 * whitespace between the two, as it does anywhere (PHP compiles each).
 */
final class CalloutConditionTest extends TestCase
{
    #[Test]
    #[DataProvider('provideAccepted')]
    public function test_what_pcre_skips_may_stand_between_the_callout_and_the_assertion(string $pattern): void
    {
        $this->assertSame(1, @preg_match($pattern, 'ab'), $pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);
        $this->assertTrue($result->isValid, \sprintf('%s: %s', $pattern, $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAccepted(): iterable
    {
        yield 'comment' => ['pattern' => '/(?(?C1)(?#c)(?=a)ab)/'];
        yield 'whitespace under x' => ['pattern' => '/(?(?C1) (?=a)ab)/x'];
        yield 'comment line under x' => ['pattern' => "/(?(?C1)#c\n(?=a)ab)/x"];
        yield 'empty quote' => ['pattern' => '/(?(?C1)\\Q\\E(?=a)ab)/'];
    }

    /**
     * The assertion after the callout may be spelled as a verb: PCRE reads
     * "(*pla:a)" there as "(?=a)". The tree is the one of the "(?=" spelling,
     * and the engine, the "(?=" spelling and the pattern the library prints
     * back agree on every subject.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideAlphabeticAssertionsAfterACallout')]
    public function test_an_alphabetic_lookaround_may_follow_the_callout_in_a_condition(string $pattern, string $lookaround, array $subjects): void
    {
        foreach ($subjects as $subject) {
            $this->assertSame($this->matchCount($lookaround, $subject), $this->matchCount($pattern, $subject), \sprintf('Oracle: %s and %s on %s.', $pattern, $lookaround, json_encode($subject)));
        }

        $regex = Regex::create(['cache' => null]);
        $result = $regex->validate($pattern);
        $this->assertTrue($result->isValid, \sprintf('%s: %s', $pattern, (string) $result->error));

        $tree = static fn (string $source): string => $regex->parse($source)->accept(new AsciiTreeRenderer());
        $this->assertSame($tree($lookaround), $tree($pattern), $pattern);

        $printed = $regex->parse($pattern)->accept(new PatternPrinter());
        foreach ($subjects as $subject) {
            $this->assertSame($this->matchCount($pattern, $subject), $this->matchCount($printed, $subject), \sprintf('%s printed as %s, which disagrees on %s.', $pattern, $printed, json_encode($subject)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, lookaround: string, subjects: list<string>}>
     */
    public static function provideAlphabeticAssertionsAfterACallout(): iterable
    {
        yield 'positive lookahead' => ['pattern' => '/^(?(?C1)(*pla:a)ab|cd)$/', 'lookaround' => '/^(?(?C1)(?=a)ab|cd)$/', 'subjects' => ['ab', 'cd', 'ad', 'cb']];
        yield 'long name' => ['pattern' => '/^(?(?C1)(*positive_lookahead:a)ab|cd)$/', 'lookaround' => '/^(?(?C1)(?=a)ab|cd)$/', 'subjects' => ['ab', 'cd', 'ad']];
        yield 'negative lookahead' => ['pattern' => '/^(?(?C1)(*nla:a)cd|ab)$/', 'lookaround' => '/^(?(?C1)(?!a)cd|ab)$/', 'subjects' => ['ab', 'cd', 'ad']];
        yield 'positive lookbehind' => ['pattern' => '/x(?(?C1)(*plb:x)ab|cd)$/', 'lookaround' => '/x(?(?C1)(?<=x)ab|cd)$/', 'subjects' => ['xab', 'xcd']];
        yield 'negative lookbehind' => ['pattern' => '/^(?(?C1)(*nlb:c)ab|cd)$/', 'lookaround' => '/^(?(?C1)(?<!c)ab|cd)$/', 'subjects' => ['ab', 'cd']];
        yield 'string callout' => ['pattern' => '/^(?(?C"x")(*pla:a)ab|cd)$/', 'lookaround' => '/^(?(?C"x")(?=a)ab|cd)$/', 'subjects' => ['ab', 'cd']];
        yield 'comment between the two' => ['pattern' => '/^(?(?C1)(?#c)(*pla:a)ab|cd)$/', 'lookaround' => '/^(?(?C1)(?#c)(?=a)ab|cd)$/', 'subjects' => ['ab', 'cd']];
        yield 'whitespace between the two under x' => ['pattern' => '/^(?(?C1) (*pla:a)ab|cd)$/x', 'lookaround' => '/^(?(?C1) (?=a)ab|cd)$/x', 'subjects' => ['ab', 'cd']];
        yield 'empty body' => ['pattern' => '/^(?(?C1)(*pla:)ab|cd)$/', 'lookaround' => '/^(?(?C1)(?=)ab|cd)$/', 'subjects' => ['ab', 'cd']];
        yield 'body holding another body' => ['pattern' => '/^(?(?C1)(*pla:(*pla:a))ab|cd)$/', 'lookaround' => '/^(?(?C1)(?=(?=a))ab|cd)$/', 'subjects' => ['ab', 'cd']];
        yield 'body holding a callout' => ['pattern' => '/^(?(?C1)(*pla:a(?C2))ab|cd)$/', 'lookaround' => '/^(?(?C1)(?=a(?C2))ab|cd)$/', 'subjects' => ['ab', 'cd']];
    }

    /**
     * What follows the callout is refused as PCRE refuses it: an atomic
     * group, a script run, a non-atomic lookaround or a verb is no
     * assertion there ("atomic assertion expected", at the colon of a named
     * one, at the opener of any other), an unknown name is no alphabetic
     * assertion, and an error inside a lookaround body is that error. The
     * offset is the running engine's; the code is one its message allows.
     */
    #[Test]
    #[DataProvider('provideRefusedAfterACallout')]
    public function test_validate_refuses_what_is_no_lookaround_after_the_callout_where_pcre_does(string $pattern, ErrorCode $code, int $offset): void
    {
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
        $this->assertSame($offset, $pcre['offset'], \sprintf('Oracle: %s (%s).', $pattern, $pcre['message']));
        $this->assertContains($code->value, PcreMessageCodes::CODES[$pcre['message']] ?? [], \sprintf('Oracle: %s does not name "%s".', $code->value, $pcre['message']));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame([$code, $offset], [$result->errorCode, $result->offset], \sprintf('%s: PCRE says "%s" at %d, the library "%s".', $pattern, $pcre['message'], $offset, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideRefusedAfterACallout(): iterable
    {
        // PCRE: "missing terminating ] for character class".
        yield 'class left open in the lookahead body' => ['pattern' => '/(?(?C1)(*pla:[a)b)/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 18];
        // PCRE: "atomic assertion expected after (?( or (?(?C)", at the colon.
        yield 'non-atomic lookahead' => ['pattern' => '/(?(?C1)(*napla:a)b)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 14];
        yield 'non-atomic lookbehind' => ['pattern' => '/(?(?C1)(*naplb:a)b)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 14];
        yield 'atomic group' => ['pattern' => '/(?(?C1)(*atomic:a)b)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 15];
        yield 'short script run' => ['pattern' => '/(?(?C1)(*sr:a)b)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 11];
        yield 'script run' => ['pattern' => '/(?(?C1)(*script_run:a)b)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 19];
        // PCRE: "(*alpha_assertion) not recognized", at the colon.
        yield 'unknown name' => ['pattern' => '/(?(?C1)(*foo:a)b)/', 'code' => ErrorCode::VerbInvalid, 'offset' => 12];
        // PCRE: "conditional subpattern contains more than two branches".
        yield 'three branches after the lookahead' => ['pattern' => '/(?(?C1)(*pla:a)b|c|d)/', 'code' => ErrorCode::ConditionalTooManyBranches, 'offset' => 0];
        // Refused where PCRE refuses them already: kept as guards.
        // PCRE: "missing closing parenthesis", at the end of the pattern.
        yield 'lookahead body never closed' => ['pattern' => '/(?(?C1)(*pla:a/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 14];
        yield 'lookahead body never closed under u' => ['pattern' => '/(?(?C1)(*pla:a/u', 'code' => ErrorCode::GroupUnclosed, 'offset' => 14];
        // PCRE: "atomic assertion expected after (?( or (?(?C)", at the opener.
        yield 'verb' => ['pattern' => '/(?(?C1)(*FAIL)b)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 7];
        yield 'short non-atomic lookahead' => ['pattern' => '/(?(?C1)(?*a)b)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 7];
        yield 'name in capitals' => ['pattern' => '/(?(?C1)(*PLA:a)b)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 7];
        // A plain group is no "(*" either, whatever its text reads like.
        yield 'group holding a letter, then a name and a colon' => ['pattern' => '/(?(?C1)(xfoo:a)b)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 7];
        yield 'group holding a property left open' => ['pattern' => '/(?(?C1)(\\p{/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 7];
        yield 'name in capitals then lowercase never closed' => ['pattern' => '/(?(?C1)(*Xfoo/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 7];
    }

    /**
     * With no callout before it, the condition may be spelled as a verb all
     * the same: "(?(*pla:a)" is "(?(?=a)". The tree is the one of the
     * symbol spelling, and the engine, that spelling and the pattern the
     * library prints back agree on every subject.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideAlphabeticAssertionConditions')]
    public function test_an_alphabetic_lookaround_is_a_condition_without_a_callout(string $pattern, string $lookaround, array $subjects): void
    {
        foreach ($subjects as $subject) {
            $this->assertSame($this->matchCount($lookaround, $subject), $this->matchCount($pattern, $subject), \sprintf('Oracle: %s and %s on %s.', $pattern, $lookaround, json_encode($subject)));
        }

        $regex = Regex::create(['cache' => null]);
        $result = $regex->validate($pattern);
        $this->assertTrue($result->isValid, \sprintf('%s: %s', $pattern, (string) $result->error));

        $tree = static fn (string $source): string => $regex->parse($source)->accept(new AsciiTreeRenderer());
        $this->assertSame($tree($lookaround), $tree($pattern), $pattern);

        $printed = $regex->parse($pattern)->accept(new PatternPrinter());
        foreach ($subjects as $subject) {
            $this->assertSame($this->matchCount($pattern, $subject), $this->matchCount($printed, $subject), \sprintf('%s printed as %s, which disagrees on %s.', $pattern, $printed, json_encode($subject)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, lookaround: string, subjects: list<string>}>
     */
    public static function provideAlphabeticAssertionConditions(): iterable
    {
        yield 'positive lookahead' => ['pattern' => '/^(?(*pla:a)ab|cd)$/', 'lookaround' => '/^(?(?=a)ab|cd)$/', 'subjects' => ['ab', 'cd', 'ad', 'cb', '']];
        yield 'long name' => ['pattern' => '/^(?(*positive_lookahead:a)ab|cd)$/', 'lookaround' => '/^(?(?=a)ab|cd)$/', 'subjects' => ['ab', 'cd', 'ad']];
        yield 'negative lookahead' => ['pattern' => '/^(?(*nla:a)cd|ab)$/', 'lookaround' => '/^(?(?!a)cd|ab)$/', 'subjects' => ['ab', 'cd', 'ad']];
        yield 'long negative name' => ['pattern' => '/^(?(*negative_lookahead:a)cd|ab)$/', 'lookaround' => '/^(?(?!a)cd|ab)$/', 'subjects' => ['ab', 'cd', 'ad']];
        yield 'positive lookbehind' => ['pattern' => '/x(?(*plb:x)ab|cd)$/', 'lookaround' => '/x(?(?<=x)ab|cd)$/', 'subjects' => ['xab', 'xcd', 'ab']];
        yield 'negative lookbehind' => ['pattern' => '/^(?(*nlb:c)ab|cd)$/', 'lookaround' => '/^(?(?<!c)ab|cd)$/', 'subjects' => ['ab', 'cd']];
        yield 'no branch for a failed condition' => ['pattern' => '/^(?(*pla:a)ab)$/', 'lookaround' => '/^(?(?=a)ab)$/', 'subjects' => ['ab', '', 'cd']];
        yield 'empty body' => ['pattern' => '/^(?(*pla:)ab|cd)$/', 'lookaround' => '/^(?(?=)ab|cd)$/', 'subjects' => ['ab', 'cd']];
        yield 'body holding another body' => ['pattern' => '/^(?(*pla:(*nla:b))ab|cd)$/', 'lookaround' => '/^(?(?=(?!b))ab|cd)$/', 'subjects' => ['ab', 'cd', 'bd']];
        yield 'body holding a class with a parenthesis' => ['pattern' => '/^(?(*pla:[)])\)|cd)$/', 'lookaround' => '/^(?(?=[)])\)|cd)$/', 'subjects' => [')', 'cd', 'ab']];
        yield 'multibyte body under u' => ['pattern' => '/^(?(*pla:é)é|cd)$/u', 'lookaround' => '/^(?(?=é)é|cd)$/u', 'subjects' => ['é', 'cd', 'e']];
        yield 'caseless body' => ['pattern' => '/^(?(*pla:A)ab|cd)$/i', 'lookaround' => '/^(?(?=A)ab|cd)$/i', 'subjects' => ['ab', 'AB', 'cd']];
    }

    /**
     * Without a callout, what stands where the condition belongs is refused
     * as PCRE refuses it: an atomic group, a script run, a non-atomic
     * lookaround, a substring scan or a verb is no assertion there ("atomic
     * assertion expected", at the colon of a named one, past the "*" of any
     * other), an unknown name is no alphabetic assertion, and a body that
     * never closes is refused for the same reason, not for its ")": PCRE
     * judges the name before it reads the body.
     *
     * The library agrees with the running engine on every release, the
     * offset and a code its message allows; the row holds what PCRE2 10.49
     * reports, checked against the engine when it is 10.49 (an unnamed
     * opener is refused one place earlier before 10.47, and "(*scs:" is an
     * unknown name before 10.45).
     */
    #[Test]
    #[DataProvider('provideRefusedAsTheCondition')]
    public function test_validate_refuses_what_is_no_lookaround_as_the_condition_where_pcre_does(string $pattern, ErrorCode $code, int $offset): void
    {
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
        $this->assertArrayHasKey($pcre['message'], PcreMessageCodes::CODES, \sprintf('Oracle: %s, "%s" is not a message the code map knows.', $pattern, $pcre['message']));
        if ('10.49' === self::runningRelease()) {
            $this->assertSame($offset, $pcre['offset'], \sprintf('Oracle: %s (%s).', $pattern, $pcre['message']));
            $this->assertContains($code->value, PcreMessageCodes::CODES[$pcre['message']], \sprintf('Oracle: %s does not name "%s".', $code->value, $pcre['message']));
        }

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($pcre['offset'], $result->offset, \sprintf('%s: PCRE says "%s" at %d, the library "%s".', $pattern, $pcre['message'], (int) $pcre['offset'], (string) $result->error));
        $this->assertContains($result->errorCode?->value, PcreMessageCodes::CODES[$pcre['message']], \sprintf('%s: PCRE says "%s", the library "%s".', $pattern, $pcre['message'], (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideRefusedAsTheCondition(): iterable
    {
        // PCRE: "atomic assertion expected after (?( or (?(?C)", at the colon.
        yield 'non-atomic lookahead' => ['pattern' => '/(?(*napla:a)b|c)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 9];
        yield 'non-atomic lookbehind' => ['pattern' => '/(?(*naplb:a)b|c)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 9];
        yield 'atomic group' => ['pattern' => '/(?(*atomic:a)b|c)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 10];
        yield 'short script run' => ['pattern' => '/(?(*sr:a)b|c)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 6];
        yield 'script run' => ['pattern' => '/(?(*script_run:a)b|c)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 14];
        yield 'substring scan' => ['pattern' => '/(?(*scs:(1)a)b)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 7];
        // PCRE: "atomic assertion expected after (?( or (?(?C)", past the "*".
        yield 'short non-atomic lookahead' => ['pattern' => '/(?(?*a)b|c)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 3];
        yield 'verb' => ['pattern' => '/(?(*FAIL)b)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 3];
        yield 'accepting verb' => ['pattern' => '/(?(*ACCEPT)b)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 3];
        yield 'verb with an argument' => ['pattern' => '/(?(*MARK:x)b)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 3];
        yield 'name in capitals' => ['pattern' => '/(?(*PLA:a)b|c)/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 3];
        // PCRE: "(*alpha_assertion) not recognized", at the colon.
        yield 'unknown name' => ['pattern' => '/(?(*foo:a)b|c)/', 'code' => ErrorCode::VerbInvalid, 'offset' => 7];
        // PCRE: "conditional subpattern contains more than two branches".
        yield 'three branches after the lookahead' => ['pattern' => '/(?(*pla:a)b|c|d)/', 'code' => ErrorCode::ConditionalTooManyBranches, 'offset' => 0];
        // PCRE: "missing terminating ] for character class".
        yield 'class left open in the lookahead body' => ['pattern' => '/(?(*pla:[a)b|c)/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 15];
        // A body that never closes: the name is refused, not the ")".
        yield 'atomic group never closed' => ['pattern' => '/(?(*atomic:a/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 10];
        yield 'atomic group with nothing after the colon' => ['pattern' => '/(?(*atomic:/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 10];
        yield 'non-atomic lookahead never closed' => ['pattern' => '/(?(*napla:a/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 9];
        yield 'non-atomic lookbehind never closed' => ['pattern' => '/(?(*naplb:a/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 9];
        yield 'short script run never closed' => ['pattern' => '/(?(*sr:a/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 6];
        yield 'short script run never closed under u' => ['pattern' => '/(?(*sr:a/u', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 6];
        yield 'script run never closed' => ['pattern' => '/(?(*script_run:a/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 14];
        yield 'substring scan never closed' => ['pattern' => '/(?(*scs:(1)a/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 7];
        yield 'short non-atomic lookahead never closed' => ['pattern' => '/(?(?*a/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 3];
        yield 'script run never closed inside an atomic body' => ['pattern' => '/(*atomic:(?(*sr:a/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 15];
        yield 'atomic group never closed after a callout' => ['pattern' => '/(?(?C1)(*atomic:a/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 15];
        yield 'short non-atomic lookahead never closed after a callout' => ['pattern' => '/(?(?C1)(?*a/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 7];
        // PCRE: "(*alpha_assertion) not recognized", at the colon.
        yield 'unknown name never closed' => ['pattern' => '/(?(*foo:a/', 'code' => ErrorCode::VerbInvalid, 'offset' => 7];
        yield 'unknown name never closed after a callout' => ['pattern' => '/(?(?C1)(*foo:a/', 'code' => ErrorCode::VerbInvalid, 'offset' => 12];
        // PCRE: "missing closing parenthesis": a lookahead is read in place.
        yield 'long lookahead name never closed' => ['pattern' => '/(?(*positive_lookahead:a/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 24];
        // After a comment where the condition starts, a "(*" no ")" closes.
        // PCRE: "missing closing parenthesis", at the end, for a lowercase
        // name the pattern ends in.
        yield 'unknown name never closed after a comment' => ['pattern' => '/(?(?#c)(*foo/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 12];
        yield 'lookahead name never closed after a comment' => ['pattern' => '/(?(?#c)(*pla/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 12];
        yield 'short name never closed after a comment' => ['pattern' => '/(?(?#c)(*pl/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 11];
        yield 'unknown name never closed after a comment and x whitespace' => ['pattern' => '/(?(?#c) (*foo/x', 'code' => ErrorCode::GroupUnclosed, 'offset' => 13];
        // PCRE: "atomic assertion expected after (?( or (?(?C)": "(*" cut
        // short, a verb in capitals or a digit is no assertion.
        yield 'opener alone after a comment' => ['pattern' => '/(?(?#c)(*/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 8];
        yield 'one letter never closed after a comment' => ['pattern' => '/(?(?#c)(*p/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 8];
        yield 'verb never closed after a comment' => ['pattern' => '/(?(?#c)(*ACCEPT/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 8];
        yield 'name in capitals never closed after a comment' => ['pattern' => '/(?(?#c)(*FOO/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 8];
        yield 'digit never closed after a comment' => ['pattern' => '/(?(?#c)(*1/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 8];
        // The name is read from its first letter: a capital, a digit or an
        // underscore there opens no name, whatever follows.
        yield 'capital then a lowercase name after a comment' => ['pattern' => '/(?(?#c)(*Xfoo:a/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 8];
        yield 'digit then a lowercase name never closed after a comment' => ['pattern' => '/(?(?#c)(*1foo/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 8];
        yield 'underscore then a lowercase name never closed after a comment' => ['pattern' => '/(?(?#c)(*_foo/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 8];
        // PCRE: "missing closing parenthesis", at the end, for a lowercase
        // name the pattern ends in, whatever its second character.
        yield 'one letter and a capital never closed after a comment' => ['pattern' => '/(?(?#c)(*pX/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 11];
        yield 'one letter and a digit never closed after a comment' => ['pattern' => '/(?(?#c)(*p1/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 11];
        // PCRE: "(*alpha_assertion) not recognized", at the colon.
        yield 'unknown name of a letter and a capital after a comment' => ['pattern' => '/(?(?#c)(*aA:/', 'code' => ErrorCode::VerbInvalid, 'offset' => 11];
        // A callout that never closes is the condition: refused as a callout.
        yield 'callout string never closed' => ['pattern' => '/(?(?C"x/', 'code' => ErrorCode::CalloutUnclosedString, 'offset' => 5];
        yield 'callout number over 255' => ['pattern' => '/(?(?C256/', 'code' => ErrorCode::CalloutOutOfRange, 'offset' => 8];
        yield 'callout number then a letter' => ['pattern' => '/(?(?C1a/', 'code' => ErrorCode::CalloutUnclosed, 'offset' => 6];
        yield 'callout with no known delimiter' => ['pattern' => '/(?(?Cx/', 'code' => ErrorCode::CalloutInvalidDelimiter, 'offset' => 6];
    }

    /**
     * Whether $pattern matches $subject. The JIT does not run callouts: PHP
     * warns and matches without it, so the warning is silenced, never a
     * failure to compile.
     */
    private function matchCount(string $pattern, string $subject): int
    {
        $matched = @preg_match($pattern, $subject);
        $this->assertNotFalse($matched, \sprintf('%s does not compile: %s', $pattern, preg_last_error_msg()));

        return $matched;
    }

    /**
     * The major.minor release of the PCRE2 the running PHP links.
     */
    private static function runningRelease(): string
    {
        return implode('.', \array_slice(explode('.', explode(' ', \PCRE_VERSION)[0]), 0, 2));
    }
}
