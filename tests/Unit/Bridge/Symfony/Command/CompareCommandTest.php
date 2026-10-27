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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Command;

use PHPRegex\Symfony\Command\CompareCommand;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * regex:compare answers each method with the strings the patterns match
 * whole: every example it prints is grounded below in the engine, each
 * pattern anchored at both ends.
 */
final class CompareCommandTest extends TestCase
{
    /**
     * @param array<string, string>           $options
     * @param list<string>                    $lines   the lines the display holds
     * @param array<int|string, list<string>> $matches example => the patterns that match it whole, each of the two named
     */
    #[Test]
    #[DataProvider('provideComparisons')]
    public function test_each_method_answers_with_an_example_the_engine_agrees_with(string $pattern1, string $pattern2, array $options, int $status, array $lines, array $matches): void
    {
        foreach ($matches as $example => $matching) {
            foreach (['1' => $pattern1, '2' => $pattern2] as $which => $pattern) {
                $expected = \in_array((string) $which, $matching, true) ? 1 : 0;
                $this->assertSame($expected, preg_match(self::whole($pattern), (string) $example), \sprintf('Pattern %s and "%s"', $which, $example));
            }
        }

        $tester = new CommandTester(new CompareCommand(Regex::create()));

        $this->assertSame($status, $tester->execute(['pattern1' => $pattern1, 'pattern2' => $pattern2] + $options));
        $display = $tester->getDisplay();
        foreach ($lines as $line) {
            $this->assertStringContainsString($line, $display);
        }
    }

    /**
     * @return iterable<string, array{pattern1: string, pattern2: string, options: array<string, string>, status: int, lines: list<string>, matches: array<int|string, list<string>>}>
     */
    public static function provideComparisons(): iterable
    {
        yield 'disjoint patterns' => [
            'pattern1' => '/[a-z]+/',
            'pattern2' => '/\d+/',
            'options' => [],
            'status' => Command::SUCCESS,
            'lines' => ['No intersection found. These regexes are disjoint.'],
            'matches' => ['a' => ['1'], '7' => ['2']],
        ];
        yield 'an intersection' => [
            'pattern1' => '/[a-z]+/',
            'pattern2' => '/edit/',
            'options' => [],
            'status' => Command::FAILURE,
            'lines' => ['Conflict detected!', 'Example: "edit"'],
            'matches' => ['edit' => ['1', '2']],
        ];
        yield 'a subset, the method in capitals' => [
            'pattern1' => '/edit/',
            'pattern2' => '/[a-z]+/',
            'options' => ['--method' => 'SUBSET'],
            'status' => Command::SUCCESS,
            'lines' => ['Pattern 1 is a strict subset of Pattern 2.'],
            'matches' => ['edit' => ['1', '2']],
        ];
        yield 'no subset' => [
            'pattern1' => '/[a-z]+/',
            'pattern2' => '/edit/',
            'options' => ['--method' => 'subset'],
            'status' => Command::FAILURE,
            'lines' => ['Pattern 1 allows strings that Pattern 2 forbids.', 'Counter-example: "a"'],
            'matches' => ['a' => ['1']],
        ];
        yield 'equivalent patterns, the minimizer spelled loosely and no determinizer' => [
            'pattern1' => '/foo|bar/',
            'pattern2' => '/bar|foo/',
            'options' => ['--method' => 'equivalence', '--minimizer' => ' Moore ', '--determinizer' => ''],
            'status' => Command::SUCCESS,
            'lines' => ['Patterns are mathematically equivalent.'],
            'matches' => ['foo' => ['1', '2'], 'bar' => ['1', '2']],
        ];
        yield 'patterns each matching a string of its own' => [
            'pattern1' => '/a|b/',
            'pattern2' => '/b|c/',
            'options' => ['--method' => 'equivalence'],
            'status' => Command::FAILURE,
            'lines' => ['Patterns are different.', 'Pattern 1 only: "a"', 'Pattern 2 only: "c"'],
            'matches' => ['a' => ['1'], 'c' => ['2'], 'b' => ['1', '2']],
        ];
        yield 'a first pattern wider than the second, no minimizer' => [
            'pattern1' => '/a|b/',
            'pattern2' => '/a/',
            'options' => ['--method' => 'equivalence', '--minimizer' => '', '--determinizer' => 'subset'],
            'status' => Command::FAILURE,
            'lines' => ['Patterns are different.', 'Pattern 1 only: "b"'],
            'matches' => ['b' => ['1']],
        ];
    }

    /**
     * Of two patterns differing one way only, only that way is printed.
     */
    #[Test]
    public function test_a_difference_one_way_prints_only_that_way(): void
    {
        $tester = new CommandTester(new CompareCommand(Regex::create()));
        $tester->execute(['pattern1' => '/a/', 'pattern2' => '/a|b/', '--method' => 'equivalence']);
        $display = $tester->getDisplay();

        $this->assertSame(0, preg_match('/^(?:a)$/', 'b'));
        $this->assertSame(1, preg_match('/^(?:a|b)$/', 'b'));
        $this->assertStringContainsString('Pattern 2 only: "b"', $display);
        $this->assertStringNotContainsString('Pattern 1 only', $display);
    }

    /**
     * Two patterns that match the same strings are each a subset of the
     * other, and neither a strict one.
     */
    #[Test]
    public function test_a_subset_of_the_same_strings_is_called_equivalent(): void
    {
        foreach (['foo', 'bar', 'fo', 'foobar', ''] as $subject) {
            $this->assertSame(preg_match('/^(?:foo|bar)$/', $subject), preg_match('/^(?:bar|foo)$/', $subject), 'Oracle: "'.$subject.'"');
        }

        $tester = new CommandTester(new CompareCommand(Regex::create()));

        $this->assertSame(Command::SUCCESS, $tester->execute(['pattern1' => '/foo|bar/', 'pattern2' => '/bar|foo/', '--method' => 'subset']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Patterns are equivalent: a subset, not a strict one.', $display);
        $this->assertStringNotContainsString('strict subset', $display);
    }

    private static function whole(string $pattern): string
    {
        $end = (int) strrpos($pattern, $pattern[0]);

        return $pattern[0].'^(?:'.substr($pattern, 1, $end - 1).')$'.substr($pattern, $end);
    }
}
