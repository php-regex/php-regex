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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Tests\TestUtils\PhpErrorOffset;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * From PCRE2 10.43, "\N{U+" followed by nothing but spaces or tabs up to
 * its brace is U+0000: the release skips the padding after "{" before it
 * asks for a digit, but enters "\N{U+" past that check, reads no digit,
 * then skips the padding before "}". "\N{U+}" stays refused.
 */
final class NamedCodePointPaddingTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_padding_alone_after_u_plus_names_nul(string $pattern, bool $inClass): void
    {
        if (version_compare(explode(' ', \PCRE_VERSION)[0], '10.43', '>=')) {
            $this->assertSame([1, 0, 0], [preg_match($pattern, "\0"), preg_match($pattern, 'N'), preg_match($pattern, ' ')], 'Oracle.');
        }

        $regex = Regex::create(['cache' => null, 'php_version' => '8.4']);
        $this->assertTrue($regex->validate($pattern)->isValid, (string) $regex->validate($pattern)->error);

        $node = $regex->parse($pattern)->pattern;
        if ($inClass) {
            $this->assertInstanceOf(CharClassNode::class, $node);
            $node = $node->expression;
        }

        $this->assertInstanceOf(CharLiteralNode::class, $node, $pattern);
        $this->assertSame(CharLiteralType::UnicodeNamed, $node->type);
        $this->assertSame(0, $node->codePoint, $pattern);
    }

    /**
     * Like every "\N{U+...}", the padded one needs UTF mode: PCRE2 10.47
     * and later refuse it past its closing brace.
     */
    #[Test]
    #[DataProvider('provideNonUtfPatterns')]
    public function test_padding_alone_after_u_plus_needs_utf(string $pattern, int $offset): void
    {
        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame(ErrorCode::UnicodeNamedRequiresUtf, $result->errorCode);
        $this->assertSame(PhpErrorOffset::of($pattern), $result->offset, $pattern);
        if (PhpErrorOffset::runsPcre1047()) {
            $this->assertSame($offset, $result->offset, $pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideNonUtfPatterns(): iterable
    {
        yield 'outside a class: /\\N{U+ }/' => ['pattern' => '/\\N{U+ }/', 'offset' => 7];
        yield 'in a class: /[\\N{U+ }]/' => ['pattern' => '/[\\N{U+ }]/', 'offset' => 8];
    }

    /**
     * @return iterable<string, array{pattern: string, inClass: bool}>
     */
    public static function providePatterns(): iterable
    {
        yield 'one space: /\\N{U+ }/u' => ['pattern' => '/\\N{U+ }/u', 'inClass' => false];
        yield 'two tabs: /\\N{U+\\t\\t}/u' => ['pattern' => "/\\N{U+\t\t}/u", 'inClass' => false];
        yield 'padded both sides of U+: /\\N{ U+ }/u' => ['pattern' => '/\\N{ U+ }/u', 'inClass' => false];
        yield 'in a class: /[\\N{U+ }]/u' => ['pattern' => '/[\\N{U+ }]/u', 'inClass' => true];
    }
}
