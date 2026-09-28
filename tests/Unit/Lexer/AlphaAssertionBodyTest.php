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

namespace RegexParser\Tests\Unit\Lexer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\NodeVisitor\CompilerNodeVisitor;
use RegexParser\Regex;

/**
 * The body of "(*pla:...)" and the other alphabetic assertions is read like
 * any group body: a ")" that is escaped, quoted by \Q...\E, inside a class
 * or inside a "(?#...)" comment does not close it, and a body that never
 * closes is refused (pcre2test 10.40 and PHP on 10.48 agree on the offsets).
 */
final class AlphaAssertionBodyTest extends TestCase
{
    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideBodies')]
    public function test_validate_reads_the_body_as_pcre_does(string $pattern, array $subjects): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), \sprintf('%s should compile.', $pattern));

        $regex = Regex::create(['cache' => null]);
        $result = $regex->validate($pattern);
        $this->assertTrue($result->isValid, \sprintf('%s compiles but was reported invalid: %s', $pattern, (string) $result->error));

        $compiled = $regex->parse($pattern)->accept(new CompilerNodeVisitor());
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($compiled, $subject), \sprintf('%s compiled to %s, which disagrees on %s.', $pattern, $compiled, json_encode($subject)));
        }
    }

    #[Test]
    #[DataProvider('provideUnclosedBodies')]
    public function test_validate_refuses_a_body_that_never_closes(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', $pattern));
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideBodies(): iterable
    {
        yield 'escaped closing parenthesis' => ['pattern' => '/^(*pla:\\))./', 'subjects' => [')', 'a']];
        yield 'closing parenthesis in a class' => ['pattern' => '/^(*pla:[)])./', 'subjects' => [')', 'a']];
        yield 'bracket first in a class' => ['pattern' => '/^(*pla:[]a)])./', 'subjects' => [']', ')', 'b']];
        yield 'escaped bracket in a class' => ['pattern' => '/^(*pla:[\\])])./', 'subjects' => [']', ')', 'b']];
        yield 'POSIX class then parenthesis' => ['pattern' => '/^(*pla:[[:alpha:])])./', 'subjects' => ['a', ')', '1']];
        yield 'quoted closing parenthesis' => ['pattern' => '/^(*pla:\\Q)\\E)./', 'subjects' => [')', 'a']];
        yield 'quoted bracket in a class' => ['pattern' => '/^(*pla:[\\Q]\\E)])./', 'subjects' => [']', ')', 'a']];
        yield 'comment holding a parenthesis' => ['pattern' => '/^(*pla:(?#()a)./', 'subjects' => ['a', 'b']];
        yield 'atomic group' => ['pattern' => '/^(*atomic:\\))/', 'subjects' => [')', 'a']];
        yield 'script run' => ['pattern' => '/^(*script_run:[)])/', 'subjects' => [')', 'a']];
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideUnclosedBodies(): iterable
    {
        yield 'quote running to the end' => ['pattern' => '/(*pla:\\Qa)/', 'offset' => 10];
        yield 'quote after a letter running to the end' => ['pattern' => '/(*pla:a\\Q)/', 'offset' => 10];
        yield 'escaped parenthesis only' => ['pattern' => '/(*pla:a\\)/', 'offset' => 9];
    }
}
