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

use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Internal\InlineFlags;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(?^)" resets the options i, m, n, r, s and x; J and U survive it (PCRE2
 * 10.49, PHP 8.4):
 *   preg_match('/(?U)(?^)(a+)/', 'aaa', $m)           -> ["a", "a"]   (U still on)
 *   preg_match('/(?J)(?^)(?<d>a)(?<d>b)/', 'ab')       -> 1            (J still on)
 *   preg_match('/(?ir)(?^i)\x{212A}/u', 'k')           -> 1            (r reset)
 */
final class InlineFlagsCaretTest extends TestCase
{
    #[Test]
    public function test_caret_resets_imnrsx_only(): void
    {
        $flags = InlineFlags::read('^', InlineFlags::LETTERS.'r');
        $this->assertInstanceOf(InlineFlags::class, $flags);

        $unset = str_split($flags->unset);
        sort($unset);
        $this->assertSame(['i', 'm', 'n', 'r', 's', 'x'], $unset);
        $this->assertSame('', $flags->set);
    }

    #[Test]
    public function test_caret_with_letters_turns_them_on_and_resets_the_rest_of_imnrsx(): void
    {
        $flags = InlineFlags::read('^iJ', InlineFlags::LETTERS);
        $this->assertInstanceOf(InlineFlags::class, $flags);

        $unset = str_split($flags->unset);
        sort($unset);
        $this->assertSame(['m', 'n', 's', 'x'], $unset);
        $this->assertSame('iJ', $flags->set);
    }

    #[Test]
    #[DataProvider('provideDuplicateNamesAfterCaret')]
    public function test_duplicate_names_stay_allowed_after_a_caret(string $pattern): void
    {
        $this->assertSame(1, preg_match($pattern, 'ab'), 'the engine accepts it');

        $shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse($pattern));

        $this->assertSame([1, 2], array_keys($shape->groups));
        $this->assertSame('d', $shape->groups[1]->name);
        $this->assertSame('d', $shape->groups[2]->name);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideDuplicateNamesAfterCaret(): iterable
    {
        yield 'inline J, then a bare caret' => ['pattern' => '/(?J)(?^)(?<d>a)(?<d>b)/'];
        yield 'pattern-level J, then a caret' => ['pattern' => '/(?^)(?<d>a)(?<d>b)/J'];
        yield 'inline J, then a caret with letters' => ['pattern' => '/(?J)(?^i)(?<d>a)(?<d>b)/'];
    }
}
