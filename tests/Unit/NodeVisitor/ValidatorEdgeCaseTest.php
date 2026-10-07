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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\Validation\Validator;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidatorEdgeCaseTest extends TestCase
{
    private Regex $regex;

    private Validator $validator;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
        $this->validator = new Validator();
    }

    public function test_invalid_quantifier_range(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('min > max');
        $this->validate('/a{5,2}/');
    }

    #[DoesNotPerformAssertions]
    public function test_allows_octal_zero_escape(): void
    {
        // \0 is now allowed as it represents the null byte
        $this->validate('/\0/');
    }

    public function test_invalid_backreference_out_of_bounds(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Backreference to non-existent group');
        $this->validate('/\5/'); // Group 5 doesn't exist
    }

    public function test_invalid_relative_backref(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('relative reference');
        $this->validate('/(a)\g{-5}/');
    }

    public function test_invalid_named_backref(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('non-existent named group');
        $this->validate('/(a)\k<foo>/');
    }

    public function test_variable_quantifier_in_lookbehind(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Lookbehind is unbounded');
        $this->validate('/(?<=a*)/');
    }

    public function test_keep_in_lookbehind(): void
    {
        // PHP 8.4 compiles it: php-src sets PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK
        // up to 8.4.
        $this->assertTrue(Regex::create(['php_version' => '8.4'])->validate('/(?<=a\K)/')->isValid);
    }

    public function test_invalid_posix_class(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid POSIX class');
        $this->validate('/[[:fake:]]/');
    }

    public function test_posix_negation_of_word_is_valid(): void
    {
        $this->validate('/[[:^word:]]/');

        $this->assertNotFalse(@preg_match('/[[:^word:]]/', ''));
    }

    public function test_invalid_conditional_condition(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid conditional construct');
        // Literal 'a' is not a valid condition
        $this->validate('/(?(a)b)/');
    }

    public function test_invalid_unicode_codepoint(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('out of range');
        $this->validate('/\u{110000}/u');
    }

    public function test_invalid_unicode_property(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid or unsupported Unicode property');
        $this->validate('/\p{InvalidProp}/u');
    }

    /**
     * A "[:", "[=" or "[." that a quote holds is text, not the opener of a
     * POSIX item: PCRE compiles each pattern, and the class matches the
     * quoted characters (each subject checked against the engine, and
     * against the pattern the library prints back).
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideQuotedPosixOpeners')]
    public function test_a_quoted_posix_opener_in_a_class_is_text(string $pattern, array $subjects): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), 'Oracle: '.$pattern.' compiles.');

        $result = Regex::create(['cache' => null])->validate($pattern);
        $this->assertTrue($result->isValid, \sprintf('%s compiles but was reported invalid: %s', $pattern, (string) $result->error));

        $printed = Regex::create(['cache' => null])->parse($pattern)->accept(new PatternPrinter());
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($printed, $subject), \sprintf('%s printed as %s, which disagrees on %s.', $pattern, $printed, json_encode($subject)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideQuotedPosixOpeners(): iterable
    {
        yield 'quoted colon opener with a colon and bracket after' => ['pattern' => '/^[\\Qc[:(\\E:]$/', 'subjects' => ['c', '[', ':', '(', ']', 'd']];
        yield 'after a negated POSIX class' => ['pattern' => '/^[[:^digit:]\\Qc[:(\\E:]$/', 'subjects' => ['c', '1', ':', '(', 'x']];
        yield 'inside an alphabetic lookahead' => ['pattern' => '/^(*pla:[\\Qc[:(\\E:])./', 'subjects' => ['c', '(', ':', 'd']];
        yield 'quoted equals opener' => ['pattern' => '/^[\\Qc[=(\\E=]$/', 'subjects' => ['c', '=', '(', 'd']];
        yield 'quoted dot opener' => ['pattern' => '/^[\\Qc[.(\\E.]$/', 'subjects' => ['c', '.', '(', 'd']];
        yield 'under x' => ['pattern' => '/^[\\Qc[:(\\E:]$/x', 'subjects' => ['c', ':', ' ']];
        // Read as text already: kept as guards.
        yield 'quoted opener first in the class' => ['pattern' => '/^[\\Q[:(\\E:]$/', 'subjects' => ['[', ':', '(', 'c']];
        yield 'quoted opener and nothing after' => ['pattern' => '/^[\\Q[:\\E]$/', 'subjects' => ['[', ':', 'a']];
        yield 'quoted opener then a real POSIX class' => ['pattern' => '/^[a\\Q[:\\E[:alpha:]]$/', 'subjects' => ['a', '[', ':', 'b', '1']];
        // The last quote written before the "[" decides: an empty one, then
        // one that holds the opener.
        yield 'quote opened again after an empty one' => ['pattern' => '/^[\\Q\\E\\Q[:foo:]\\E]$/', 'subjects' => ['[', ':', 'f', 'x']];
        yield 'quote opened again after a letter and a stray end' => ['pattern' => '/^[a\\E\\Q[:foo:]\\E]$/', 'subjects' => ['a', '[', 'f', 'x']];
        yield 'quote opened again after a letter and an empty one' => ['pattern' => '/^[a\\Q\\E\\Q[:foo:]\\E]$/', 'subjects' => ['a', '[', 'o', 'x']];
    }

    /**
     * A quote closed before the "[" leaves it unquoted: it opens a POSIX
     * item again, here one PCRE refuses, inside a range end too. The offset
     * and code are the running engine's.
     */
    #[Test]
    #[DataProvider('provideBracketsAfterAClosedQuote')]
    public function test_a_bracket_after_a_closed_quote_in_a_class_opens_a_posix_item(string $pattern, ErrorCode $code, int $offset): void
    {
        $this->assertRefusedAsTheEngineRefuses($pattern, $code, $offset);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideBracketsAfterAClosedQuote(): iterable
    {
        // PCRE: "POSIX collating elements are not supported".
        yield 'after a letter and an empty quote' => ['pattern' => '/[a\\Q\\E[.a.]]/', 'code' => ErrorCode::PosixCollatingElement, 'offset' => 11];
        yield 'after an empty quote first in the class' => ['pattern' => '/[\\Q\\E[.a.]]/', 'code' => ErrorCode::PosixCollatingElement, 'offset' => 10];
        yield 'equivalence class after a letter and an empty quote' => ['pattern' => '/[a\\Q\\E[=a=]]/', 'code' => ErrorCode::PosixCollatingElement, 'offset' => 11];
        // PCRE: "unknown POSIX class name".
        yield 'unknown name after a letter and an empty quote' => ['pattern' => '/[a\\Q\\E[:(:]]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 11];
        // PCRE: "invalid range in character class": the quote ends before the "-".
        yield 'range end after a quoted start' => ['pattern' => '/[\\Qa\\E-[.a.]]/', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 12];
    }

    /**
     * "\c\" is the control character of the backslash: the "\Q" after it
     * is "Q", no quote, so "[:" opens a POSIX item whose name PCRE does not
     * know ("unknown POSIX class name"). The offset and code are the
     * running engine's.
     */
    #[Test]
    #[DataProvider('provideControlEscapesBeforeAPosixOpener')]
    public function test_a_posix_opener_after_a_control_backslash_is_no_quoted_text(string $pattern, ErrorCode $code, int $offset): void
    {
        if (!PcreTarget::runtime()->pcreAtLeast('10.45')) {
            $this->markTestSkipped(\sprintf('%s is verified against PCRE2 10.45 and later; PCRE2 %s reports it differently.', $pattern, \PCRE_VERSION));
        }

        $this->assertRefusedAsPcreRefuses($pattern, $code, $offset);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideControlEscapesBeforeAPosixOpener(): iterable
    {
        yield 'control backslash, Q, then a POSIX opener holding a parenthesis' => ['pattern' => '/[\\c\\Q[:(\\E:]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 12];
        yield 'control backslash, Q, then a POSIX opener' => ['pattern' => '/[\\c\\Q[:\\E:]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 11];
        // Refused where PCRE refuses it already: kept as a guard.
        yield 'control letter then an unknown POSIX name' => ['pattern' => '/[\\cA[:foo:]]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 11];
    }

    /**
     * PCRE reads a class whole before it misses the ")" of the group around
     * it: an unknown POSIX name or a reversed range in a class that closes
     * the pattern is reported first, whatever the group ("(", "(?:", "(?=",
     * "(*pla:", "(?*"...). The offsets are the running engine's. A class
     * that ends right where the later error stands, at the end of the
     * pattern, is still read whole; the guards below put a byte after it.
     */
    #[Test]
    #[DataProvider('provideClassErrorsBeforeAnUnclosedGroupAtTheEnd')]
    public function test_validate_reports_a_class_error_before_an_unclosed_group_ending_on_the_class(string $pattern, ErrorCode $code, int $offset): void
    {
        if (!PcreTarget::runtime()->pcreAtLeast('10.45')) {
            $this->markTestSkipped(\sprintf('%s is verified against PCRE2 10.45 and later; PCRE2 %s reports it differently.', $pattern, \PCRE_VERSION));
        }

        $this->assertRefusedAsPcreRefuses($pattern, $code, $offset);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideClassErrorsBeforeAnUnclosedGroupAtTheEnd(): iterable
    {
        // PCRE: "unknown POSIX class name"; the library: unclosed group at offset + 1.
        yield 'capturing group' => ['pattern' => '/([[:foo:]]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 9];
        yield 'capturing group after a letter' => ['pattern' => '/a([[:foo:]]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 10];
        yield 'non-capturing group' => ['pattern' => '/(?:[[:foo:]]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 11];
        yield 'named group' => ['pattern' => '/(?<n>[[:foo:]]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 13];
        yield 'atomic group' => ['pattern' => '/(?>[[:foo:]]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 11];
        yield 'branch reset group' => ['pattern' => '/(?|[[:foo:]]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 11];
        yield 'group with options' => ['pattern' => '/(?i:[[:foo:]]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 12];
        yield 'lookahead' => ['pattern' => '/(?=[[:foo:]]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 11];
        yield 'lookahead in a group' => ['pattern' => '/((?=[[:foo:]]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 12];
        yield 'alphabetic lookahead' => ['pattern' => '/(*pla:[[:foo:]]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 14];
        yield 'alphabetic atomic group' => ['pattern' => '/(*atomic:[[:foo:]]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 17];
        yield 'short non-atomic lookahead' => ['pattern' => '/(?*[[:foo:]]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 11];
        // PCRE: "range out of order in character class".
        yield 'reversed range in a capturing group' => ['pattern' => '/([z-a]/', 'code' => ErrorCode::RangeReversed, 'offset' => 5];
        yield 'reversed range in an alphabetic lookahead' => ['pattern' => '/(*pla:[z-a]/', 'code' => ErrorCode::RangeReversed, 'offset' => 10];
        // Reported where PCRE reports them already: kept as guards.
        yield 'text after the class' => ['pattern' => '/([[:foo:]]a/', 'code' => ErrorCode::PosixInvalid, 'offset' => 9];
        yield 'comment after the class' => ['pattern' => '/([[:foo:]](?#c)/', 'code' => ErrorCode::PosixInvalid, 'offset' => 9];
        yield 'space after the class under x' => ['pattern' => '/([[:foo:]] /x', 'code' => ErrorCode::PosixInvalid, 'offset' => 9];
        yield 'reversed range, text after the class' => ['pattern' => '/([z-a]a/', 'code' => ErrorCode::RangeReversed, 'offset' => 5];
        yield 'empty POSIX name' => ['pattern' => '/([[::]]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 6];
        yield 'collating element' => ['pattern' => '/([[.a.]]/', 'code' => ErrorCode::PosixCollatingElement, 'offset' => 7];
    }

    /**
     * A verb argument that no ")" closes, or a "\p{" that no "}" closes,
     * runs to the end of the pattern for PCRE: a class written inside it is
     * never read as a class, so its reversed range or unknown POSIX name is
     * never reported. PCRE reports the verb ("(*VERB) not recognized or
     * malformed") or the property ("malformed \P or \p sequence") at the end
     * of the pattern.
     *
     * The library agrees with the running engine on every release, the
     * offset and a code its message allows; the row holds what PCRE2 10.49
     * reports, checked against the engine when it is 10.49.
     */
    #[Test]
    #[DataProvider('provideClassesInsideAnUnclosedVerbOrProperty')]
    public function test_validate_reports_the_unclosed_verb_or_property_around_a_class_pcre_never_reads(string $pattern, ErrorCode $code, int $offset): void
    {
        $this->assertRefusedAsTheEngineRefuses($pattern, $code, $offset);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideClassesInsideAnUnclosedVerbOrProperty(): iterable
    {
        // PCRE: "(*VERB) not recognized or malformed", at the end of the pattern.
        yield 'reversed range in an unclosed mark' => ['pattern' => '/(*MARK:[z-a]/', 'code' => ErrorCode::VerbUnclosed, 'offset' => 12];
        yield 'reversed range in an unclosed short mark' => ['pattern' => '/(*:[z-a]/', 'code' => ErrorCode::VerbUnclosed, 'offset' => 8];
        yield 'reversed range in an unclosed prune' => ['pattern' => '/(*PRUNE:[z-a]/', 'code' => ErrorCode::VerbUnclosed, 'offset' => 13];
        yield 'reversed range in an unclosed skip' => ['pattern' => '/(*SKIP:[z-a]/', 'code' => ErrorCode::VerbUnclosed, 'offset' => 12];
        yield 'reversed range in an unclosed then' => ['pattern' => '/(*THEN:[z-a]/', 'code' => ErrorCode::VerbUnclosed, 'offset' => 12];
        yield 'reversed range in an unclosed short mark after text' => ['pattern' => '/a(*:b[z-a]/', 'code' => ErrorCode::VerbUnclosed, 'offset' => 10];
        yield 'unknown POSIX name in an unclosed mark' => ['pattern' => '/(*MARK:[[:foo:]]/', 'code' => ErrorCode::VerbUnclosed, 'offset' => 16];
        yield 'reversed range then text in an unclosed mark' => ['pattern' => '/(*MARK:a[z-a]b/', 'code' => ErrorCode::VerbUnclosed, 'offset' => 14];
        // The library has PCRE's offset here, but names the class, not the verb.
        yield 'class left open in an unclosed mark' => ['pattern' => '/(*MARK:[a/', 'code' => ErrorCode::VerbUnclosed, 'offset' => 9];
        // PCRE: "malformed \P or \p sequence", at the end of the pattern.
        yield 'reversed range in an unclosed property' => ['pattern' => '/(\\p{[z-a]/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 9];
        yield 'reversed range in an unclosed negated property' => ['pattern' => '/(\\P{[z-a]/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 9];
        yield 'unknown POSIX name in an unclosed property' => ['pattern' => '/(?:\\p{[[:foo:]]/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 15];
        yield 'reversed range in an unclosed property in an alphabetic lookahead' => ['pattern' => '/(*pla:\\p{[z-a]/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 14];
        // Reported where PCRE reports it already: kept as a guard. The mark
        // closes, so the class after it is read.
        yield 'reversed range after a closed mark' => ['pattern' => '/(*MARK:a)[z-a]/', 'code' => ErrorCode::RangeReversed, 'offset' => 13];
    }

    /**
     * Outside "(?xx)" a space first in a class is a member: " -\x1f" is a
     * range from U+0020 down to U+001F, which PCRE refuses before it meets
     * the error after the class. Under "(?xx)" PCRE skips the space, the
     * "-" is a member, and the error after the class is the one reported.
     */
    #[Test]
    #[DataProvider('provideClassesOpenedByASpaceBeforeALaterError')]
    public function test_validate_reads_a_space_first_in_a_class_before_a_later_error(string $pattern, ErrorCode $code, int $offset): void
    {
        $this->assertRefusedAsTheEngineRefuses($pattern, $code, $offset);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideClassesOpenedByASpaceBeforeALaterError(): iterable
    {
        // PCRE: "range out of order in character class".
        yield 'before a group left open' => ['pattern' => '/[ -\\x1f](/', 'code' => ErrorCode::RangeReversed, 'offset' => 7];
        yield 'before a stray parenthesis' => ['pattern' => '/[ -\\x1f]a)/', 'code' => ErrorCode::RangeReversed, 'offset' => 7];
        yield 'in a body left open' => ['pattern' => '/(*pla:[ -\\x1f](/', 'code' => ErrorCode::RangeReversed, 'offset' => 13];
        // Under xx: PCRE reports the error after the class.
        yield 'under xx, before a group left open' => ['pattern' => '/(?xx)[ -\\x1f](/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 14];
        yield 'under xx, before a stray parenthesis' => ['pattern' => '/(?xx)[ -\\x1f]a)/', 'code' => ErrorCode::GroupUnmatchedClose, 'offset' => 15];
    }

    private function validate(string $regex): void
    {
        $ast = $this->regex->parse($regex);
        $ast->accept($this->validator);
    }

    private function assertRefusedAsPcreRefuses(string $pattern, ErrorCode $code, int $offset): void
    {
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
        $this->assertSame($offset, $pcre['offset'], \sprintf('Oracle: %s (%s).', $pattern, $pcre['message']));
        $this->assertContains($code->value, PcreMessageCodes::CODES[$pcre['message']] ?? [], \sprintf('Oracle: %s does not name "%s".', $code->value, $pcre['message']));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame([$code, $offset], [$result->errorCode, $result->offset], \sprintf('%s: PCRE says "%s" at %d, the library "%s" (%s) at %s.', $pattern, $pcre['message'], $offset, (string) $result->error, $result->errorCode?->value, var_export($result->offset, true)));
    }

    /**
     * The library refuses the pattern at the running engine's offset, with a
     * code the engine's message allows; on PCRE2 10.49 the row's offset and
     * code are the engine's and the library's.
     */
    private function assertRefusedAsTheEngineRefuses(string $pattern, ErrorCode $code, int $offset): void
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
     * The major.minor release of the PCRE2 the running PHP links.
     */
    private static function runningRelease(): string
    {
        return implode('.', \array_slice(explode('.', explode(' ', \PCRE_VERSION)[0]), 0, 2));
    }
}
