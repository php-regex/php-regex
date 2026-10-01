<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Visitor;

use PhpRegex\Optimizer\Modernizer;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class ModernizerTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    public function test_modernizes_digit_range(): void
    {
        $original = '/[0-9]+/';
        $modernized = $this->modernize($original);

        $this->assertSame('/\d+/', $modernized);
        $this->assertMatchesOriginalBehavior($original, $modernized);
    }

    public function test_removes_unnecessary_escaping(): void
    {
        $original = '/\@name\:/';
        $modernized = $this->modernize($original);

        $this->assertSame('/@name:/', $modernized);
        $this->assertMatchesOriginalBehavior($original, $modernized);
    }

    public function test_modernizes_backref(): void
    {
        $original = '/(a)\1/';
        $modernized = $this->modernize($original);

        $this->assertSame('/(a)\1/', $modernized);
        $this->assertMatchesOriginalBehavior($original, $modernized);
    }

    public function test_complex_modernization(): void
    {
        $original = '/^[0-9]+\-[a-z]+\@(?:gmail)\.com$/';
        $modernized = $this->modernize($original);

        // Expected: ^\d+-[a-z]+@gmail\.com$
        $this->assertSame('/^\d+-[a-z]+@gmail\.com$/', $modernized);
        $this->assertMatchesOriginalBehavior($original, $modernized);
    }

    private function assertMatchesOriginalBehavior(string $original, string $modernized): void
    {
        // Simple test: both should match or not match the same test strings
        $testStrings = ['123', 'abc', '123-abc@gmail.com', '@name:', 'aa'];

        foreach ($testStrings as $test) {
            $originalMatches = preg_match($original, $test) > 0;
            $modernizedMatches = preg_match($modernized, $test) > 0;
            $this->assertSame($originalMatches, $modernizedMatches, "Mismatch for test string: $test");
        }
    }

    private function modernize(string $pattern): string
    {
        $ast = $this->regexService->parse($pattern);
        $modernized = $ast->accept(new Modernizer());

        return $modernized->accept(new PatternPrinter());
    }
}
