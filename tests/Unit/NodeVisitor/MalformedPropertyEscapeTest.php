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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "\p" and "\P" take a one-letter property or a braced name. Anything else is
 * a compile error: PCRE2 error 146 (malformed) or 147 (the empty name "{}").
 *
 * The offsets are PCRE2's body offsets, the same on 10.40, 10.44 and 10.48
 * (pcre2test), except where two are listed: 10.48 steps past a whole UTF-8
 * character, 10.40 and 10.44 past one byte.
 */
final class MalformedPropertyEscapeTest extends TestCase
{
    /**
     * @param list<int> $offsets
     */
    #[Test]
    #[DataProvider('provideMalformedPropertyEscapes')]
    public function test_validate_rejects_malformed_property_escape_at_pcre_offset(string $pattern, array $offsets): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', $pattern));
        $this->assertStringStartsWith('regex.unicode.property_', (string) $result->errorCode?->value);
        $this->assertContains(
            $result->offset,
            $offsets,
            \sprintf('%s reported at offset %s, PCRE2 reports %s.', $pattern, var_export($result->offset, true), implode(' or ', $offsets)),
        );
    }

    #[Test]
    #[DataProvider('provideWellFormedPropertyEscapes')]
    public function test_validate_accepts_well_formed_property_escape(string $pattern): void
    {
        $this->assertSame(0, preg_match($pattern, ''));

        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles but was reported invalid: %s', $pattern, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, offsets: list<int>}>
     */
    public static function provideMalformedPropertyEscapes(): iterable
    {
        yield 'bare \\p' => ['pattern' => '/\\p/', 'offsets' => [2]];
        yield 'bare \\P' => ['pattern' => '/\\P/', 'offsets' => [2]];
        yield '\\p before a digit' => ['pattern' => '/\\p1/', 'offsets' => [3]];
        yield '\\p before a caret' => ['pattern' => '/\\p^L/u', 'offsets' => [3]];
        yield '\\p before an escaped backslash' => ['pattern' => '/\\p\\\\/', 'offsets' => [3]];
        yield '\\p before \\E' => ['pattern' => '/\\p\\E{L}/u', 'offsets' => [3]];
        yield '\\P before \\E' => ['pattern' => '/\\P\\E{L}/u', 'offsets' => [3]];
        yield '\\p before \\Q' => ['pattern' => '/\\p\\QL\\E/u', 'offsets' => [3]];
        yield '\\p before a space under x' => ['pattern' => '/(?x)\\p L/', 'offsets' => [7]];
        yield '\\p before a two-byte character' => ['pattern' => "/\\p\u{e9}/", 'offsets' => [3]];
        yield '\\p before a two-byte character under UTF' => ['pattern' => "/\\p\u{e9}/u", 'offsets' => [4, 3]];
        yield 'unclosed brace' => ['pattern' => '/\\p{/', 'offsets' => [3]];
        yield 'unclosed brace after a letter' => ['pattern' => '/a\\p{/', 'offsets' => [4]];
        yield 'unclosed brace with a name' => ['pattern' => '/\\p{Lu/u', 'offsets' => [5]];
        yield 'empty braces' => ['pattern' => '/\\p{}/', 'offsets' => [4]];
        yield 'bare \\p in a class' => ['pattern' => '/[\\p]/', 'offsets' => [4]];
        yield 'bare \\p as range start' => ['pattern' => '/[\\p-z]/', 'offsets' => [4]];
        yield 'bare \\p quantified' => ['pattern' => '/\\p+/', 'offsets' => [3]];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideWellFormedPropertyEscapes(): iterable
    {
        yield 'one-letter property' => ['pattern' => '/\\pL/u'];
        yield 'braced property' => ['pattern' => '/\\p{Lu}/u'];
        yield 'negated braced property' => ['pattern' => '/\\P{^Lu}/u'];
        yield 'property then \\E' => ['pattern' => '/\\pL\\E/u'];
        yield 'property in a class' => ['pattern' => '/[\\pL\\P{N}]/u'];
        yield 'quoted \\p' => ['pattern' => '/\\Q\\p\\E/'];
        yield 'escaped backslash then p' => ['pattern' => '/\\\\p/'];
    }
}
