<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Internal\NoJit;

/**
 * "(*NO_JIT)" leads the pattern, whatever its delimiter: one the verb itself
 * holds, as "_" or "*", moves to another, and the pattern means the same.
 */
final class NoJitTest extends TestCase
{
    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_pattern_matches_as_before_without_the_jit(string $pattern, array $subjects): void
    {
        $checked = NoJit::pattern($pattern);

        $this->assertStringContainsString('(*NO_JIT)', $checked);
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject, $expected), preg_match($checked, $subject, $actual), $subject);
            $this->assertSame($expected, $actual, $subject);
        }
    }

    /**
     * A pattern with no closing delimiter, or one whose body holds every
     * other delimiter, is left as it is: the engine refuses the first, and
     * the second keeps the JIT.
     */
    #[Test]
    public function test_a_pattern_that_cannot_move_is_left_as_it_is(): void
    {
        $this->assertSame('_a', NoJit::pattern('_a'));
        $this->assertSame("_\x01#~%!@;,_", NoJit::pattern("_\x01#~%!@;,_"));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function providePatterns(): iterable
    {
        yield 'slash' => ['/a(b)/i', ['xAB', 'ab', 'b']];
        yield 'bracket' => ['(a(b))', ['ab', 'a']];
        yield 'underscore' => ['_a\\_(b)_i', ['A_B', 'ab']];
        yield 'star' => ['*a\\*+(b)*', ['a**b', 'ab']];
        yield 'closing parenthesis' => [')a\\)b)i', ['A)B', 'ab']];
        yield 'leading white space' => [" \n_a_", ['a', 'b']];
        yield 'body holding a control character' => ["_a\x01_", ["a\x01", 'a']];
    }
}
