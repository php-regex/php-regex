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

use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where Python's re reads a construct otherwise than PCRE, the construct is
 * spelled out or refused. Each pattern was checked with Python 3.10 and
 * 3.13: re.compile(pattern, flags).search(subject) gives the spans
 * preg_match() gives, the whole match first, then every group by number,
 * null for one unset.
 */
final class PythonEngineParityTest extends TestCase
{
    use PcreGates;
    use PhpUnitForwardCompat;

    /**
     * The rows that hold from a PCRE2 newer than the floor: "\w" takes the
     * marks and the connectors from 10.43.
     */
    private const NEWER_RELEASES = ['10.43' => ['/\w/u', '/e\b/u']];

    /**
     * @param list<?array{int, int}>|null $spans byte offsets, as preg_match() gives them
     */
    #[Test]
    #[DataProvider('provideSpelledPatterns')]
    public function test_spells_what_python_reads_otherwise(string $pattern, string $python, string $flags, string $subject, ?array $spans): void
    {
        $this->assertSame($spans, self::spans($pattern, $subject), 'Oracle: '.$pattern);

        $result = Regex::create(['cache' => null])->transpile($pattern, 'python');

        $this->assertSame($python, $result->pattern);
        $this->assertSame($flags, $result->flags);
    }

    /**
     * @return iterable<string, array{pattern: string, python: string, flags: string, subject: string, spans: list<?array{int, int}>|null}>
     */
    public static function provideSpelledPatterns(): iterable
    {
        // Without u, PCRE reads "\w", "\d", "\s", "\b" and case as ASCII: re.ASCII.
        yield 'a word character without u' => ['pattern' => '/^\w+$/', 'python' => '^\w+$', 'flags' => 'a', 'subject' => 'café', 'spans' => null];
        yield 'a digit without u' => ['pattern' => '/\d/', 'python' => '\d', 'flags' => 'a', 'subject' => '٣', 'spans' => null];
        yield 'a space without u' => ['pattern' => '/\s/', 'python' => '\s', 'flags' => 'a', 'subject' => "\x1C", 'spans' => null];
        yield 'a digit under u' => ['pattern' => '/\d/u', 'python' => '\d', 'flags' => '', 'subject' => '٣', 'spans' => [[0, 2]]];
        yield 'a space under u' => ['pattern' => '/\s/u', 'python' => '[\t-\r \x85\xA0\u1680\u180E\u2000-\u200A\u2028\u2029\u202F\u205F\u3000]', 'flags' => '', 'subject' => "\x1C\u{180E}", 'spans' => [[1, 4]]];
        yield 'a non-space under u' => ['pattern' => '/\S/u', 'python' => '[^\t-\r \x85\xA0\u1680\u180E\u2000-\u200A\u2028\u2029\u202F\u205F\u3000]', 'flags' => '', 'subject' => "\x1C", 'spans' => [[0, 1]]];
        // A scoped or a bare inline flag: the atoms it governs carry it.
        yield 'a scoped caseless group' => ['pattern' => '/a(?i:b)c/u', 'python' => 'a(?:(?i:b))c', 'flags' => '', 'subject' => 'aBc', 'spans' => [[0, 3]]];
        yield 'a bare caseless switch' => ['pattern' => '/a(?i)b|c/u', 'python' => 'a(?i:b)|(?i:c)', 'flags' => '', 'subject' => 'C', 'spans' => [[0, 1]]];
        yield 'a caseless switch turned off' => ['pattern' => '/a(?-i)b/iu', 'python' => 'a(?-i:b)', 'flags' => 'i', 'subject' => 'AB', 'spans' => null];
        // "\Z" is PCRE's end or before a final newline; Python's "\Z" is PCRE's "\z".
        yield 'the end or before a final newline' => ['pattern' => '/a\Z/', 'python' => 'a(?=\n?\Z)', 'flags' => 'a', 'subject' => "a\n", 'spans' => [[0, 1]]];
        yield 'the end of the subject' => ['pattern' => '/a\z/', 'python' => 'a\Z', 'flags' => 'a', 'subject' => "a\n", 'spans' => null];
        yield 'a dollar under D' => ['pattern' => '/a$/D', 'python' => 'a\Z', 'flags' => 'a', 'subject' => "a\n", 'spans' => null];
        // Under m a caret does not match after a newline that ends the subject.
        yield 'a caret under m' => ['pattern' => '/^$/m', 'python' => '(?:^|(?<=\n)(?!\Z))(?=\n|\Z)', 'flags' => 'a', 'subject' => "a\n", 'spans' => null];
        yield 'a dollar under m' => ['pattern' => '/a$/m', 'python' => 'a(?=\n|\Z)', 'flags' => 'a', 'subject' => "a\nb", 'spans' => [[0, 1]]];
        yield 'the dot under an inline s' => ['pattern' => '/a(?s).b/', 'python' => 'a[\s\S]b', 'flags' => 'a', 'subject' => "a\nb", 'spans' => [[0, 3]]];
        yield 'the dot under an inline -s' => ['pattern' => '/a(?-s:.)b/s', 'python' => 'a(?:[^\n])b', 'flags' => 'as', 'subject' => "a\nb", 'spans' => null];
        // /x is applied while reading: an escaped space or "#" stays a literal.
        yield 'an escaped space under x' => ['pattern' => '/a\ b/x', 'python' => 'a b', 'flags' => 'a', 'subject' => 'a b', 'spans' => [[0, 3]]];
        yield 'an escaped hash under x' => ['pattern' => '/a\#b/x', 'python' => 'a#b', 'flags' => 'a', 'subject' => 'a#b', 'spans' => [[0, 3]]];
        // /U swaps greedy and lazy.
        yield 'the ungreedy flag' => ['pattern' => '/<.+>/U', 'python' => '<.+?>', 'flags' => 'a', 'subject' => '<a><b>', 'spans' => [[0, 3]]];
        yield 'an inline ungreedy switch' => ['pattern' => '/a(?U)b+/', 'python' => 'ab+?', 'flags' => 'a', 'subject' => 'abbb', 'spans' => [[0, 2]]];
        yield 'a lookahead that may be left out' => ['pattern' => '/(?=a)*b/', 'python' => 'b', 'flags' => 'a', 'subject' => 'b', 'spans' => [[0, 1]]];
        yield 'a control escape that is no letter' => ['pattern' => '/\c?/', 'python' => '\x7F', 'flags' => 'a', 'subject' => "\x7F", 'spans' => [[0, 1]]];
        yield 'horizontal space in a class' => ['pattern' => '/[\hx]/', 'python' => '[\x09\x20\xA0\u1680\u180E\u2000-\u200A\u202F\u205F\u3000x]', 'flags' => 'a', 'subject' => ' ', 'spans' => [[0, 1]]];
        // Before 3.14 Python's "\B" fails on the empty subject.
        yield 'no word boundary in the empty subject' => ['pattern' => '/\B/', 'python' => '(?:\B|\A\Z)', 'flags' => 'a', 'subject' => '', 'spans' => [[0, 0]]];
        // Python's IGNORECASE matches "I", "i", "İ" and "ı" with one another; PCRE pairs "I" with "i" alone.
        yield 'a caseless i under u' => ['pattern' => '/i/iu', 'python' => '(?-i:[Ii])', 'flags' => 'i', 'subject' => 'İ', 'spans' => null];
        yield 'a caseless class under u' => ['pattern' => '/[a-z]/iu', 'python' => '(?-i:[A-Za-z\u017F\u212A])', 'flags' => 'i', 'subject' => 'ı', 'spans' => null];
        yield 'a negated caseless class under u' => ['pattern' => '/[^a-z]/iu', 'python' => '(?-i:[^A-Za-z\u017F\u212A])', 'flags' => 'i', 'subject' => 'İ', 'spans' => [[0, 2]]];
        yield 'a caseless letter with no dotted i under u' => ['pattern' => '/k/iu', 'python' => 'k', 'flags' => 'i', 'subject' => "\u{212A}", 'spans' => [[0, 3]]];
        yield 'a backreference before a digit' => ['pattern' => '/(a)\1(?#)2/', 'python' => '(a)(?:\1)2', 'flags' => 'a', 'subject' => 'aa2', 'spans' => [[0, 3], [0, 1]]];
        yield 'a caseless class that takes every character' => ['pattern' => '/(?i)[\x00-\x{398}\x{39a}-\x{10ffff}]/u', 'python' => '[\s\S]', 'flags' => '', 'subject' => "\u{399}", 'spans' => [[0, 2]]];
        yield 'letters around the dotted i under u' => ['pattern' => '/HJhj\x{12F}\x{132}/iu', 'python' => 'HJhj\u012F\u0132', 'flags' => 'i', 'subject' => "hjHJ\u{12E}\u{133}", 'spans' => [[0, 8]]];
        yield 'the dotted capital I under u' => ['pattern' => '/\x{130}/iu', 'python' => '(?-i:[\u0130])', 'flags' => 'i', 'subject' => 'i', 'spans' => null];
        yield 'the dotless small i under u' => ['pattern' => '/\x{131}/iu', 'python' => '(?-i:[\u0131])', 'flags' => 'i', 'subject' => 'I', 'spans' => null];
        yield 'an i among other letters under u' => ['pattern' => '/éIx/iu', 'python' => 'é(?-i:[Ii])x', 'flags' => 'i', 'subject' => 'ÉiX', 'spans' => [[0, 4]]];
        yield 'two adjacent letters with their cases' => ['pattern' => '/[ij]/iu', 'python' => '(?-i:[IJij])', 'flags' => 'i', 'subject' => 'J', 'spans' => [[0, 1]]];
        yield 'a range with a third case' => ['pattern' => '/[i-k]/iu', 'python' => '(?-i:[I-Ki-k\u212A])', 'flags' => 'i', 'subject' => "\u{212A}", 'spans' => [[0, 3]]];
        yield 'an escape in a class under an inline caseless switch' => ['pattern' => '/a(?i)[\x41]/', 'python' => 'a(?i:[\x41])', 'flags' => 'a', 'subject' => 'aa', 'spans' => [[0, 2]]];
        yield 'a quoted run with an i under u' => ['pattern' => '/\QéIx\E/iu', 'python' => 'é(?-i:[Ii])x', 'flags' => 'i', 'subject' => 'ÉiX', 'spans' => [[0, 4]]];
        yield 'a quoted run starting with an I under u' => ['pattern' => '/\QIé\E/iu', 'python' => '(?-i:[Ii])é', 'flags' => 'i', 'subject' => 'iÉ', 'spans' => [[0, 3]]];
        yield 'a class around the dotted i under u' => ['pattern' => '/[hj\x{12F}\x{132}]/iu', 'python' => '[hj\u012F\u0132]', 'flags' => 'i', 'subject' => 'H', 'spans' => [[0, 1]]];
        yield 'a class of a capital I under u' => ['pattern' => '/[I]/iu', 'python' => '(?-i:[Ii])', 'flags' => 'i', 'subject' => 'i', 'spans' => [[0, 1]]];
        yield 'a lookbehind over alternatives of different lengths' => ['pattern' => '/(?<=a|bc)d/', 'python' => '(?:(?<=a)|(?<=bc))d', 'flags' => 'a', 'subject' => 'bcd', 'spans' => [[2, 3]]];
        yield 'a negative lookbehind over alternatives of different lengths' => ['pattern' => '/(?<!a|bc)d/', 'python' => '(?<!a)(?<!bc)d', 'flags' => 'a', 'subject' => 'bcd', 'spans' => null];
        yield 'a space in a class under u' => ['pattern' => '/[\sx]/u', 'python' => '[\t-\r \x85\xA0\u1680\u180E\u2000-\u200A\u2028\u2029\u202F\u205F\u3000x]', 'flags' => '', 'subject' => "\u{85}", 'spans' => [[0, 2]]];
        yield 'a backreference under i without u' => ['pattern' => '/(a)\1/i', 'python' => '(a)\1', 'flags' => 'ai', 'subject' => 'aA', 'spans' => [[0, 2], [0, 1]]];
        yield 'an inline comment' => ['pattern' => '/a(?#c)b/', 'python' => 'ab', 'flags' => 'a', 'subject' => 'ab', 'spans' => [[0, 2]]];
        yield 'horizontal space' => ['pattern' => '/\h/', 'python' => '[\x09\x20\xA0\u1680\u180E\u2000-\u200A\u202F\u205F\u3000]', 'flags' => 'a', 'subject' => "\t", 'spans' => [[0, 1]]];
        yield 'a group that may be unset' => ['pattern' => '/(a)|b\1/', 'python' => '(a)|b\1', 'flags' => 'a', 'subject' => 'b', 'spans' => null];
        yield 'the start of the subject' => ['pattern' => '/\Afoo/', 'python' => '\Afoo', 'flags' => 'a', 'subject' => 'foo', 'spans' => [[0, 3]]];
        yield 'control characters in a literal' => ['pattern' => "/a\t\r\fb/", 'python' => 'a\t\r\fb', 'flags' => 'a', 'subject' => "a\t\r\fb", 'spans' => [[0, 5]]];
        // Under u "\S" leaves out more than Python's: the class keeps its other members beside it.
        yield 'a non-space beside a member under u' => ['pattern' => '/[\Sa]/u', 'python' => '(?:[a]|[^\t-\r \x85\xA0\u1680\u180E\u2000-\u200A\u2028\u2029\u202F\u205F\u3000])', 'flags' => '', 'subject' => ' b', 'spans' => [[1, 2]]];
        yield 'no non-space among spaces under u' => ['pattern' => '/[\Sa]/u', 'python' => '(?:[a]|[^\t-\r \x85\xA0\u1680\u180E\u2000-\u200A\u2028\u2029\u202F\u205F\u3000])', 'flags' => '', 'subject' => "\u{2028} \u{85}", 'spans' => null];
        yield 'a named character under u' => ['pattern' => '/\N{U+2022}/u', 'python' => '\u2022', 'flags' => '', 'subject' => "\u{2022}", 'spans' => [[0, 3]]];
    }

