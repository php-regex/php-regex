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

namespace PHPRegex\Tests\Unit\Transpiler;

use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without u, PCRE takes one byte for a set where JavaScript, the HTML
 * pattern attribute and Python take one character. While the set stays in
 * ASCII the two agree. Once it reaches past it they differ on a subject
 * holding a character outside ASCII: the dot, a negated class, "\D", "\W"
 * and "\S" take every byte above 0x7F, "\h" takes 0xA0 and "\v" 0x85, and a
 * property reads a byte as a Latin-1 character where the target reads the
 * character the subject holds. The pattern is written as before, and the
 * first such set is named in a warning.
 */
final class SetsWithoutUtfTest extends TestCase
{
    use PhpUnitForwardCompat;

    private const TARGETS = ['javascript', 'html-pattern', 'python'];

    /**
     * The sets that take a byte above 0x7F without u, each with what the
     * targets write for it between "^" and "$" and a character of two bytes
     * it takes whole under u.
     */
    private const SETS_PAST_ASCII = [
        '.' => ['javascript' => '/^[^\n](?=\n?$)/', 'html-pattern' => '^[^\n]$', 'python' => "r'(?a)^.$'", 'character' => 'é'],
        '[^a]' => ['javascript' => '/^[^a](?=\n?$)/', 'html-pattern' => '^[^a]$', 'python' => "r'(?a)^[^a]$'", 'character' => 'é'],
        '\D' => ['javascript' => '/^\D(?=\n?$)/', 'html-pattern' => '^\D$', 'python' => "r'(?a)^\\D$'", 'character' => 'é'],
        '\W' => ['javascript' => '/^\W(?=\n?$)/', 'html-pattern' => '^\W$', 'python' => "r'(?a)^\\W$'", 'character' => "\u{A0}"],
        '\S' => ['javascript' => '/^[^\t-\r ](?=\n?$)/', 'html-pattern' => '^[^\t-\r ]$', 'python' => "r'(?a)^\\S$'", 'character' => 'é'],
        '\h' => ['javascript' => '/^[\x09\x20\xA0\u1680\u180E\u2000-\u200A\u202F\u205F\u3000](?=\n?$)/', 'html-pattern' => '^[\x09\x20\xA0\u1680\u180E\u2000-\u200A\u202F\u205F\u3000]$', 'python' => "r'(?a)^[\\x09\\x20\\xA0\\u1680\\u180E\\u2000-\\u200A\\u202F\\u205F\\u3000]$'", 'character' => "\u{A0}"],
        '\v' => ['javascript' => '/^[\x0A-\x0D\x85\u2028\u2029](?=\n?$)/', 'html-pattern' => '^[\x0A-\x0D\x85\u2028\u2029]$', 'python' => "r'(?a)^[\\x0A-\\x0D\\x85\\u2028\\u2029]$'", 'character' => "\u{85}"],
        '[\Da]' => ['javascript' => '/^[\Da](?=\n?$)/', 'html-pattern' => '^[\Da]$', 'python' => "r'(?a)^[\\Da]$'", 'character' => 'é'],
        '[\s\S]' => ['javascript' => '/^(?:[\t-\r ]|[^\t-\r ])(?=\n?$)/', 'html-pattern' => '^[\t-\r [^\t-\r ]]$', 'python' => "r'(?a)^[\\s\\S]$'", 'character' => 'é'],
        '[^\x00-\x7F]' => ['javascript' => '/^[^\x00-\x7F](?=\n?$)/', 'html-pattern' => '^[^\x00-\x7F]$', 'python' => "r'(?a)^[^\\x00-\\x7F]$'", 'character' => 'é'],
    ];

    /**
     * The sets that stay in ASCII without u: PCRE takes no byte above 0x7F
     * for them, and the targets no character outside ASCII.
     */
    private const SETS_IN_ASCII = ['\d', '\w', '\s', '[a-z]', '[\w.-]', '[\x00-\x7F]', '[^\D]', '[^\W]', '[^\S]', '[^\w\W]', '[^\S\D]'];

