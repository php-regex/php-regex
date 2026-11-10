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

namespace PHPRegex\Tests\Integration\Transpiler;

use PHPRegex\Tests\Integration\Transpiler\Oracle\TargetEngine;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the warning on a set without u says, checked in the engines: PCRE
 * takes one byte for the set and the target one character, so preg_match()
 * and the target differ on a character outside ASCII, agree on an ASCII
 * one, and agree on both under u, where no warning is given.
 *
 * The engines are those of the transpiler oracle, Node 20 or later and
 * Python 3.11 or later, and the test runs with it:
 *
 *     tools/phpunit/vendor/bin/phpunit --group transpiler-oracle
 */
#[Group('transpiler-oracle')]
final class SetsWithoutUtfOracleTest extends TestCase
{
    /**
     * Each set with a character outside ASCII the target takes for it,
     * which PCRE reads as two bytes without u, and an ASCII character with
     * whether the set takes it.
     */
    private const SETS = [
        '.' => ['character' => 'é', 'ascii' => 'x', 'takesAscii' => true],
        '[^a]' => ['character' => 'é', 'ascii' => 'x', 'takesAscii' => true],
        '\D' => ['character' => 'é', 'ascii' => 'x', 'takesAscii' => true],
        '\W' => ['character' => "\u{A0}", 'ascii' => '-', 'takesAscii' => true],
        '\S' => ['character' => 'é', 'ascii' => 'x', 'takesAscii' => true],
        '\h' => ['character' => "\u{A0}", 'ascii' => "\t", 'takesAscii' => true],
        '\v' => ['character' => "\u{85}", 'ascii' => "\x0B", 'takesAscii' => true],
        '[\Da]' => ['character' => 'é', 'ascii' => '1', 'takesAscii' => false],
        '[^\x00-\x7F]' => ['character' => 'é', 'ascii' => 'x', 'takesAscii' => false],
        '\p{L}' => ['character' => 'é', 'ascii' => 'x', 'takesAscii' => true],
    ];

    #[Test]
    #[DataProvider('provideTargets')]
    public function test_the_target_takes_a_character_where_pcre_takes_a_byte(string $target): void
    {
        $engine = 'python' === $target ? TargetEngine::python() : TargetEngine::node();
        $this->assertTrue($engine->isAvailable(), \sprintf('The %s oracle needs %s: TRANSPILER_ORACLE_NODE and TRANSPILER_ORACLE_PYTHON name other binaries.', $target, 'python' === $target ? 'Python 3.11 or later' : 'Node 20 or later'));

        $regex = Regex::create();
        $whole = 'html-pattern' === $target;
        $cases = [];
        $expected = [];
        foreach (self::SETS as $set => ['character' => $character, 'ascii' => $ascii, 'takesAscii' => $takesAscii]) {
            foreach (['', 'u'] as $flag) {
                $pattern = '/^'.$set.'$/'.$flag;

                try {
                    $result = $regex->transpile($pattern, $target);
                } catch (TranspileException $refusal) {
                    // Python's re has no property, and no "\w" as PCRE reads it under u.
                    $this->assertContains([$target, $set, $flag], [['python', '\p{L}', ''], ['python', '\p{L}', 'u'], ['python', '\W', 'u']], $refusal->getMessage());

                    continue;
                }

                $warned = [] !== array_filter($result->warnings, static fn (string $warning): bool => str_contains($warning, 'takes one byte in PCRE and one character in this target'));
                $this->assertSame('' === $flag, $warned, $pattern.' for '.$target);

                // Without u the character is two bytes to PCRE, and one byte is all the set takes.
                $this->assertSame('u' === $flag ? 1 : 0, preg_match($pattern, $character), $pattern.' on the character');
                $this->assertSame($takesAscii ? 1 : 0, preg_match($pattern, $ascii), $pattern.' on ASCII');

                $cases[] = ['pattern' => $result->pattern, 'flags' => $whole ? 'v' : $result->flags, 'whole' => $whole, 'subjects' => [[$character, 0], [$ascii, 0]]];
                $expected[] = [$pattern, $takesAscii];
            }
        }

        $this->assertGreaterThanOrEqual(15, \count($cases));
        foreach ($engine->run($cases) as $index => $outcome) {
            [$pattern, $takesAscii] = $expected[$index];
            $this->assertNull($outcome['error'], $pattern.' for '.$target);
            $this->assertFalse($outcome['timedOut'] ?? false, $pattern.' for '.$target);

            // The target takes the character whole, with u and without.
            $this->assertNotNull($outcome['results'][0], $pattern.' for '.$target.' on the character');
            $this->assertSame($takesAscii, null !== $outcome['results'][1], $pattern.' for '.$target.' on ASCII');
        }
    }

    /**
     * @return iterable<string, array{target: string}>
     */
    public static function provideTargets(): iterable
    {
        yield 'javascript' => ['target' => 'javascript'];
        yield 'html-pattern' => ['target' => 'html-pattern'];
        yield 'python' => ['target' => 'python'];
    }
}
