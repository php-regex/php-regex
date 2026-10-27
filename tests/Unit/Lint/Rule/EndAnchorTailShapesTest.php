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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An end anchor followed by items that need a character after it: the
 * shapes those items take, a lookahead over an alternation, a conditional
 * or a script run, a lookbehind after the last newline, and a call that
 * accepts. Each finding is held to the engine over every subject of up to
 * four characters drawn from the pattern's own: none matches.
 */
final class EndAnchorTailShapesTest extends TestCase
{
    private const IMPOSSIBLE_END = 'regex.lint.anchor.impossible.end';

    /**
     * @param list<string> $alphabet
     */
    #[Test]
    #[DataProvider('provideImpossibleTails')]
    public function test_an_end_anchor_the_items_after_it_cannot_pass_is_reported(string $pattern, array $alphabet): void
    {
        foreach (self::subjects($alphabet) as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject), \sprintf('Oracle: %s matches %s.', $pattern, json_encode($subject)));
        }

        $this->assertContains(self::IMPOSSIBLE_END, self::ids($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, alphabet: list<string>}>
     */
    public static function provideImpossibleTails(): iterable
    {
        yield 'a lookahead over two branches, one the newline' => ['pattern' => '/x\Z(?=a|\n)y/', 'alphabet' => ['x', 'a', 'y', "\n"]];
        yield 'a lookahead over two branches, neither the newline' => ['pattern' => '/x\Z(?=a|b)y/', 'alphabet' => ['x', 'a', 'b', 'y', "\n"]];
        yield 'a lookahead over a conditional' => ['pattern' => '/x\Z(?=(?(1)\n|a))y(b)/', 'alphabet' => ['x', 'a', 'b', 'y', "\n"]];
        yield 'a lookahead over a script run' => ['pattern' => '/x\Z(?=(*sr:\n))y/', 'alphabet' => ['x', 'y', "\n"]];
        yield 'a script run of the newline before a letter' => ['pattern' => '/x\Z(?:(*script_run:\n))b/', 'alphabet' => ['x', 'b', "\n"]];
        yield 'a lookbehind after the last newline that ends on a letter' => ['pattern' => '/a$\n(?<=ab)/', 'alphabet' => ['a', 'b', "\n"]];
    }

    /**
     * A call of a group holding an "(*ACCEPT)" after the anchor returns
     * from the group, and the pattern goes on to the "z" the anchor leaves
     * no room for; the group itself ends the whole match on its accept
     * before the anchor is reached, with nothing matched.
     */
    #[Test]
    public function test_an_accepting_call_after_the_anchor_does_not_pass_it(): void
    {
        $pattern = '/((?:(*ACCEPT))x)y\Z(?1)z/';
        foreach (self::subjects(['x', 'y', 'z', "\n"]) as $subject) {
            $this->assertSame(1, preg_match($pattern, $subject, $match));
            $this->assertSame('', $match[0], \sprintf('Oracle: the match of %s went through the anchor.', json_encode($subject)));
        }

        $this->assertContains(self::IMPOSSIBLE_END, self::ids($pattern));
    }

    /**
     * A lookbehind after the last newline that may end on it stays silent.
     */
    #[Test]
    public function test_a_lookbehind_that_may_end_on_the_newline_is_not_reported(): void
    {
        $this->assertNotContains(self::IMPOSSIBLE_END, self::ids('/a$\n(?<=b\n)/'));
    }

    /**
     * @param list<string> $alphabet
     *
     * @return list<string>
     */
    private static function subjects(array $alphabet): array
    {
        $subjects = [''];
        $layer = [''];
        for ($length = 1; $length <= 4; $length++) {
            $next = [];
            foreach ($layer as $prefix) {
                foreach ($alphabet as $character) {
                    $next[] = $prefix.$character;
                }
            }
            $subjects = [...$subjects, ...$next];
            $layer = $next;
        }

        return $subjects;
    }

    /**
     * @return list<string>
     */
    private static function ids(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        return array_values(array_map(static fn ($violation): string => $violation->id, $linter->getIssues()));
    }
}
