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

namespace PHPRegex\Tests\Unit\Parser\Engine;

use PHPRegex\Parser\Engine\NoJit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
     * The delimiter a pattern moves to is the first of its own list that its
     * body does not hold, and none where it holds every one.
     */
    #[Test]
    #[DataProvider('provideBodies')]
    public function test_a_pattern_moves_to_a_delimiter_its_body_does_not_hold(string $body, ?string $delimiter): void
    {
        $this->assertSame($delimiter, NoJit::delimiterOutside($body));
        if (null !== $delimiter) {
            $this->assertSame(1, preg_match($delimiter.$body.$delimiter, 'a?:)'), 'Oracle: PHP refuses the moved pattern, or it matches otherwise.');
        }
    }

    /**
     * @return iterable<string, array{body: string, delimiter: string|null}>
     */
    public static function provideBodies(): iterable
    {
        yield 'a body holding no delimiter' => ['body' => 'a?:\)', 'delimiter' => "\x01"];
        yield 'a body holding a control character' => ['body' => "a|\x01", 'delimiter' => '#'];
        yield 'a body holding every delimiter but the last' => ['body' => "a|[\x01#~%!@;]", 'delimiter' => ','];
        yield 'a body holding every delimiter' => ['body' => "a|[\x01#~%!@;,]", 'delimiter' => null];
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
