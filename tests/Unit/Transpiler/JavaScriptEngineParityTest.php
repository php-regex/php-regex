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
use PHPRegex\Transpiler\TranspileOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where JavaScript reads a construct otherwise than PCRE, the construct is
 * spelled out or refused. Each literal was checked with Node 24: exec() on
 * the subject, from its start, gives the spans preg_match() gives, the
 * whole match first, then every group by number, null for one unset.
 */
final class JavaScriptEngineParityTest extends TestCase
{
    use PcreGates;
    use PhpUnitForwardCompat;

    /**
     * The rows that hold from a PCRE2 newer than the floor: "{,3}" is a
     * quantifier, and "\w" takes the marks and the connectors, from 10.43.
     */
    private const NEWER_RELEASES = ['10.43' => ['/a{,3}b/', '/\w/u', '/\W/u', '/\bé/u', '/a\B/u', '/[\W\d]/u', '/[^\W\d]/u', '/[\W]/u', '/[^\S\W]/u', '/[^x\S\W]/u']];

    /**
     * @param list<?array{int, int}>|null $spans byte offsets, as preg_match() gives them
     */
    #[Test]
    #[DataProvider('provideSpelledPatterns')]
    public function test_spells_what_javascript_reads_otherwise(string $pattern, string $literal, string $subject, ?array $spans): void
    {
        $this->requiresPcre2For($pattern, self::NEWER_RELEASES);
        $this->assertSame($spans, self::spans($pattern, $subject), 'Oracle: '.$pattern);

        $result = Regex::create(['cache' => null])->transpile($pattern, 'javascript');

        $this->assertSame($literal, $result->literal);
    }

