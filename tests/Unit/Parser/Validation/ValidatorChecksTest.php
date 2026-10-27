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

namespace PHPRegex\Tests\Unit\Parser\Validation;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the validator says of the verbs and the settings of the opening
 * run, the items and ranges of classes, the branches of conditionals, the
 * counts of repeats, version conditions and callouts: the code, the
 * offset and the message of the first error, as the walk over the tree
 * finds it, in a pattern and in the payload of a "(*pla:...)" parsed apart.
 */
final class ValidatorChecksTest extends TestCase
{
    /**
     * Each verified with preg_match() on PCRE2 10.49: the offset is the one
     * PCRE reports.
     *
     * @param array{0: ErrorCode, 1: int, 2: string}|null $error
     */
    #[Test]
    #[DataProvider('provideCurrentRelease')]
    public function test_the_check_reports_what_pcre2_10_49_reports(string $pattern, ?array $error): void
    {
        $this->assertSame($error, $this->firstError($pattern, '8.4', '10.49'), $pattern);
    }

    /**
     * The same checks for older releases, where PCRE reports some of these
     * errors on the character at fault rather than past it, or does not
     * know the construct.
     *
     * @param array{0: ErrorCode, 1: int, 2: string} $error
     */
    #[Test]
    #[DataProvider('provideOlderReleases')]
    public function test_the_check_follows_the_release_judged(string $pattern, string $php, string $pcre, array $error): void
    {
        $this->assertSame($error, $this->firstError($pattern, $php, $pcre), $pattern.' on PCRE2 '.$pcre);
    }