    /**
     * @param list<?array{int, int}>|null $spans byte offsets, as preg_match() gives them
     */
    #[Test]
    #[DataProvider('provideRefusedPatterns')]
    public function test_refuses_what_python_cannot_read_as_pcre_does(string $pattern, string $message, string $subject, ?array $spans, string $python): void
    {
        $this->requiresPcre2For($pattern, self::NEWER_RELEASES);
        // What PCRE does and what Python would do in its place, checked with
        // python3, differ: the construct is refused.
        $this->assertSame($spans, self::spans($pattern, $subject), 'Oracle: '.$pattern.'; Python '.$python);

        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains($message);

        Regex::create(['cache' => null])->transpile($pattern, 'python');
    }

    /**
     * @return iterable<string, array{pattern: string, message: string, subject: string, spans: list<?array{int, int}>|null, python: string}>
     */
    public static function provideRefusedPatterns(): iterable
    {
        yield 'an atomic group' => [
            'pattern' => '/(?>a)(b)\1/', 'message' => 'Atomic groups are not supported in Python re.',
            'subject' => 'abb', 'spans' => [[0, 3], [1, 2]], 'python' => 'the emulation (?=(?P<tmp>a))(?P=tmp) adds a group and shifts every number after it',
        ];
        yield 'a word character under u' => [
            'pattern' => '/\w/u', 'message' => 'Python re reads \w otherwise than PCRE under u',
            'subject' => "\u{301}", 'spans' => [[0, 2]], 'python' => 'leaves the combining acute accent out of \w',
        ];
        yield 'a word boundary under u' => [
            'pattern' => '/e\b/u', 'message' => 'Python re reads \b otherwise than PCRE under u',
            'subject' => "e\u{301}", 'spans' => null, 'python' => 'finds a boundary before the combining accent',
        ];
        yield 'the start of the match attempt' => [
            'pattern' => '/\Ga/', 'message' => '\G is not supported in Python re.',
            'subject' => 'ba', 'spans' => null, 'python' => 'has no \G: "bad escape"',
        ];
        yield 'a reference inside its own group' => [
            'pattern' => '/(a|b\1)+/', 'message' => 'A backreference to a group that has not closed before it cannot be carried into Python re.',
            'subject' => 'aba', 'spans' => [[0, 3], [1, 3]], 'python' => 'refuses it: "cannot refer to an open group"',
        ];
        yield 'a forward reference' => [
            'pattern' => '/(?:(\2)?(a))+/', 'message' => 'A backreference to a group that has not closed before it cannot be carried into Python re.',
            'subject' => 'aaa', 'spans' => [[0, 3], [1, 2], [2, 3]], 'python' => 'refuses it: "invalid group reference"',
        ];
        yield 'a non-atomic lookahead' => [
            'pattern' => '/(*napla:(a+))a\1/', 'message' => 'Non-atomic assertions are not supported in Python re.',
            'subject' => 'aaa', 'spans' => [[0, 3], [0, 2]], 'python' => 'has atomic lookaheads only',
        ];
        yield 'a backreference under i and u' => [
            'pattern' => '/(i)\1/iu', 'message' => 'A backreference under /i and u cannot be carried into Python re',
            'subject' => 'iİ', 'spans' => null, 'python' => 'matches "iİ": its IGNORECASE takes "İ" for "i"',
        ];
        yield 'a backreference inside a lookbehind' => [
            'pattern' => '/(?<!(foo)a\1)bar/', 'message' => 'A backreference inside a lookbehind cannot be carried into Python re',
            'subject' => 'bar', 'spans' => [[0, 3], null], 'python' => 'refuses it: "cannot refer to group defined in the same lookbehind subpattern"',
        ];
        yield 'a group in a lookahead that may be left out' => [
            'pattern' => '/(?=(a))?a/', 'message' => 'A capturing group in an assertion that may be left out cannot be carried into this target',
            'subject' => 'a', 'spans' => [[0, 1], [0, 1]], 'python' => 'leaves the group unset',
        ];
    }

    #[Test]
    public function test_notes_the_whitespace_classes(): void
    {
        $this->assertContains('Converted \h to character class.', Regex::create(['cache' => null])->transpile('/\h/', 'python')->notes);
        $this->assertContains('Dropped comments during transpilation.', Regex::create(['cache' => null])->transpile('/a(?#c)b/', 'python')->notes);
    }

    /**
     * @return list<?array{int, int}>|null
     */
    private static function spans(string $pattern, string $subject): ?array
    {
        if (1 !== preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        return array_values(array_map(static fn (array $match): ?array => null === $match[0] ? null : [$match[1], $match[1] + \strlen($match[0])], array_filter($matches, is_int(...), \ARRAY_FILTER_USE_KEY)));
    }
}
