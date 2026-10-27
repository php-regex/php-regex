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

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Cli\Command\CompareCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "regex compare --method=subset" calls two patterns that match the same
 * strings equivalent, and keeps "strict subset" for a first pattern that
 * matches fewer strings than the second.
 */
final class CompareSubsetEquivalenceTest extends TestCase
{
    /**
     * @param list<string> $subjects strings both patterns match whole, or both do not
     */
    #[Test]
    #[DataProvider('provideEquivalentPatterns')]
    public function test_a_subset_of_the_same_strings_is_called_equivalent(string $pattern1, string $pattern2, array $subjects): void
    {
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match(self::whole($pattern1), $subject), preg_match(self::whole($pattern2), $subject), 'Oracle: "'.$subject.'"');
        }

        [$exitCode, $display] = self::compare($pattern1, $pattern2);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Patterns are equivalent: a subset, not a strict one.', $display);
        $this->assertStringNotContainsString('strict subset', $display);
    }

    /**
     * @return iterable<string, array{pattern1: string, pattern2: string, subjects: list<string>}>
     */
    public static function provideEquivalentPatterns(): iterable
    {
        yield 'the same branches in another order' => ['pattern1' => '/foo|bar/', 'pattern2' => '/bar|foo/', 'subjects' => ['foo', 'bar', 'fo', '']];
        yield 'a digit class written two ways' => ['pattern1' => '/[0-9]+/', 'pattern2' => '/\d+/', 'subjects' => ['0', '42', 'a', '']];
        yield 'the same pattern' => ['pattern1' => '/a+/', 'pattern2' => '/a+/', 'subjects' => ['a', 'aa', '']];
    }

    #[Test]
    public function test_a_first_pattern_matching_fewer_strings_is_a_strict_subset(): void
    {
        $this->assertSame(1, preg_match('/^(?:[a-z]+)$/', 'a'));
        $this->assertSame(0, preg_match('/^(?:edit)$/', 'a'));

        [$exitCode, $display] = self::compare('/edit/', '/[a-z]+/');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Pattern 1 is a strict subset of Pattern 2.', $display);
        $this->assertStringNotContainsString('equivalent', $display);
    }

    /**
     * @return array{int, string}
     */
    private static function compare(string $pattern1, string $pattern2): array
    {
        $output = OutputFactory::create();
        $input = new Input('compare', [$pattern1, $pattern2, '--method=subset'], new GlobalOptions(false, false, false, true, null, null), []);

        ob_start();

        try {
            $exitCode = (new CompareCommand())->run($input, $output);
        } finally {
            $display = (string) ob_get_clean();
        }

        return [$exitCode, $display.OutputFactory::errors($output)];
    }

    private static function whole(string $pattern): string
    {
        $end = (int) strrpos($pattern, $pattern[0]);

        return $pattern[0].'^(?:'.substr($pattern, 1, $end - 1).')$'.substr($pattern, $end);
    }
}