    /**
     * @return iterable<string, array{pattern: string, error: array{0: ErrorCode, 1: int, 2: string}|null}>
     */
    public static function provideCurrentRelease(): iterable
    {
        $mark = str_repeat('a', 256);

        // Verbs and the settings of the opening run.
        yield 'verb with no name' => ['pattern' => '/(*)/', 'error' => [ErrorCode::QuantifierNothingToRepeat, 2, 'Quantifier "*" does not follow a repeatable item: "(*)" names no verb.']];
        yield 'unknown verb' => ['pattern' => '/(*FOO)/', 'error' => [ErrorCode::VerbInvalid, 5, 'Invalid or unsupported PCRE verb: "FOO".']];
        yield 'verb that takes no value' => ['pattern' => '/(*COMMIT=x)/', 'error' => [ErrorCode::VerbInvalid, 8, '(*COMMIT) takes no value: only the limits take one, as (*LIMIT_MATCH=10).']];
        yield 'limit with no number' => ['pattern' => '/(*LIMIT_MATCH=)a/', 'error' => [ErrorCode::VerbInvalid, 14, '(*LIMIT_MATCH) needs a number: (*LIMIT_MATCH=10).']];
        yield 'limit with a letter after its digits' => ['pattern' => '/(*LIMIT_MATCH=5x)a/', 'error' => [ErrorCode::VerbInvalid, 15, '(*LIMIT_MATCH) needs a number: (*LIMIT_MATCH=10).']];
        yield 'second limit with no number' => ['pattern' => '/(*LIMIT_MATCH=1)(*LIMIT_MATCH=x)a/', 'error' => [ErrorCode::VerbInvalid, 30, '(*LIMIT_MATCH) needs a number: (*LIMIT_MATCH=10).']];
        yield 'limit too large' => ['pattern' => '/(*LIMIT_MATCH=99999999999)a/', 'error' => [ErrorCode::VerbLimitTooLarge, 23, 'The value 99999999999 is too large for a (*LIMIT_...) setting: PCRE takes at most 4294967289.']];
        yield 'heap limit too large' => ['pattern' => '/(*LIMIT_HEAP=99999999999)a/', 'error' => [ErrorCode::VerbLimitTooLarge, 22, 'The value 99999999999 is too large for a (*LIMIT_...) setting: PCRE takes at most 4294967289.']];
        yield 'limits in the opening run' => ['pattern' => '/(*LIMIT_DEPTH=10)(*LIMIT_RECURSION=5)a/', 'error' => null];
        yield 'mark written with "="' => ['pattern' => '/(*=x)/', 'error' => [ErrorCode::VerbInvalid, 2, '(*=...) is not a PCRE verb; a mark is written (*MARK:name) or (*:name).']];
        yield 'mark with no name' => ['pattern' => '/(*MARK)/', 'error' => [ErrorCode::VerbMarkNameMissing, 6, '(*MARK) must have a name: (*MARK:name) or (*:name).']];
        yield 'mark name too long' => ['pattern' => '/(*MARK:'.$mark.')/', 'error' => [ErrorCode::VerbNameTooLong, 263, 'The name of (*MARK) is too long: PCRE takes at most 255 code units.']];
        yield 'short mark name too long' => ['pattern' => '/(*:'.$mark.')/', 'error' => [ErrorCode::VerbNameTooLong, 259, 'The name of (*MARK) is too long: PCRE takes at most 255 code units.']];
        yield 'setting after the opening run' => ['pattern' => '/a(*UTF)/', 'error' => [ErrorCode::VerbMisplaced, 6, '(*UTF) is only recognized at the very start of the pattern.']];
        yield 'setting in a payload parsed apart' => ['pattern' => '/(*pla:a(*UTF))/', 'error' => [ErrorCode::VerbMisplaced, 12, '(*UTF) is only recognized at the very start of the pattern.']];
        yield 'mark with no name in a payload' => ['pattern' => '/(*pla:(*MARK))/', 'error' => [ErrorCode::VerbMarkNameMissing, 12, '(*MARK) must have a name: (*MARK:name) or (*:name).']];
        yield 'conflicting casings' => ['pattern' => '/(*TURKISH_CASING)(*CASELESS_RESTRICT)a/u', 'error' => [ErrorCode::VerbConflictingCasings, 37, '(*TURKISH_CASING) and (*CASELESS_RESTRICT) cannot be used together.']];
        yield 'turkish casing without UTF' => ['pattern' => '/(*TURKISH_CASING)a/', 'error' => [ErrorCode::VerbTurkishCasingWithoutUtf, 17, '(*TURKISH_CASING) needs UTF mode.']];
        yield 'turkish casing under UCP alone' => ['pattern' => '/(*UCP)(*TURKISH_CASING)a/', 'error' => [ErrorCode::VerbTurkishCasingWithoutUtf, 23, '(*TURKISH_CASING) needs UTF mode: UCP alone is not enough.']];

        // The items of classes.
        yield 'unknown POSIX class' => ['pattern' => '/[[:foo:]]/', 'error' => [ErrorCode::PosixInvalid, 8, 'Invalid POSIX class: "foo".']];
        yield 'negated unknown POSIX class' => ['pattern' => '/[[:^foo:]]/', 'error' => [ErrorCode::PosixInvalid, 9, 'Invalid POSIX class: "^foo".']];
        yield 'POSIX class in capitals' => ['pattern' => '/[[:ALPHA:]]/', 'error' => [ErrorCode::PosixInvalid, 10, 'Invalid POSIX class: "ALPHA".']];
        yield 'collating element' => ['pattern' => '/[[.a.]]/', 'error' => [ErrorCode::PosixCollatingElement, 6, 'POSIX collating elements are not supported.']];
        yield 'collating element after a hyphen' => ['pattern' => '/[-[.x.]]/', 'error' => [ErrorCode::PosixCollatingElement, 7, 'POSIX collating elements are not supported.']];
        yield 'collating element ending a range' => ['pattern' => '/[a-[.x.]]/', 'error' => [ErrorCode::RangeInvalidBounds, 8, 'Invalid range in character class: a POSIX item cannot end a range.']];
        yield 'POSIX class outside a class' => ['pattern' => '/[:alpha:]/', 'error' => [ErrorCode::PosixOutsideClass, 9, 'POSIX named classes are supported only within a class.']];
        yield 'collating element outside a class' => ['pattern' => '/[.a.]/', 'error' => [ErrorCode::PosixCollatingElement, 5, 'POSIX collating elements are not supported.']];
        yield 'quoted POSIX class' => ['pattern' => '/[\Q[:foo:]\E]/', 'error' => null];
        yield 'quoted bracket' => ['pattern' => '/[\Q[\E:foo:]]/', 'error' => null];
        yield 'unknown POSIX class in a payload' => ['pattern' => '/(*pla:[[:foo:]])/', 'error' => [ErrorCode::PosixInvalid, 14, 'Invalid POSIX class: "foo".']];

        // Ranges.
        yield 'reversed range' => ['pattern' => '/[z-a]/', 'error' => [ErrorCode::RangeReversed, 4, 'Invalid range "z-a": start character comes after end character.']];
        yield 'reversed range from an escape' => ['pattern' => '/[\x{100}-a]/u', 'error' => [ErrorCode::RangeReversed, 10, 'Invalid range "\x{100}-a": start character comes after end character.']];
        yield 'reversed range from the last byte of a character' => ['pattern' => "/[\u{e9}-a]/", 'error' => [ErrorCode::RangeReversed, 5, "Invalid range \"\u{e9}-a\": start character comes after end character."]];
        yield 'reversed range in a payload' => ['pattern' => '/(*pla:[z-a])/', 'error' => [ErrorCode::RangeReversed, 10, 'Invalid range "z-a": start character comes after end character.']];

        // The branches of conditionals.
        yield 'conditional of three branches' => ['pattern' => '/(a)(?(1)a|b|c)/', 'error' => [ErrorCode::ConditionalTooManyBranches, 3, 'A conditional group holds more than two branches.']];
        yield 'relative conditional of three branches' => ['pattern' => '/(a)(?(+1)a|b|c)(b)/', 'error' => [ErrorCode::ConditionalTooManyBranches, 4, 'A conditional group holds more than two branches.']];
        yield 'recursion conditional of three branches' => ['pattern' => '/(?(R)a|b|c)/', 'error' => [ErrorCode::ConditionalTooManyBranches, 0, 'A conditional group holds more than two branches.']];
        yield 'version conditional of three branches' => ['pattern' => '/(?(VERSION>=10.4)a|b|c)/', 'error' => [ErrorCode::ConditionalTooManyBranches, 0, 'A conditional group holds more than two branches.']];
        yield 'define of two branches' => ['pattern' => '/(?(DEFINE)a|b)/', 'error' => [ErrorCode::DefineTooManyBranches, 3, 'A (DEFINE) group holds more than one branch.']];
        yield 'define of two branches in a payload' => ['pattern' => '/(*pla:(?(DEFINE)a|b))/', 'error' => [ErrorCode::DefineTooManyBranches, 9, 'A (DEFINE) group holds more than one branch.']];

        // Repeat counts.
        yield 'count too big' => ['pattern' => '/a{65536}/', 'error' => [ErrorCode::QuantifierTooBig, 7, 'Number too big in "{65536}" quantifier: PCRE allows at most 65535 repetitions.']];
        yield 'count of six digits' => ['pattern' => '/a{999999}/', 'error' => [ErrorCode::QuantifierTooBig, 8, 'Number too big in "{999999}" quantifier: PCRE allows at most 65535 repetitions.']];
        yield 'upper count too big' => ['pattern' => '/a{1,70000}/', 'error' => [ErrorCode::QuantifierTooBig, 9, 'Number too big in "{1,70000}" quantifier: PCRE allows at most 65535 repetitions.']];
        yield 'open lower count too big' => ['pattern' => '/a{,65536}/', 'error' => [ErrorCode::QuantifierTooBig, 8, 'Number too big in "{,65536}" quantifier: PCRE allows at most 65535 repetitions.']];
        yield 'count after \N too big' => ['pattern' => '/\N{70000}/', 'error' => [ErrorCode::QuantifierTooBig, 2, 'Number too big in "{70000}" quantifier: PCRE allows at most 65535 repetitions.']];
        yield 'counts out of order' => ['pattern' => '/a{3,2}/', 'error' => [ErrorCode::QuantifierInvalidRange, 5, 'Invalid quantifier range "{3,2}": min > max.']];
        yield 'possessive counts out of order' => ['pattern' => '/a{2,1}+/', 'error' => [ErrorCode::QuantifierInvalidRange, 5, 'Invalid quantifier range "{2,1}": min > max.']];
        yield 'counts out of order in a payload' => ['pattern' => '/(*pla:a{3,2})/', 'error' => [ErrorCode::QuantifierInvalidRange, 11, 'Invalid quantifier range "{3,2}": min > max.']];
        yield 'padded count before a comment' => ['pattern' => '/(?x)a{ 1 , 2 }(?#c)+/', 'error' => null];

        // Version conditions.
        yield 'version compared with ">"' => ['pattern' => '/(?(VERSION>10)a)/', 'error' => [ErrorCode::ConditionalVersionOperator, 11, 'Version condition ">" is not supported: PCRE compares with "=" or ">=".']];
        yield 'version compared with "<"' => ['pattern' => '/(?(VERSION<10)a)/', 'error' => [ErrorCode::ConditionalVersionOperator, 11, 'Version condition "<" is not supported: PCRE compares with "=" or ">=".']];
        yield 'version of three parts' => ['pattern' => '/(?(VERSION=10.4.5)a)/', 'error' => [ErrorCode::ConditionalVersionSyntax, 16, 'Invalid version "10.4.5" in a version condition: PCRE takes a major number and an optional ".minor".']];
        yield 'version minor too big' => ['pattern' => '/(?(VERSION=10.1001)a)/', 'error' => [ErrorCode::ConditionalVersionSyntax, 18, 'Invalid version "10.1001" in a version condition: the number is too big.']];
        yield 'version major too big' => ['pattern' => '/(?(VERSION=1001)a)/', 'error' => [ErrorCode::ConditionalVersionSyntax, 15, 'Invalid version "1001" in a version condition: the number is too big.']];

        // Callouts.
        yield 'callout number too big' => ['pattern' => '/(?C256)/', 'error' => [ErrorCode::CalloutOutOfRange, 6, 'Callout identifier must be between 0 and 255, got 256.']];
        yield 'callout number of four digits' => ['pattern' => '/(?C1000)/', 'error' => [ErrorCode::CalloutOutOfRange, 7, 'Callout identifier must be between 0 and 255, got 1000.']];
        yield 'callout number too big in a payload' => ['pattern' => '/(*pla:(?C256))/', 'error' => [ErrorCode::CalloutOutOfRange, 12, 'Callout identifier must be between 0 and 255, got 256.']];
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, pcre: string, error: array{0: ErrorCode, 1: int, 2: string}}>
     */
    public static function provideOlderReleases(): iterable
    {
        // Before PCRE2 10.45, past the character after the digits; the
        // digit after the one refused.
        yield 'limit with a letter after its digits' => ['pattern' => '/(*LIMIT_MATCH=5x)a/', 'php' => '8.4', 'pcre' => '10.43', 'error' => [ErrorCode::VerbInvalid, 16, '(*LIMIT_MATCH) needs a number: (*LIMIT_MATCH=10).']];
        yield 'limit too large' => ['pattern' => '/(*LIMIT_MATCH=99999999999)a/', 'php' => '8.4', 'pcre' => '10.43', 'error' => [ErrorCode::VerbLimitTooLarge, 24, 'The value 99999999999 is too large for a (*LIMIT_...) setting: PCRE takes at most 4294967289.']];
        // Before PCRE2 10.45, the casing settings are verbs PCRE does not know.
        yield 'casing setting before PCRE2 10.45' => ['pattern' => '/(*CASELESS_RESTRICT)a/', 'php' => '8.4', 'pcre' => '10.44', 'error' => [ErrorCode::VerbInvalid, 19, 'Invalid or unsupported PCRE verb: "CASELESS_RESTRICT".']];
        // Before PCRE2 10.45, on the "[" of a collating element or on the
        // name of a class.
        yield 'unknown POSIX class' => ['pattern' => '/[[:foo:]]/', 'php' => '8.4', 'pcre' => '10.43', 'error' => [ErrorCode::PosixInvalid, 3, 'Invalid POSIX class: "foo".']];
        yield 'negated unknown POSIX class' => ['pattern' => '/[[:^foo:]]/', 'php' => '8.4', 'pcre' => '10.43', 'error' => [ErrorCode::PosixInvalid, 4, 'Invalid POSIX class: "^foo".']];
        yield 'collating element' => ['pattern' => '/[[.a.]]/', 'php' => '8.4', 'pcre' => '10.43', 'error' => [ErrorCode::PosixCollatingElement, 1, 'POSIX collating elements are not supported.']];
        yield 'collating element ending a range' => ['pattern' => '/[a-[.x.]]/', 'php' => '8.4', 'pcre' => '10.43', 'error' => [ErrorCode::RangeInvalidBounds, 4, 'Invalid range in character class: a POSIX item cannot end a range.']];
        // Before PCRE2 10.47, on the "[" of an item outside a class, and on
        // the end of a reversed range.
        yield 'POSIX class outside a class' => ['pattern' => '/[:alpha:]/', 'php' => '8.4', 'pcre' => '10.43', 'error' => [ErrorCode::PosixOutsideClass, 0, 'POSIX named classes are supported only within a class.']];
        yield 'reversed range' => ['pattern' => '/[z-a]/', 'php' => '8.4', 'pcre' => '10.43', 'error' => [ErrorCode::RangeReversed, 3, 'Invalid range "z-a": start character comes after end character.']];
        // Before PCRE2 10.47, on the name the condition tests.
        yield 'named recursion conditional of three branches' => ['pattern' => '/(?(R&n)a|b|c)(?<n>x)/', 'php' => '8.4', 'pcre' => '10.45', 'error' => [ErrorCode::ConditionalTooManyBranches, 5, 'A conditional group holds more than two branches.']];
        // Before PCRE2 10.45, up to the digit that takes a count over 65535.
        yield 'count of six digits' => ['pattern' => '/a{999999}/', 'php' => '8.4', 'pcre' => '10.43', 'error' => [ErrorCode::QuantifierTooBig, 7, 'Number too big in "{999999}" quantifier: PCRE allows at most 65535 repetitions.']];
        // Before PCRE2 10.43, a count that does not repeat "\N".
        yield 'padded count after \N' => ['pattern' => '/\N{4 }/', 'php' => '8.3', 'pcre' => '10.42', 'error' => [ErrorCode::EscapeUnsupported, 2, 'The count "{4 }" after \N needs PCRE2 10.43, which PHP bundles from 8.4.']];
        yield 'open count after \N' => ['pattern' => '/\N{,2}/', 'php' => '8.3', 'pcre' => '10.42', 'error' => [ErrorCode::EscapeUnsupported, 2, 'The count "{,2}" after \N needs PCRE2 10.43, which PHP bundles from 8.4.']];
        // Before PCRE2 10.37, its digits are read first.
        yield 'padded count after \N too big' => ['pattern' => '/\N{99999 }/', 'php' => '8.3', 'pcre' => '10.36', 'error' => [ErrorCode::QuantifierTooBig, 2, 'Number too big in the count after \N: PCRE allows at most 65535 repetitions.']];
        // Before PCRE2 10.47, a minor of two digits.
        yield 'version minor of three digits' => ['pattern' => '/(?(VERSION=10.100)a)/', 'php' => '8.4', 'pcre' => '10.45', 'error' => [ErrorCode::ConditionalVersionSyntax, 16, 'Invalid version "10.100" in a version condition: the number is too big.']];
        yield 'version major left open' => ['pattern' => '/(?(VERSION=10 )a)/', 'php' => '8.4', 'pcre' => '10.45', 'error' => [ErrorCode::ConditionalUnclosed, 13, 'Missing ")" to close the condition at position 13.']];
    }

    /**
     * @return array{0: ErrorCode, 1: int, 2: string}|null
     */
    private function firstError(string $pattern, string $php, string $pcre): ?array
    {
        $result = RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $pcre, 'runtime_pcre_validation' => false])->validate($pattern);

        return null === $result->errorCode || null === $result->offset ? null : [$result->errorCode, $result->offset, (string) $result->error];
    }
}
