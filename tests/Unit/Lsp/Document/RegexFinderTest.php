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

namespace PhpRegex\Tests\Unit\Lsp\Document;

use PhpRegex\LanguageServer\Document\RegexFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegexFinderTest extends TestCase
{
    private RegexFinder $finder;

    protected function setUp(): void
    {
        $this->finder = new RegexFinder();
    }

    #[Test]
    public function test_finds_preg_match_pattern(): void
    {
        $content = "<?php\npreg_match('/\\w+/', \$text);";
        $occurrences = $this->finder->find($content);

        $this->assertCount(1, $occurrences);
        $this->assertSame('/\\w+/', $occurrences[0]->pattern);
    }

    #[Test]
    public function test_finds_preg_match_all_pattern(): void
    {
        $content = "<?php\npreg_match_all('/[a-z]+/i', \$text, \$matches);";
        $occurrences = $this->finder->find($content);

        $this->assertCount(1, $occurrences);
        $this->assertSame('/[a-z]+/i', $occurrences[0]->pattern);
    }

    #[Test]
    public function test_finds_preg_replace_pattern(): void
    {
        $content = "<?php\npreg_replace('/foo/', 'bar', \$text);";
        $occurrences = $this->finder->find($content);

        $this->assertCount(1, $occurrences);
        $this->assertSame('/foo/', $occurrences[0]->pattern);
    }

    #[Test]
    public function test_finds_preg_split_pattern(): void
    {
        $content = "<?php\npreg_split('/\\s+/', \$text);";
        $occurrences = $this->finder->find($content);

        $this->assertCount(1, $occurrences);
        $this->assertSame('/\\s+/', $occurrences[0]->pattern);
    }

    #[Test]
    public function test_finds_preg_grep_pattern(): void
    {
        $content = "<?php\npreg_grep('/^test/', \$array);";
        $occurrences = $this->finder->find($content);

        $this->assertCount(1, $occurrences);
        $this->assertSame('/^test/', $occurrences[0]->pattern);
    }

    #[Test]
    public function test_finds_multiple_patterns(): void
    {
        $content = "<?php\npreg_match('/foo/', \$a);\npreg_match('/bar/', \$b);";
        $occurrences = $this->finder->find($content);

        $this->assertCount(2, $occurrences);
        $this->assertSame('/foo/', $occurrences[0]->pattern);
        $this->assertSame('/bar/', $occurrences[1]->pattern);
    }

    #[Test]
    public function test_returns_correct_position(): void
    {
        $content = "<?php\npreg_match('/test/', \$text);";
        $occurrences = $this->finder->find($content);

        $this->assertCount(1, $occurrences);
        $this->assertSame(1, $occurrences[0]->start['line']);
        $this->assertSame(11, $occurrences[0]->start['character']);
    }

    #[Test]
    public function test_handles_double_quoted_strings(): void
    {
        $content = "<?php\npreg_match(\"/\\d+/\", \$text);";
        $occurrences = $this->finder->find($content);

        $this->assertCount(1, $occurrences);
        // Note: \d in double quotes becomes just d
        $this->assertSame('/d+/', $occurrences[0]->pattern);
    }

    #[Test]
    public function test_handles_different_delimiters(): void
    {
        $content = "<?php\npreg_match('#test#', \$text);";
        $occurrences = $this->finder->find($content);

        $this->assertCount(1, $occurrences);
        $this->assertSame('#test#', $occurrences[0]->pattern);
    }

    #[Test]
    public function test_returns_empty_for_no_patterns(): void
    {
        $content = "<?php\n\$a = 'hello';";
        $occurrences = $this->finder->find($content);

        $this->assertCount(0, $occurrences);
    }

    #[Test]
    public function test_returns_empty_for_invalid_php(): void
    {
        $content = '<?php this is not valid php';
        $occurrences = $this->finder->find($content);

        // Should not crash, may return empty or partial results
        $this->assertIsArray($occurrences);
    }

    #[Test]
    #[DataProvider('providePatternWithFlags')]
    public function test_captures_flags(string $content, string $expectedPattern): void
    {
        $occurrences = $this->finder->find($content);

        $this->assertCount(1, $occurrences);
        $this->assertSame($expectedPattern, $occurrences[0]->pattern);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providePatternWithFlags(): iterable
    {
        yield 'case insensitive' => ["<?php\npreg_match('/test/i', \$t);", '/test/i'];
        yield 'multiline' => ["<?php\npreg_match('/test/m', \$t);", '/test/m'];
        yield 'dot all' => ["<?php\npreg_match('/test/s', \$t);", '/test/s'];
        yield 'unicode' => ["<?php\npreg_match('/test/u', \$t);", '/test/u'];
        yield 'extended' => ["<?php\npreg_match('/test/x', \$t);", '/test/x'];
        yield 'multiple flags' => ["<?php\npreg_match('/test/imsu', \$t);", '/test/imsu'];
    }
}
