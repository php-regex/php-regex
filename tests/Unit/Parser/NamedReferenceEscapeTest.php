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

use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "\k" takes a group name in angle brackets, braces or single quotes, and
 * PCRE refuses every other shape: an opener with nothing readable after it
 * (error 162), a name that starts with a digit (144), or a name its closer
 * does not end (142).
 *
 * The offsets are PCRE2's body offsets, the same on 10.40, 10.44 and 10.48
 * (pcre2test), except where two are listed: 10.48 steps past a leading
 * digit, 10.40 and 10.44 stop on it.
 */
final class NamedReferenceEscapeTest extends TestCase
{
    /**
     * @param list<int> $offsets
     */
    #[Test]
    #[DataProvider('provideMalformedNamedReferences')]
    public function test_validate_rejects_malformed_named_reference_at_pcre_offset(string $pattern, array $offsets): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', $pattern));
        $this->assertContains(
            $result->offset,
            $offsets,
            \sprintf('%s reported at offset %s, PCRE2 reports %s.', $pattern, var_export($result->offset, true), implode(' or ', $offsets)),
        );
    }

    #[Test]
    #[DataProvider('provideWellFormedNamedReferences')]
    public function test_validate_accepts_well_formed_named_reference(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''));

        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles but was reported invalid: %s', $pattern, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, offsets: list<int>}>
     */
    public static function provideMalformedNamedReferences(): iterable
    {
        yield 'angle bracket at the end' => ['pattern' => '/\\k</', 'offsets' => [3]];
        yield 'brace at the end' => ['pattern' => '/\\k{/', 'offsets' => [3]];
        yield 'quote at the end' => ['pattern' => "/\\k'/", 'offsets' => [3]];
        yield 'angle-bracketed name never closed' => ['pattern' => '/\\k<a/', 'offsets' => [4]];
        yield 'braced name never closed' => ['pattern' => '/\\k{ab/', 'offsets' => [5]];
        yield 'quoted name never closed' => ['pattern' => "/\\k'a/", 'offsets' => [4]];
        yield 'name never closed after a letter' => ['pattern' => '/x\\k<ab/', 'offsets' => [6]];
        yield 'hyphen inside the name' => ['pattern' => '/\\k<a-b>/', 'offsets' => [4]];
        yield 'space inside the name under x' => ['pattern' => '/\\k<a b>/x', 'offsets' => [4]];
        yield 'quote end inside the name' => ['pattern' => '/\\k<a\\E>/', 'offsets' => [4]];
        yield 'digit first, never closed' => ['pattern' => '/\\k<9/', 'offsets' => [4, 3]];
        yield 'digit first, closed' => ['pattern' => '/\\k<9a>/', 'offsets' => [4, 3]];
        yield 'digit first in braces' => ['pattern' => '/\\k{1,}/', 'offsets' => [4, 3]];
        yield 'bracket instead of a name' => ['pattern' => '/\\k<]/', 'offsets' => [3]];
        yield 'escape instead of a name' => ['pattern' => '/\\k<\\S/', 'offsets' => [3]];
        yield 'non-ASCII letter without UTF' => ['pattern' => "/\\k<\u{e9}>/", 'offsets' => [3]];
        yield 'non-ASCII letter under UTF, never closed' => ['pattern' => "/\\k<\u{e9}/u", 'offsets' => [5]];
        yield 'letters under UTF, never closed' => ['pattern' => "/\\k<a\u{e9}/u", 'offsets' => [6]];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideWellFormedNamedReferences(): iterable
    {
        yield 'angle brackets' => ['pattern' => '/(?<a>x)\\k<a>/'];
        yield 'braces' => ['pattern' => '/(?<ab>x)\\k{ab}/'];
        yield 'quotes' => ['pattern' => "/(?<a_1>x)\\k'a_1'/"];
        yield 'non-ASCII name under UTF' => ['pattern' => "/(?<\u{e9}>x)\\k<\u{e9}>/u"];
        yield 'quoted \\k' => ['pattern' => '/\\Q\\k<\\E/'];
    }
}
