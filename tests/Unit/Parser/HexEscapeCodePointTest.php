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
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "\x" reads one or two hexadecimal digits: "\x0" is NUL, "\xA" a line feed,
 * and "\x4g" the character 4 followed by "g" (PHP matches each).
 */
final class HexEscapeCodePointTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEscapes')]
    public function test_the_escape_carries_its_code_point(string $pattern, string $subject, int $codePoint): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);

        $node = Regex::create(['cache' => null])->parse($pattern)->pattern;
        $first = $node instanceof SequenceNode ? $node->children[1] : $node;

        $this->assertInstanceOf(CharLiteralNode::class, $first);
        $this->assertSame($codePoint, $first->codePoint, $pattern);
    }

    #[Test]
    public function test_a_bare_x_is_nul_where_pcre_takes_it(): void
    {
        // PCRE2 10.40 to 10.44, which PHP 8.2 to 8.5 bundle, read "\x" with
        // no digit as NUL, and "\xg" as NUL then "g"; 10.45 refuses it at
        // the character after "\x" (pcre2test on each).
        foreach ([80200, 80400, 80500] as $phpVersion) {
            $regex = Regex::create(['cache' => null, 'php_version' => $phpVersion]);
            $node = $regex->parse('/a\\xg/')->pattern;

            $this->assertInstanceOf(SequenceNode::class, $node);
            $this->assertInstanceOf(CharLiteralNode::class, $node->children[1]);
            $this->assertSame(0, $node->children[1]->codePoint);
            $this->assertTrue($regex->validate('/a\\xg/')->isValid);
            $this->assertSame(['a'], $regex->literals('/a\\xg/')->literalSet->prefixes);
        }

        $running = Regex::create(['cache' => null])->validate('/a\\xg/');
        if (version_compare(explode(' ', \PCRE_VERSION)[0], '10.45', '>=')) {
            $this->assertSame(ErrorCode::EscapeDigitsMissing, $running->errorCode);
            $this->assertSame(3, $running->offset);
        } else {
            $this->assertTrue($running->isValid);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, codePoint: int}>
     */
    public static function provideEscapes(): iterable
    {
        yield 'one digit, NUL' => ['pattern' => '/^\\x0$/', 'subject' => "\0", 'codePoint' => 0];
        yield 'one letter digit' => ['pattern' => '/^\\xA$/', 'subject' => "\n", 'codePoint' => 10];
        yield 'one digit before a letter' => ['pattern' => '/^\\x4g$/', 'subject' => "\x04g", 'codePoint' => 4];
        yield 'two digits' => ['pattern' => '/^\\x41$/', 'subject' => 'A', 'codePoint' => 65];
    }
}