    #[Test]
    #[DataProvider('provideSetsPastAscii')]
    public function test_pcre_takes_a_byte_where_the_set_reaches_past_ascii(string $set, string $character): void
    {
        $bytes = 0;
        for ($byte = 0x80; $byte <= 0xFF; $byte++) {
            $bytes += (int) preg_match('/^'.$set.'$/', \chr($byte));
        }

        $this->assertGreaterThan(0, $bytes, $set.' takes a byte above 0x7F.');
        // One byte is not the character, which u reads whole.
        $this->assertSame(2, \strlen($character));
        $this->assertSame(0, preg_match('/^'.$set.'$/', $character), $set);
        $this->assertSame(1, preg_match('/^'.$set.'$/u', $character), $set.' under u');
    }

    #[Test]
    #[DataProvider('provideSetsPastAsciiByTarget')]
    public function test_the_first_set_past_ascii_is_named_and_the_pattern_written_as_before(string $set, string $target, string $literal, string $character): void
    {
        // The character is two bytes to the set without u, and one character under it.
        $this->assertSame(0, preg_match('/^'.$set.'$/', $character), $set);
        $this->assertSame(1, preg_match('/^'.$set.'$/u', $character), $set.' under u');

        $result = Regex::create()->transpile('/^'.$set.'$/', $target);

        $this->assertSame($literal, $result->literal);
        $this->assertSame([self::warning($set, 1)], $result->warnings);
    }

    #[Test]
    #[DataProvider('provideSetsPastAsciiUnderU')]
    public function test_under_u_no_set_is_named(string $set, string $target, string $character): void
    {
        $this->assertSame(1, preg_match('/^'.$set.'$/u', $character), $set.' under u');

        try {
            $result = Regex::create()->transpile('/^'.$set.'$/u', $target);
        } catch (TranspileException $refusal) {
            // Python has no way to write "\w" as PCRE reads it under u.
            $this->assertSame(['python', '\W'], [$target, $set], $refusal->getMessage());

            return;
        }

        $this->assertSame([], $result->warnings);
    }

    #[Test]
    #[DataProvider('provideSetsInAscii')]
    public function test_a_set_that_stays_in_ascii_is_not_named(string $set, string $target): void
    {
        for ($byte = 0x80; $byte <= 0xFF; $byte++) {
            $this->assertSame(0, preg_match('/^'.$set.'$/', \chr($byte)), \sprintf('%s takes no byte above 0x7F, 0x%X included.', $set, $byte));
        }

        $this->assertSame([], Regex::create()->transpile('/^'.$set.'+$/', $target)->warnings);
    }

    /**
     * Without u PCRE reads a byte as a Latin-1 character for a property:
     * 0xC3, the first byte of "é", is the letter "Ã". JavaScript reads the
     * character the subject holds, and takes a digit of another script that
     * PCRE reads as two bytes, neither one a digit.
     *
     * @param string $subject a subject of UTF-8 the pattern takes whole under u, and not without it
     */
    #[Test]
    #[DataProvider('provideProperties')]
    public function test_a_property_is_named_whatever_bytes_it_takes(string $pattern, string $target, string $literal, string $set, int $offset, string $subject): void
    {
        $this->assertSame(1, preg_match('/^\p{L}$/', "\xC3"));
        $this->assertSame(0, preg_match('/^\p{L}$/', 'é'));
        $this->assertSame(0, preg_match('/\p{Nd}/', '٣'));
        $this->assertSame(1, preg_match('/\p{Nd}/u', '٣'));
        // The pattern itself: bytes without u, the character under it.
        $this->assertTrue(mb_check_encoding($subject, 'UTF-8'));
        $this->assertSame(0, preg_match($pattern, $subject), $pattern);
        $this->assertSame(1, preg_match($pattern.'u', $subject), $pattern.'u');

        $result = Regex::create()->transpile($pattern, $target);

        $this->assertSame($literal, $result->literal);
        // The flag JavaScript needs for a property is reported first, as before.
        $this->assertSame('javascript' === $target ? ['Added /u for Unicode property escapes.', self::warning($set, $offset)] : [self::warning($set, $offset)], $result->warnings);
        $this->assertSame([], Regex::create()->transpile($pattern.'u', $target)->warnings);
    }

