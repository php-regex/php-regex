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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PythonTranspilerTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    public function test_transpiles_basic_pattern_to_python_raw_string(): void
    {
        $result = Regex::create()->transpile('/foo/im', 'python');

        $this->assertSame('python', $result->target);
        $this->assertSame('foo', $result->pattern);
        // /m is applied while printing; without u PCRE reads ASCII, as re.ASCII does.
        $this->assertSame('ai', $result->flags);
        // Python strings carry no flags of their own, so they go inline.
        $this->assertSame("r'(?ai)foo'", $result->literal);
    }

    #[Test]
    public function test_drops_the_study_flag(): void
    {
        // Oracle: /S changes nothing since PHP 7.3.
        $this->assertSame(1, preg_match('/ab+c/S', 'xabbc'));

        $result = Regex::create(['cache' => null])->transpile('/ab+c/S', 'python');

        $this->assertSame("r'(?a)ab+c'", $result->literal);
        $this->assertSame('a', $result->flags);
        $this->assertContains('Dropped /S: PHP has ignored it since 7.3, under PCRE2.', $result->notes);
    }

    #[Test]
    public function test_keeps_the_flags_after_the_study_flag(): void
    {
        $result = Regex::create(['cache' => null])->transpile('/ab+c/Si', 'python');

        $this->assertSame("r'(?ai)ab+c'", $result->literal);
    }

    #[Test]
    public function test_keeps_python_style_named_groups_and_backrefs(): void
    {
        $result = Regex::create()->transpile('/(?P<w>\w+)(?P=w)/', 'python');

        $this->assertSame("r'(?a)(?P<w>\\w+)(?P=w)'", $result->literal);
    }

    #[Test]
    public function test_converts_quoted_and_braced_named_backreferences(): void
    {
        // Oracle: \k'n' and \k{n} are \k<n> in PCRE2.
        $this->assertSame(1, preg_match("/(?<n>a)\\k'n'/", 'aa'));
        $this->assertSame(1, preg_match('/(?<n>a)\\k{n}/', 'aa'));

        $this->assertSame("r'(?a)(?P<n>a)(?P=n)'", Regex::create()->transpile("/(?<n>a)\\k'n'/", 'python')->literal);
        $this->assertSame("r'(?a)(?P<n>a)(?P=n)'", Regex::create()->transpile('/(?<n>a)\\k{n}/', 'python')->literal);
    }

    #[Test]
    public function test_converts_g_backreference_to_numeric(): void
    {
        $result = Regex::create()->transpile('/(a)\g{1}/', 'python');

        $this->assertSame("r'(?a)(a)\\1'", $result->literal);
    }

    #[Test]
    public function test_keeps_lookarounds(): void
    {
        $regex = Regex::create();

        $this->assertSame("r'(?a)a(?=b)'", $regex->transpile('/a(?=b)/', 'python')->literal);
        $this->assertSame("r'(?a)(?<=x)y'", $regex->transpile('/(?<=x)y/', 'python')->literal);
    }

    /**
     * The lookahead emulation, "(?=(?P<tmp>x))(?P=tmp)", adds a group: every
     * group after it moves up a number, and a second one does not compile.
     */
    #[Test]
    public function test_refuses_atomic_groups(): void
    {
        try {
            Regex::create()->transpile('/(?>x)y/', 'python');
            $message = '';
        } catch (TranspileException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame('Atomic groups are not supported in Python re.', $message);
    }

    #[Test]
    public function test_converts_horizontal_whitespace_to_character_class(): void
    {
        $result = Regex::create()->transpile('/\h+/', 'python');

        $this->assertStringStartsWith("r'(?a)[\\x09\\x20", $result->literal);
        $this->assertNotEmpty($result->notes);
    }

    #[Test]
    public function test_rejects_keep_escape(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains('\K is not supported in Python re.');

        Regex::create()->transpile('/a\Kb/', 'python');
    }

    #[Test]
    public function test_rejects_possessive_quantifiers(): void
    {
        $this->expectException(TranspileException::class);

        Regex::create()->transpile('/a++b/', 'python');
    }

    #[Test]
    public function test_a_pattern_holding_a_quote_picks_another_string_delimiter(): void
    {
        $regex = Regex::create();

        // Backslash-escaping the quote inside a raw string would leave the
        // backslash in the pattern.
        $this->assertSame('r"(?a)a\'b"', $regex->transpile("/a'b/", 'python')->literal);
        $this->assertSame('r\'(?a)a"b\'', $regex->transpile('/a"b/', 'python')->literal);
    }

    #[Test]
    public function test_a_pattern_holding_both_quotes_falls_back_to_a_plain_string(): void
    {
        $regex = Regex::create();

        $result = $regex->transpile('/a\'b"c/', 'python');

        $this->assertSame("'(?a)a\\'b\"c'", $result->literal);
        $this->assertSame("re.compile('a\\'b\"c', re.ASCII)", $result->constructor);
    }

    #[Test]
    public function test_the_constructor_keeps_the_flags_out_of_the_pattern(): void
    {
        $regex = Regex::create();

        $result = $regex->transpile('/foo/imsx', 'python');

        $this->assertSame(
            "re.compile(r'foo', re.ASCII | re.IGNORECASE | re.DOTALL)",
            $result->constructor,
        );
    }

    #[Test]
    public function test_rejects_unicode_named_character_name_form(): void
    {
        // Oracle: preg_match() refuses \N{LATIN SMALL LETTER A}: "PCRE2
        // does not support \F, \L, \l, \N{name}, \U, or \u".
        try {
            Regex::create(['cache' => null])->transpile('/\N{LATIN SMALL LETTER A}/u', 'python');
            $message = '';
        } catch (TranspileException $e) {
            $message = $e->getMessage();
        }

        // the engine read the name where PCRE2 supports it, and the
        // transpiler says what to write instead; where PCRE2 predates
        // named characters, the name never resolves and the transpiler
        // says so in its own words
        $this->assertContains($message, [
            'PCRE2 does not support \N{name}: write the character as \N{U+hhhh}.',
            'Unicode named character escape with unknown name cannot be converted.',
        ]);
    }

    #[Test]
    public function test_rejects_unicode_named_character_unknown_name(): void
    {
        // Oracle: \N{UNKNOWN} is refused by PCRE2 (no such name). The
        // library parser keeps it as codePoint = -1; the printer must not
        // silently emit \xFFFFFFFFFFFFFFFF.
        $this->expectException(TranspileException::class);

        Regex::create(['cache' => null])->transpile('/\N{UNKNOWN NAME}/u', 'python');
    }
}
