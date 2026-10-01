<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Tests\TestUtils\PhpErrorOffset;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class ParserErrorTest extends TestCase
{
    public function test_throws_on_too_short_regex(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('No closing delimiter "/" found. You opened with "/"; expected closing "/". Tip: escape "/" inside the pattern (\\/) or use a different delimiter, e.g. #a#.');

        $regex = $this->createRegex();
        $regex->parse('/a');
    }

    public function test_throws_on_missing_closing_delimiter_non_standard(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('No closing delimiter "#" found. You opened with "#"; expected closing "#". Tip: escape "#" inside the pattern (\\#) or use a different delimiter, e.g. ~foo~.');

        $regex = $this->createRegex();
        $regex->parse('#foo');
    }

    public function test_throws_on_missing_closing_delimiter_brace(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('No closing delimiter "}" found. You opened with "{"; expected closing "}". Tip: escape "}" inside the pattern (\\}) or use a different delimiter, e.g. #foo#.');

        $regex = $this->createRegex();
        $regex->parse('{foo');
    }

    public function test_throws_on_unknown_flag(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unknown regex flag(s) found: "z"');

        $regex = $this->createRegex();
        $regex->parse('/abc/z');
    }

    public function test_throws_on_escaped_delimiter_as_last_char(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('No closing delimiter "/" found. You opened with "/"; expected closing "/". Tip: escape "/" inside the pattern (\\/) or use a different delimiter, e.g. #foo\\/#.');

        $regex = $this->createRegex();
        // The parser sees this as "/foo\/flags", without an unescaped ending delimiter.
        $regex->parse('/foo\/');
    }

    public function test_throws_on_quantifying_anchor(): void
    {
        $this->expectException(ParserException::class);
        // PHP: "quantifier does not follow a repeatable item", at offset 2
        // from PCRE2 10.47, 1 before.
        $this->expectExceptionMessage(\sprintf('Quantifier "*" cannot be applied to assertion or verb "^" at position %d', PhpErrorOffset::of('/^*a/')));

        $regex = $this->createRegex();
        $regex->parse('/^*a/');
    }

    public function test_throws_on_quantifying_assertion(): void
    {
        $this->expectException(ParserException::class);
        // PHP: "quantifier does not follow a repeatable item", at offset 3
        // from PCRE2 10.47, 2 before.
        $this->expectExceptionMessage(\sprintf('Quantifier "+" cannot be applied to assertion or verb "\A" at position %d', PhpErrorOffset::of('/\A+a/')));

        $regex = $this->createRegex();
        $regex->parse('/\A+a/');
    }

    public function test_throws_on_quantifying_keep_node(): void
    {
        $this->expectException(ParserException::class);
        // PHP: "quantifier does not follow a repeatable item", at offset 4
        // from PCRE2 10.47, 3 before.
        $this->expectExceptionMessage(\sprintf('Quantifier "?" cannot be applied to assertion or verb "\K" at position %d', PhpErrorOffset::of('/a\K?/')));

        $regex = $this->createRegex();
        $regex->parse('/a\K?/');
    }

    public function test_throws_on_incomplete_python_group(): void
    {
        $this->expectException(ParserException::class);
        // PHP: "unrecognized character after (?P", past the ")" from PCRE2
        // 10.47, on it before.
        $this->expectExceptionMessage(\sprintf('Invalid syntax after (?P at position %d', PhpErrorOffset::of('/(?P)/')));

        $regex = $this->createRegex();
        $regex->parse('/(?P)/');
    }

    public function test_throws_on_python_backref_without_name(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Expected group name');

        $regex = $this->createRegex();
        $regex->parse('/(?P=)/');
    }

    public function test_throws_on_invalid_token_in_group_name(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unexpected token "|" in group name');

        $regex = $this->createRegex();
        $regex->parse('/(?<a|b>)/');
    }

    private function createRegex(): Regex
    {
        return Regex::create();
    }
}