    /**
     * @param string $subject what the pattern matches with the lone byte 0xE9 where the named set stands
     */
    #[Test]
    #[DataProvider('provideFirstSets')]
    public function test_one_warning_names_the_first_set_of_the_pattern(string $pattern, string $target, string $set, int $offset, string $subject): void
    {
        // The named set takes the byte, which is no character on its own.
        $this->assertSame(1, preg_match('/^'.$set.'$/', "\xE9"), $set);
        $this->assertSame(1, substr_count($subject, "\xE9"));
        $this->assertFalse(mb_check_encoding($subject, 'UTF-8'));
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);
        // Without the byte the pattern fails: the set is what took it.
        $this->assertSame(0, preg_match($pattern, str_replace("\xE9", '', $subject)), $pattern);

        $this->assertSame([self::warning($set, $offset)], Regex::create()->transpile($pattern, $target)->warnings);
    }

    /**
     * An inline "(?xx)", which Python alone carries, has PCRE leave out the
     * spaces and tabs of a class: "[ ^a]" is "[^a]" under it, and takes a
     * byte above 0x7F, where it is a space, "^" and "a" without it.
     */
    #[Test]
    #[DataProvider('provideClassesUnderExtendedMore')]
    public function test_a_class_is_read_under_the_inline_xx_in_force(string $pattern, ?int $offset): void
    {
        // Negated, the class takes the byte; a space, "^" and "a" do not.
        $this->assertSame(null === $offset ? 0 : 1, preg_match($pattern, "\xE9"), $pattern);
        $this->assertSame(1, preg_match($pattern, ' '), $pattern);

        $this->assertSame(null === $offset ? [] : [self::warning('[ ^a]', $offset)], Regex::create()->transpile($pattern, 'python')->warnings);
    }

    /**
     * Under "(?xx)" the class "[ ^\S]" is "[^\S]", six ASCII bytes: the dot
     * after it is the first set that reaches past ASCII.
     */
    #[Test]
    public function test_a_class_that_stays_in_ascii_under_xx_leaves_the_warning_to_the_next_set(): void
    {
        $this->assertSame(1, preg_match('/(?xx)^[ ^\S].$/', " \xE9"));
        $this->assertSame(0, preg_match('/(?xx)^[ ^\S].$/', "\xA0\xE9"));
        $this->assertSame(1, preg_match('/^[ ^\S].$/', "\xA0\xE9"));

        $this->assertSame([self::warning('.', 12)], Regex::create()->transpile('/(?xx)^[ ^\S].$/', 'python')->warnings);
        $this->assertSame([self::warning('[ ^\S]', 1)], Regex::create()->transpile('/^[ ^\S].$/', 'python')->warnings);
    }

    /**
     * What a target refuses reaches no output: "\H", "\V", "\N", "\C",
     * "\R" and "\X" take a byte above 0x7F too, and stay refused.
     */
    #[Test]
    #[DataProvider('provideRefusedSets')]
    public function test_a_set_the_target_refuses_stays_refused(string $pattern, string $target, string $byte): void
    {
        $this->assertSame(1, preg_match($pattern, $byte), $pattern);

        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains('Unsupported character type');

        Regex::create()->transpile($pattern, $target);
    }

    /**
     * PCRE reads an assertion that may be left out as none at all: its dot
     * is not written, and not named.
     */
    #[Test]
    #[DataProvider('provideTargets')]
    public function test_a_set_that_is_not_written_is_not_named(string $target): void
    {
        $this->assertSame(1, preg_match('/^a(?=.)?$/D', 'a'));
        $this->assertSame(0, preg_match('/^a(?=.)$/D', 'a'));

        $result = Regex::create()->transpile('/^a(?=.)?$/D', $target);

        $this->assertStringNotContainsString('(?=', $result->pattern);
        $this->assertSame([], $result->warnings);
    }

    /**
     * The running PCRE says which bytes a set takes, the set written
     * between delimiters: one that holds every delimiter it tries cannot be
     * asked about, and is named as a set that reaches past ASCII.
     */
    #[Test]
    #[DataProvider('provideTargets')]
    public function test_a_set_pcre_cannot_be_asked_about_is_named(string $target): void
    {
        $set = "[^/#~%!@;\x01]";
        $this->assertSame(1, preg_match('{^'.$set.'$}', "\xE9"));

        $result = Regex::create()->transpile('{^'.$set.'$}', $target);

        $this->assertSame([self::warning($set, 1)], $result->warnings);
    }

    /**
     * The advice is checked against the engine, as a refusal's is: a
     * comment that is not valid UTF-8 is dropped by the targets and
     * refused by u.
     */
    #[Test]
    #[DataProvider('providePatternsRefusedUnderU')]
    public function test_the_advice_says_when_u_alone_is_not_enough(string $pattern, string $target): void
    {
        $this->assertSame(1, preg_match($pattern, 'ab'));
        $this->assertFalse(@preg_match($pattern.'u', 'ab'));
        $this->assertSame(1, preg_match('/a./u', 'aé'));

        $result = Regex::create()->transpile($pattern, $target);

        $this->assertSame(['Without the u flag "." at offset 6 takes one byte in PCRE and one character in this target, so the two differ on a subject holding a character outside ASCII; under u, for UTF-8 subjects, they agree. The u flag refuses a pattern that is not valid UTF-8: write the character as UTF-8, then add u.'], $result->warnings);
    }

    #[Test]
    public function test_the_warning_reads_as_one_sentence_and_the_advice(): void
    {
        $this->assertSame(0, preg_match('/^\S$/', 'é'));
        $this->assertSame(1, preg_match('/^\S$/u', 'é'));
        $this->assertSame(
            ['Without the u flag "\S" at offset 1 takes one byte in PCRE and one character in this target, so the two differ on a subject holding a character outside ASCII; under u, for UTF-8 subjects, they agree. Add u.'],
            Regex::create()->transpile('/^\S$/', 'javascript')->warnings,
        );
    }

    /**
     * @return iterable<string, array{set: string, character: string}>
     */
    public static function provideSetsPastAscii(): iterable
    {
        foreach (self::SETS_PAST_ASCII as $set => $row) {
            yield $set => ['set' => $set, 'character' => $row['character']];
        }
    }

    /**
     * @return iterable<string, array{set: string, target: string, literal: string, character: string}>
     */
    public static function provideSetsPastAsciiByTarget(): iterable
    {
        foreach (self::SETS_PAST_ASCII as $set => $row) {
            foreach (self::TARGETS as $target) {
                yield $target.': '.$set => ['set' => $set, 'target' => $target, 'literal' => $row[$target], 'character' => $row['character']];
            }
        }
    }

    /**
     * @return iterable<string, array{set: string, target: string, character: string}>
     */
    public static function provideSetsPastAsciiUnderU(): iterable
    {
        foreach (self::provideSetsPastAsciiByTarget() as $name => $row) {
            yield $name => ['set' => $row['set'], 'target' => $row['target'], 'character' => $row['character']];
        }
    }

    /**
     * @return iterable<string, array{set: string, target: string}>
     */
    public static function provideSetsInAscii(): iterable
    {
        foreach (self::SETS_IN_ASCII as $set) {
            foreach (self::TARGETS as $target) {
                yield $target.': '.$set => ['set' => $set, 'target' => $target];
            }
        }
    }

    /**
     * Python's re has no property.
     *
     * @return iterable<string, array{pattern: string, target: string, literal: string, set: string, offset: int, subject: string}>
     */
    public static function provideProperties(): iterable
    {
        yield 'javascript: letters, some of them Latin-1 bytes' => ['pattern' => '/^\p{L}$/D', 'target' => 'javascript', 'literal' => '/^\p{L}$/u', 'set' => '\p{L}', 'offset' => 1, 'subject' => 'é'];
        yield 'javascript: digits, none of them above 0x7F' => ['pattern' => '/^\p{Nd}$/D', 'target' => 'javascript', 'literal' => '/^\p{Nd}$/u', 'set' => '\p{Nd}', 'offset' => 1, 'subject' => '٣'];
        yield 'javascript: a negated property' => ['pattern' => '/^a\P{L}$/D', 'target' => 'javascript', 'literal' => '/^a\P{L}$/u', 'set' => '\P{L}', 'offset' => 2, 'subject' => "a\u{A0}"];
        yield 'javascript: a property in a class' => ['pattern' => '/^[a-f\p{Nd}]$/D', 'target' => 'javascript', 'literal' => '/^[a-f\p{Nd}]$/u', 'set' => '[a-f\p{Nd}]', 'offset' => 1, 'subject' => '٣'];
        yield 'html-pattern: digits' => ['pattern' => '/^\p{Nd}$/', 'target' => 'html-pattern', 'literal' => '^\p{Nd}$', 'set' => '\p{Nd}', 'offset' => 1, 'subject' => '٣'];
        yield 'html-pattern: a property in a negated class' => ['pattern' => '/^[^\P{Nd}]$/', 'target' => 'html-pattern', 'literal' => '^[^\P{Nd}]$', 'set' => '[^\P{Nd}]', 'offset' => 1, 'subject' => '٣'];
    }

    /**
     * @return iterable<string, array{pattern: string, target: string, set: string, offset: int, subject: string}>
     */
    public static function provideFirstSets(): iterable
    {
        foreach (self::TARGETS as $target) {
            yield $target.': three sets, the dot first' => ['pattern' => '/^.[^a]\S$/', 'target' => $target, 'set' => '.', 'offset' => 1, 'subject' => "\xE9bc"];
            yield $target.': after sets that stay in ASCII' => ['pattern' => '/^\d+[a-z]\w\D$/', 'target' => $target, 'set' => '\D', 'offset' => 11, 'subject' => "1a_\xE9"];
            yield $target.': the same set twice' => ['pattern' => '/^[^,]+,[^,]+$/', 'target' => $target, 'set' => '[^,]', 'offset' => 1, 'subject' => "\xE9,b"];
            yield $target.': in a group, before one outside it' => ['pattern' => '/^(a|[^b])\S$/', 'target' => $target, 'set' => '[^b]', 'offset' => 4, 'subject' => "\xE9c"];
            yield $target.': in a lookbehind' => ['pattern' => '/(?<=\W)a.$/', 'target' => $target, 'set' => '\W', 'offset' => 4, 'subject' => "\xE9ab"];
            yield $target.': a member of a class is the class' => ['pattern' => '/^[a\D]$/', 'target' => $target, 'set' => '[a\D]', 'offset' => 1, 'subject' => "\xE9"];
            // The offset is the one in the pattern as written, delimiters left out.
            yield $target.': a quoted dot is a literal' => ['pattern' => '/^\Q.\E\S$/', 'target' => $target, 'set' => '\S', 'offset' => 6, 'subject' => ".\xE9"];
            yield $target.': under x, after a space and before a comment' => ['pattern' => "/^ a [^b] # .\n $/x", 'target' => $target, 'set' => '[^b]', 'offset' => 4, 'subject' => "a\xE9"];
            yield $target.': between other delimiters' => ['pattern' => '#^a.$#', 'target' => $target, 'set' => '.', 'offset' => 2, 'subject' => "a\xE9"];
            yield $target.': a dot under an inline s' => ['pattern' => '/^(?s)a.$/', 'target' => $target, 'set' => '.', 'offset' => 6, 'subject' => "a\xE9"];
        }

        // Python splits the lookbehind, and writes its branches in order.
        yield 'python: in the second branch of a lookbehind' => ['pattern' => '/(?<=ab|\W)a.$/', 'target' => 'python', 'set' => '\W', 'offset' => 7, 'subject' => "\xE9ab"];
    }

    /**
     * @return iterable<string, array{pattern: string, offset: ?int}>
     */
    public static function provideClassesUnderExtendedMore(): iterable
    {
        yield 'no xx' => ['pattern' => '/^[ ^a]$/', 'offset' => null];
        yield 'x alone' => ['pattern' => '/(?x)^[ ^a]$/', 'offset' => null];
        yield 'another letter alone' => ['pattern' => '/(?i)^[ ^a]$/', 'offset' => null];
        yield 'a caret alone' => ['pattern' => '/(?^)^[ ^a]$/', 'offset' => null];
        yield 'xx' => ['pattern' => '/(?xx)^[ ^a]$/', 'offset' => 6];
        yield 'xx beside another letter' => ['pattern' => '/(?xx-i)^[ ^a]$/', 'offset' => 8];
        yield 'three of them' => ['pattern' => '/(?xxx)^[ ^a]$/', 'offset' => 7];
        yield 'xx after a caret' => ['pattern' => '/(?^xx)^[ ^a]$/', 'offset' => 7];
        yield 'xx after x' => ['pattern' => '/(?x)(?xx)^[ ^a]$/', 'offset' => 10];
        yield 'xx kept by another letter' => ['pattern' => '/(?xx)(?i)^[ ^a]$/', 'offset' => 10];
        yield 'xx ended by x alone' => ['pattern' => '/(?xx)(?x)^[ ^a]$/', 'offset' => null];
        yield 'xx ended by x unset' => ['pattern' => '/(?xx)(?-x)^[ ^a]$/', 'offset' => null];
        yield 'xx ended by a caret' => ['pattern' => '/(?xx)(?^)^[ ^a]$/', 'offset' => null];
        yield 'xx set and x unset at once' => ['pattern' => '/(?xx-x)^[ ^a]$/', 'offset' => null];
        yield 'x set and xx unset at once' => ['pattern' => '/(?x-xx)^[ ^a]$/', 'offset' => null];
        yield 'xx for a group' => ['pattern' => '/^(?xx:[ ^a])$/', 'offset' => 6];
        yield 'after the group xx was set for' => ['pattern' => '/^(?xx:b)?[ ^a]$/', 'offset' => null];
        yield 'after the group xx was set in' => ['pattern' => '/^(?:(?xx)b)?[ ^a]$/', 'offset' => null];
        yield 'in the alternative after the one xx was set in' => ['pattern' => '/^(?:(?xx)b|[ ^a])$/', 'offset' => 11];
        yield 'xx ended for a group' => ['pattern' => '/(?xx)^(?x:[ ^a])$/', 'offset' => null];
        yield 'xx kept in a group that sets another letter' => ['pattern' => '/(?xx)^(?i:[ ^a])$/', 'offset' => 10];
        yield 'xx back after the group that ended it' => ['pattern' => '/(?xx)^(?x:b)?[ ^a]$/', 'offset' => 13];
    }

    /**
     * @return iterable<string, array{pattern: string, target: string, byte: string}>
     */
    public static function provideRefusedSets(): iterable
    {
        foreach (self::TARGETS as $target) {
            // "\R" takes NEL alone above 0x7F, which "\V" leaves out.
            foreach (['\H' => "\xE9", '\V' => "\xE9", '\N' => "\xE9", '\C' => "\xE9", '\R' => "\x85", '\X' => "\xE9"] as $set => $byte) {
                yield $target.': '.$set => ['pattern' => '/^'.$set.'$/', 'target' => $target, 'byte' => $byte];
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, target: string}>
     */
    public static function providePatternsRefusedUnderU(): iterable
    {
        foreach (self::TARGETS as $target) {
            yield $target => ['pattern' => "/a(?#\xFF)./", 'target' => $target];
        }
    }

    /**
     * @return iterable<string, array{target: string}>
     */
    public static function provideTargets(): iterable
    {
        foreach (self::TARGETS as $target) {
            yield $target => ['target' => $target];
        }
    }

    private static function warning(string $set, int $offset): string
    {
        return \sprintf('Without the u flag "%s" at offset %d takes one byte in PCRE and one character in this target, so the two differ on a subject holding a character outside ASCII; under u, for UTF-8 subjects, they agree. Add u.', $set, $offset);
    }
}
