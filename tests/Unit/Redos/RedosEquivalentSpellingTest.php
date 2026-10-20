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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Tests\Support\PatternEquivalence;
use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two spellings of the same pattern get the same verdict. Each pair below
 * is one pattern written two ways, as the engine reads it: the same matches
 * and the same groups on every subject of a small alphabet. The verdict of
 * either spelling may come from the heuristics, which read the length of a
 * capture, whether a loop body can match nothing and what a group holds:
 * a node kind one of them misses gives the second spelling another score.
 *
 * Two lookarounds that accept the same text are no such pair: "(?=a+)" reads
 * the whole run where "(?=a)" reads one character, so "^(?:(?=a+)a)+$" takes
 * quadratic time where "^(?:(?=a)a)+$" takes linear time (PCRE2 10.49, JIT
 * off: 390 ms against 1.4 ms on 40,000 "a").
 */
final class RedosEquivalentSpellingTest extends TestCase
{
    use PcreGates;

    private const ALPHABET = ['a', 'b', 'c', "\x01", "\r", "\n", "\x0b"];

    #[Test]
    #[DataProvider('provideEquivalentSpellings')]
    public function test_engine_fact_both_spellings_are_the_same_pattern(string $written, string $equivalent): void
    {
        $this->requiresCompile($written);
        $this->requiresCompile($equivalent);

        foreach (self::subjects() as $subject) {
            $this->assertSame(
                PatternEquivalence::engineRun($written, $subject),
                PatternEquivalence::engineRun($equivalent, $subject),
                \sprintf('%s and %s differ on "%s".', $written, $equivalent, addcslashes($subject, "\0..\37")),
            );
        }
    }

    #[Test]
    #[DataProvider('provideEquivalentSpellings')]
    public function test_equivalent_spellings_get_the_same_verdict(string $written, string $equivalent): void
    {
        $this->requiresCompile($written);
        $this->requiresCompile($equivalent);

        $analyzer = new RedosAnalyzer();
        $first = $analyzer->analyze($written);
        $second = $analyzer->analyze($equivalent);

        $this->assertSame($first->proof, $second->proof, 'Both spellings are in the model, or both out of it.');
        $this->assertSame($first->severity, $second->severity, \sprintf('%s is %s, %s is %s.', $written, $first->severity->value, $equivalent, $second->severity->value));
        $this->assertSame($first->score, $second->score);
        $this->assertSame($first->headline(), $second->headline());
    }

    /**
     * @return iterable<string, array{written: string, equivalent: string}>
     */
    public static function provideEquivalentSpellings(): iterable
    {
        // A control character is one character, however it is written.
        yield 'a control escape and its hexadecimal escape, captured and repeated' => ['written' => '/^(?:(\cA)\1)+$/', 'equivalent' => '/^(?:(\x01)\1)+$/'];
        yield 'a control escape and its hexadecimal escape, unanchored at the end' => ['written' => '/^(?:(\cA)\1)+/', 'equivalent' => '/^(?:(\x01)\1)+/'];
        yield 'a control escape and its octal escape' => ['written' => '/^(?:(\cA)\1)+$/', 'equivalent' => '/^(?:(\001)\1)+$/'];

        // "\R" is one or two characters: the line break sequences it stands for.
        yield 'a line break escape and its sequences' => ['written' => '/^(?:(\R)\1)+$/', 'equivalent' => '/^(?:((?>\r\n|[\n\x0b\f\r\x85]))\1)+$/'];

        // A script run of one character is that character.
        yield 'a script run of one letter, captured' => ['written' => '/^(?:((*sr:a))\1)+$/', 'equivalent' => '/^(?:(a)\1)+$/'];
        yield 'a script run of one letter, repeated' => ['written' => '/(?:(*sr:a))*a(c)\1/', 'equivalent' => '/(?:a)*a(c)\1/'];
        yield 'a script run around a capture and its reference' => ['written' => '/^(?:(*sr:(a+)\1))+$/', 'equivalent' => '/^(?:(?:(a+)\1))+$/'];

        // A condition that picks between two characters reads one.
        yield 'a lookahead condition between two letters' => ['written' => '/^(?:((?(?=a)a|b))\1)+$/', 'equivalent' => '/^(?:((?:a|b))\1)+$/'];

        // A DEFINE group matches nothing where it stands.
        yield 'a DEFINE group inside a capture and after the pattern' => ['written' => '/^(?:((?(DEFINE)(?<x>a+))b)\1)+$/', 'equivalent' => '/^(?:(b)\1)+$(?(DEFINE)(?<x>a+))/'];
    }

    /**
     * Every subject of up to four characters of the alphabet.
     *
     * @return list<string>
     */
    private static function subjects(): array
    {
        $subjects = [''];
        $layer = [''];
        for ($length = 1; $length <= 4; $length++) {
            $next = [];
            foreach ($layer as $prefix) {
                foreach (self::ALPHABET as $character) {
                    $next[] = $prefix.$character;
                }
            }

            $subjects = [...$subjects, ...$next];
            $layer = $next;
        }

        return $subjects;
    }
}