    /**
     * @return iterable<string, array{pattern: string, literal: string, subject: string, spans: list<?array{int, int}>|null}>
     */
    public static function provideSpelledPatterns(): iterable
    {
        // "$" also matches before a newline that ends the subject; JavaScript's only at its end.
        yield 'a dollar before a final newline' => ['pattern' => '/a$/', 'literal' => '/a(?=\n?$)/', 'subject' => "a\n", 'spans' => [[0, 1]]];
        yield 'a dollar under D' => ['pattern' => '/a$/D', 'literal' => '/a$/', 'subject' => "a\n", 'spans' => null];
        yield 'the end of the subject' => ['pattern' => '/a\z/', 'literal' => '/a$/', 'subject' => "a\n", 'spans' => null];
        yield 'the end or before a final newline' => ['pattern' => '/a\Z/', 'literal' => '/a(?=\n?$)/', 'subject' => "a\n", 'spans' => [[0, 1]]];
        yield 'the start of the subject' => ['pattern' => '/\Aa/', 'literal' => '/^a/', 'subject' => 'ab', 'spans' => [[0, 1]]];
        // Under m a line ends at "\n" only: JavaScript also ends one at "\r", U+2028 and U+2029.
        yield 'a caret under m' => ['pattern' => '/^b/m', 'literal' => '/(?:^|(?<=\n)(?!$))b/', 'subject' => "a\rb", 'spans' => null];
        yield 'a caret after a final newline' => ['pattern' => '/^/m', 'literal' => '/(?:^|(?<=\n)(?!$))/', 'subject' => "\n", 'spans' => [[0, 0]]];
        yield 'a dollar under m' => ['pattern' => '/a$/m', 'literal' => '/a(?=\n|$)/', 'subject' => "a\rb", 'spans' => null];
        // The dot leaves out "\n" alone; JavaScript's also "\r", U+2028 and U+2029.
        yield 'the dot' => ['pattern' => '/a.b/', 'literal' => '/a[^\n]b/', 'subject' => "a\rb", 'spans' => [[0, 3]]];
        yield 'the dot under s' => ['pattern' => '/a.b/s', 'literal' => '/a.b/s', 'subject' => "a\nb", 'spans' => [[0, 3]]];
        yield 'the dot under an inline s' => ['pattern' => '/a(?s).b/', 'literal' => '/a[\s\S]b/', 'subject' => "a\nb", 'spans' => [[0, 3]]];
        yield 'the dot under an inline -s' => ['pattern' => '/a(?-s:.)b/s', 'literal' => '/a(?:[^\n])b/s', 'subject' => "a\nb", 'spans' => null];
        // Under u PHP turns on UCP: "\d", "\w", "\s" and "\b" read Unicode; JavaScript's stay ASCII.
        yield 'a digit under u' => ['pattern' => '/\d/u', 'literal' => '/\p{Nd}/u', 'subject' => '٣', 'spans' => [[0, 2]]];
        yield 'a non-digit under u' => ['pattern' => '/\D/u', 'literal' => '/\P{Nd}/u', 'subject' => '٣', 'spans' => null];
        yield 'a word character under u' => ['pattern' => '/\w/u', 'literal' => '/[\p{L}\p{N}_\p{Mn}\p{Pc}]/u', 'subject' => 'é', 'spans' => [[0, 2]]];
        yield 'a non-word character under u' => ['pattern' => '/\W/u', 'literal' => '/[^\p{L}\p{N}_\p{Mn}\p{Pc}]/u', 'subject' => 'é', 'spans' => null];
        yield 'a space under u' => ['pattern' => '/\s/u', 'literal' => '/[\t-\r \x85\xA0\u1680\u180E\u2000-\u200A\u2028\u2029\u202F\u205F\u3000]/u', 'subject' => "\u{85}", 'spans' => [[0, 2]]];
        yield 'a non-space under u' => ['pattern' => '/\S/u', 'literal' => '/[^\t-\r \x85\xA0\u1680\u180E\u2000-\u200A\u2028\u2029\u202F\u205F\u3000]/u', 'subject' => "\u{FEFF}", 'spans' => [[0, 3]]];
        yield 'a word boundary under u' => ['pattern' => '/\bé/u', 'literal' => '/(?:(?<=[\p{L}\p{N}_\p{Mn}\p{Pc}])(?![\p{L}\p{N}_\p{Mn}\p{Pc}])|(?<![\p{L}\p{N}_\p{Mn}\p{Pc}])(?=[\p{L}\p{N}_\p{Mn}\p{Pc}]))é/u', 'subject' => 'é', 'spans' => [[0, 2]]];
        yield 'no word boundary under u' => ['pattern' => '/a\B/u', 'literal' => '/a(?:(?<=[\p{L}\p{N}_\p{Mn}\p{Pc}])(?=[\p{L}\p{N}_\p{Mn}\p{Pc}])|(?<![\p{L}\p{N}_\p{Mn}\p{Pc}])(?![\p{L}\p{N}_\p{Mn}\p{Pc}]))/u', 'subject' => 'aé', 'spans' => [[0, 1]]];
        yield 'a digit in a class under u' => ['pattern' => '/[\d_]/u', 'literal' => '/[\p{Nd}_]/u', 'subject' => '٣', 'spans' => [[0, 2]]];
        yield 'a space in a class under u' => ['pattern' => '/[\sx]/u', 'literal' => '/[\t-\r \x85\xA0\u1680\u180E\u2000-\u200A\u2028\u2029\u202F\u205F\u3000x]/u', 'subject' => "\u{A0}", 'spans' => [[0, 2]]];
        yield 'a non-word character in a class under u' => ['pattern' => '/[\W\d]/u', 'literal' => '/(?:[\p{Nd}]|[^\p{L}\p{N}_\p{Mn}\p{Pc}])/u', 'subject' => 'é٣', 'spans' => [[2, 4]]];
        yield 'a non-word character in a negated class under u' => ['pattern' => '/[^\W\d]/u', 'literal' => '/(?:(?![\p{Nd}])[\p{L}\p{N}_\p{Mn}\p{Pc}])/u', 'subject' => '٣é', 'spans' => [[2, 4]]];
        // An assertion repeated is read once; one that may be left out changes nothing.
        yield 'a lookahead that may be left out' => ['pattern' => '/(?=a)*b/', 'literal' => '/b/', 'subject' => 'b', 'spans' => [[0, 1]]];
        yield 'a lookahead repeated' => ['pattern' => '/(?=a)+a/', 'literal' => '/(?=a)a/', 'subject' => 'a', 'spans' => [[0, 1]]];
        yield 'a lookbehind that may be left out' => ['pattern' => '/(?<=a)?b/', 'literal' => '/b/', 'subject' => 'b', 'spans' => [[0, 1]]];
        // "{,3}" is "{0,3}" from PCRE2 10.43; JavaScript reads no such quantifier.
        yield 'an open lower bound' => ['pattern' => '/a{,3}b/', 'literal' => '/a{0,3}b/', 'subject' => 'aab', 'spans' => [[0, 3]]];
        // "\c?" is DEL; JavaScript only takes a letter after "\c".
        yield 'a control escape that is no letter' => ['pattern' => '/\c?/', 'literal' => '/\x7F/', 'subject' => "\x7F", 'spans' => [[0, 1]]];
        yield 'horizontal space in a class' => ['pattern' => '/[\hx]/', 'literal' => '/[\x09\x20\xA0\u1680\u180E\u2000-\u200A\u202F\u205F\u3000x]/', 'subject' => ' ', 'spans' => [[0, 1]]];
        yield 'vertical space in a class' => ['pattern' => '/[\vx]/', 'literal' => '/[\x0A-\x0D\x85\u2028\u2029x]/', 'subject' => "\r", 'spans' => [[0, 1]]];
        yield 'a Cased_Letter property' => ['pattern' => '/\p{L&}/u', 'literal' => '/\p{LC}/u', 'subject' => 'a', 'spans' => [[0, 1]]];
        yield 'a property spelled loosely' => ['pattern' => '/\p{ lu }/u', 'literal' => '/\p{Lu}/u', 'subject' => 'A', 'spans' => [[0, 1]]];
        yield 'a binary property spelled loosely' => ['pattern' => '/\p{white space}/u', 'literal' => '/\p{White_Space}/u', 'subject' => ' ', 'spans' => [[0, 1]]];
        yield 'a binary property by its other short name' => ['pattern' => '/\p{WSpace}/u', 'literal' => '/\p{White_Space}/u', 'subject' => ' ', 'spans' => [[0, 1]]];
        yield 'a class of one non-word character under u' => ['pattern' => '/[\W]/u', 'literal' => '/(?:[^\p{L}\p{N}_\p{Mn}\p{Pc}])/u', 'subject' => 'é-', 'spans' => [[2, 3]]];
        yield 'a negated class of two complements under u' => ['pattern' => '/[^\S\W]/u', 'literal' => '/(?:(?=[\t-\r \x85\xA0\u1680\u180E\u2000-\u200A\u2028\u2029\u202F\u205F\u3000])[\p{L}\p{N}_\p{Mn}\p{Pc}])/u', 'subject' => 'a ', 'spans' => null];
        yield 'a bracket before a dot in a composed class' => ['pattern' => '/[\S[.]/u', 'literal' => '/(?:[\[.]|[^\t-\r \x85\xA0\u1680\u180E\u2000-\u200A\u2028\u2029\u202F\u205F\u3000])/u', 'subject' => '[', 'spans' => [[0, 1]]];
        yield 'horizontal space' => ['pattern' => '/\h/', 'literal' => '/[\x09\x20\xA0\u1680\u180E\u2000-\u200A\u202F\u205F\u3000]/', 'subject' => "\t", 'spans' => [[0, 1]]];
        yield 'word boundaries without u' => ['pattern' => '/a\bb\B/', 'literal' => '/a\bb\B/', 'subject' => 'a bb', 'spans' => null];
        yield 'a control letter' => ['pattern' => '/\cA/', 'literal' => '/\cA/', 'subject' => "\x01", 'spans' => [[0, 1]]];
        yield 'a character without case above ASCII under i' => ['pattern' => '/a中/i', 'literal' => '/a中/i', 'subject' => 'A中', 'spans' => [[0, 4]]];
        yield 'a repetition that may match empty after a longer match' => ['pattern' => '/(?:a?b?)*c/', 'literal' => '/(?:a?b?)*c/', 'subject' => 'abc', 'spans' => [[0, 3]]];
        yield 'a repetition whose last alternative is empty' => ['pattern' => '/(?:a|)*b/', 'literal' => '/(?:a|)*b/', 'subject' => 'aab', 'spans' => [[0, 3]]];
        yield 'a negated class of complements and a member under u' => ['pattern' => '/[^x\S\W]/u', 'literal' => '/(?:(?![x])(?=[\t-\r \x85\xA0\u1680\u180E\u2000-\u200A\u2028\u2029\u202F\u205F\u3000])[\p{L}\p{N}_\p{Mn}\p{Pc}])/u', 'subject' => 'x', 'spans' => null];
        yield 'a repeated group of a class' => ['pattern' => '/([ab])+/', 'literal' => '/([ab])+/', 'subject' => 'ab', 'spans' => [[0, 2], [1, 2]]];
        yield 'a repeated group of the dot' => ['pattern' => '/(.)+/', 'literal' => '/([^\n])+/', 'subject' => 'ab', 'spans' => [[0, 2], [1, 2]]];
        yield 'a repeated group of an escape' => ['pattern' => '/(\x41)+/', 'literal' => '/(\x41)+/', 'subject' => 'AA', 'spans' => [[0, 2], [1, 2]]];
        yield 'a repeated group of a property' => ['pattern' => '/(\pL)+/u', 'literal' => '/(\p{L})+/u', 'subject' => 'ab', 'spans' => [[0, 2], [1, 2]]];
        yield 'a repeated group of a control letter' => ['pattern' => '/(\cA)+/', 'literal' => '/(\cA)+/', 'subject' => "\x01", 'spans' => [[0, 1], [0, 1]]];
        yield 'a lookahead without a group that may be left out' => ['pattern' => '/(?=(?:a))?b/', 'literal' => '/b/', 'subject' => 'b', 'spans' => [[0, 1]]];
        yield 'a quoted run with a character without case under i' => ['pattern' => '/\Qa中\E/i', 'literal' => '/a中/i', 'subject' => 'A中', 'spans' => [[0, 4]]];
        yield 'a backreference before a digit' => ['pattern' => '/(a)\1 2/x', 'literal' => '/(a)(?:\1)2/', 'subject' => 'aa2', 'spans' => [[0, 3], [0, 1]]];
        yield 'a backreference to a group always set' => ['pattern' => '/(a)(?:b|c)\1/', 'literal' => '/(a)(?:b|c)\1/', 'subject' => 'aca', 'spans' => [[0, 3], [0, 1]]];
        yield 'a group set by each repetition' => ['pattern' => '/(?:(a)b)+/', 'literal' => '/(?:(a)b)+/', 'subject' => 'abab', 'spans' => [[0, 4], [2, 3]]];
    }

