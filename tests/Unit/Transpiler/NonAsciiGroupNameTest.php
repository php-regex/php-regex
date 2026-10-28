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

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A group named with a letter of any script is referred to by that name in
 * each target: PCRE2 10.49, node 22 (/(?<é>a)\k<é>/u matches "aa") and
 * Python 3.10 ((?P<é>a)(?P=é) matches "aa") all read it, in every spelling
 * PCRE takes for the reference.
 */
final class NonAsciiGroupNameTest extends TestCase
{
    #[Test]
    #[DataProvider('provideReferences')]
    public function test_a_reference_to_a_group_of_any_script_is_carried(string $pattern, string $target, string $literal): void
    {
        $this->assertSame(1, preg_match($pattern, 'aa'));

        $this->assertSame($literal, Regex::create(['cache' => null])->transpile($pattern, $target)->literal);
    }

    /**
     * @return iterable<string, array{pattern: string, target: string, literal: string}>
     */
    public static function provideReferences(): iterable
    {
        foreach (['\k<é>', '\k{é}', "\\k'é'"] as $reference) {
            $pattern = '/(?<é>a)'.$reference.'/u';
            yield 'JavaScript, '.$reference => ['pattern' => $pattern, 'target' => 'javascript', 'literal' => '/(?<é>a)\k<é>/u'];
            yield 'Python, '.$reference => ['pattern' => $pattern, 'target' => 'python', 'literal' => "r'(?P<é>a)(?P=é)'"];
        }
    }
}