    /**
     * @param list<?array{int, int}>|null $spans byte offsets, as preg_match() gives them
     */
    #[Test]
    #[DataProvider('provideRefusedPatterns')]
    public function test_refuses_what_javascript_cannot_read_as_pcre_does(string $pattern, string $message, string $subject, ?array $spans, string $javaScript): void
    {
        // What PCRE does and what JavaScript would do in its place, checked
        // with node, differ: the construct is refused.
        $this->requiresPcre2For($pattern, ['10.45' => ['/\p{Lu}/iu']]);
        $this->assertSame($spans, self::spans($pattern, $subject), 'Oracle: '.$pattern.'; JavaScript '.$javaScript);

        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains($message);

        Regex::create(['cache' => null])->transpile($pattern, 'javascript');
    }

    /**
     * @return iterable<string, array{pattern: string, message: string, subject: string, spans: list<?array{int, int}>|null, javaScript: string}>
     */
    public static function provideRefusedPatterns(): iterable
    {
        yield 'a reference to a group that may be unset' => [
            'pattern' => '/(a)|b\1/', 'message' => 'A backreference to a group that may not have matched cannot be carried into JavaScript',
            'subject' => 'b', 'spans' => null, 'javaScript' => 'matches "b": a reference to an unset group matches the empty string',
        ];
        yield 'a reference inside its own group' => [
            'pattern' => '/(a|b\1)+/', 'message' => 'A backreference to a group that may not have matched cannot be carried into JavaScript',
            'subject' => 'aba', 'spans' => [[0, 3], [1, 3]], 'javaScript' => 'matches "ab" only: the reference matches empty inside its own group',
        ];
        yield 'a group a repetition may skip' => [
            'pattern' => '/(?:(a)|b)+/', 'message' => 'A capturing group a repetition may leave unset cannot be carried into JavaScript',
            'subject' => 'ab', 'spans' => [[0, 2], [0, 1]], 'javaScript' => 'leaves the group unset: each repetition starts with its groups unset',
        ];
        yield 'a group in a repetition that may match empty' => [
            'pattern' => '/(a?)+/', 'message' => 'A capturing group in a repetition that may match empty cannot be carried into JavaScript',
            'subject' => 'a', 'spans' => [[0, 1], [1, 1]], 'javaScript' => 'gives the group "a": an empty repetition is never taken',
        ];
        yield 'a group in a lookahead that may be left out' => [
            'pattern' => '/(?=(a))?a/', 'message' => 'A capturing group in an assertion that may be left out cannot be carried into this target',
            'subject' => 'a', 'spans' => [[0, 1], [0, 1]], 'javaScript' => 'leaves the group unset',
        ];
        yield 'a repetition that prefers to match empty' => [
            'pattern' => '/(?:a|(?=b)|.)*/', 'message' => 'A repetition that may match empty before it tries a longer match cannot be carried into JavaScript',
            'subject' => 'b', 'spans' => [[0, 0]], 'javaScript' => 'matches "b": the empty repetition is refused, and "." taken instead',
        ];
        yield 'a lazy repetition that may be left out' => [
            'pattern' => '/(?:a??)*/', 'message' => 'A repetition that may match empty before it tries a longer match cannot be carried into JavaScript',
            'subject' => 'a', 'spans' => [[0, 0]], 'javaScript' => 'matches "a": the empty repetition is refused, and "a" taken instead',
        ];
        yield 'an empty alternative first in a repetition' => [
            'pattern' => '/(?:|a)*/', 'message' => 'A repetition that may match empty before it tries a longer match cannot be carried into JavaScript',
            'subject' => 'a', 'spans' => [[0, 0]], 'javaScript' => 'matches "a"',
        ];
        yield 'a lazy repetition nested in a repetition' => [
            'pattern' => '/(?:(?:a??)b?)*/', 'message' => 'A repetition that may match empty before it tries a longer match cannot be carried into JavaScript',
            'subject' => 'a', 'spans' => [[0, 0]], 'javaScript' => 'matches "a"',
        ];
        yield 'a greedy repetition made lazy by U' => [
            'pattern' => '/(?:a?)*/U', 'message' => 'A repetition that may match empty before it tries a longer match cannot be carried into JavaScript',
            'subject' => 'a', 'spans' => [[0, 0]], 'javaScript' => 'matches "a" with "(?:a??)*"',
        ];
        yield 'a reference to a group one alternative sets' => [
            'pattern' => '/(?:(a)|b)\1/', 'message' => 'A backreference to a group that may not have matched cannot be carried into JavaScript',
            'subject' => 'b', 'spans' => null, 'javaScript' => 'matches "b"',
        ];
        yield 'a reference to a group in a negative lookahead' => [
            'pattern' => '/(?!(a))\1/', 'message' => 'A backreference to a group that may not have matched cannot be carried into JavaScript',
            'subject' => 'b', 'spans' => null, 'javaScript' => 'matches the empty string',
        ];
        yield 'a group in a repetition whose body may match empty' => [
            'pattern' => '/((?:a?)+)*/', 'message' => 'A capturing group in a repetition that may match empty cannot be carried into JavaScript',
            'subject' => 'b', 'spans' => [[0, 0], [0, 0]], 'javaScript' => 'leaves the group unset',
        ];
        yield 'a named group alone in a lookahead that may be left out' => [
            'pattern' => '/(?=(?<n>a))?a/', 'message' => 'A capturing group in an assertion that may be left out cannot be carried into this target',
            'subject' => 'a', 'spans' => [[0, 1], [0, 1]], 'javaScript' => 'leaves the group unset',
        ];
        yield 'a named reference to a group that may be unset' => [
            'pattern' => '/(?<n>a)|b\k<n>/', 'message' => 'A backreference to a group that may not have matched cannot be carried into JavaScript',
            'subject' => 'b', 'spans' => null, 'javaScript' => 'matches "b"',
        ];
        yield 'a named group in a lookahead that may be left out' => [
            'pattern' => '/(?=(?:(?<n>a)))?a/', 'message' => 'A capturing group in an assertion that may be left out cannot be carried into this target',
            'subject' => 'a', 'spans' => [[0, 1], [0, 1]], 'javaScript' => 'leaves the group unset',
        ];
        yield 'an uppercase character above ASCII under i without u' => [
            'pattern' => '/É/i', 'message' => 'Without u, PCRE matches a character above ASCII in its own case only under /i',
            'subject' => 'é', 'spans' => null, 'javaScript' => '/É/i matches "é"',
        ];
        yield 'a case property under i' => [
            'pattern' => '/\p{Lu}/iu', 'message' => 'A case property under /i cannot be carried into JavaScript',
            'subject' => 'ı', 'spans' => [[0, 2]], 'javaScript' => 'leaves out "ı", which no uppercase letter folds to',
        ];
        yield 'a character above ASCII under i without u' => [
            'pattern' => '/é/i', 'message' => 'Without u, PCRE matches a character above ASCII in its own case only under /i',
            'subject' => 'É', 'spans' => null, 'javaScript' => '/é/i matches "É"',
        ];
        yield 'a non-atomic lookahead' => [
            'pattern' => '/(*napla:(a+))a\1/', 'message' => 'Non-atomic assertions are not supported in JavaScript.',
            'subject' => 'aaa', 'spans' => [[0, 3], [0, 2]], 'javaScript' => 'has atomic lookaheads only: (?=(a+))a\1 fails on "aaa"',
        ];
        yield 'a property of PCRE alone' => [
            'pattern' => '/\p{Xan}/u', 'message' => 'Unsupported Unicode property in JavaScript: Xan.',
            'subject' => '1', 'spans' => [[0, 1]], 'javaScript' => 'has no property Xan: the pattern does not compile',
        ];
    }

    #[Test]
    public function test_a_caret_under_m_needs_lookbehind(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains('Lookbehind is disabled for JavaScript targets');

        Regex::create(['cache' => null])->transpile('/^a/m', 'javascript', new TranspileOptions(allowLookbehind: false));
    }

    #[Test]
    public function test_a_word_boundary_under_u_needs_lookbehind(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains('Lookbehind is disabled for JavaScript targets');

        Regex::create(['cache' => null])->transpile('/\bé/u', 'javascript', new TranspileOptions(allowLookbehind: false));
    }

    /**
     * @param array{string, string} $expected the message, and the pattern from the position it points at, the body counted after the delimiter
     */
    #[Test]
    #[DataProvider('provideRefusalsAndTheirPositions')]
    public function test_a_refusal_names_the_construct_and_points_at_it(string $pattern, ?TranspileOptions $options, array $expected): void
    {
        try {
            Regex::create(['cache' => null])->transpile($pattern, 'javascript', $options);
            $refusal = null;
        } catch (TranspileException $e) {
            $refusal = [$e->getMessage(), substr($pattern, 1 + (int) $e->position)];
        }

        $this->assertSame($expected, $refusal);
    }

    /**
     * @return iterable<string, array{pattern: string, options: ?TranspileOptions, expected: array{string, string}}>
     */
    public static function provideRefusalsAndTheirPositions(): iterable
    {
        yield 'the start of the match attempt' => ['pattern' => '/\Ga/', 'options' => null, 'expected' => ['Unsupported assertion in JavaScript: \G.', '\Ga/']];
        yield 'a word boundary under u without lookbehind' => ['pattern' => '/a\b/u', 'options' => new TranspileOptions(allowLookbehind: false), 'expected' => ['Lookbehind is disabled for JavaScript targets: \b under u needs one to read a Unicode word boundary.', '\b/u']];
        // The first construct JavaScript reads otherwise is the one named, and pointed at.
        yield 'a divergence inside a repetition that diverges too' => ['pattern' => '/(?:(a)|b\1)+/', 'options' => null, 'expected' => ['A backreference to a group that may not have matched cannot be carried into JavaScript: there it matches the empty string, where PCRE fails.', '\1)+/']];
        yield 'the first of two divergences' => ['pattern' => '/(a)|b\1(?:(c)|d)+/', 'options' => null, 'expected' => ['A backreference to a group that may not have matched cannot be carried into JavaScript: there it matches the empty string, where PCRE fails.', '\1(?:(c)|d)+/']];
    }

    #[Test]
    public function test_notes_the_whitespace_classes(): void
    {
        $this->assertContains('Converted \h (horizontal whitespace) to character class.', Regex::create(['cache' => null])->transpile('/\h/', 'javascript')->notes);
        $this->assertContains('Converted \v (vertical whitespace) to character class.', Regex::create(['cache' => null])->transpile('/\v/', 'javascript')->notes);
    }

    #[Test]
    public function test_notes_the_flags_applied_while_printing(): void
    {
        $result = Regex::create(['cache' => null])->transpile('/^a$/mD', 'javascript');

        $this->assertSame('', $result->flags);
        $this->assertContains('Applied /m: "^" and "$" are written for lines ended by "\n" alone, as PCRE reads them.', $result->notes);
        $this->assertContains('Applied /D: "$" is written for the end of the subject alone.', $result->notes);
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
