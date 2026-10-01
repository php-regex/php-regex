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

namespace PhpRegex\Tests\Unit\Bridge\Symfony\Extractor\Strategy;

use PhpRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPUnit\Framework\TestCase;

final class TokenBasedExtractionStrategyTest extends TestCase
{
    public function test_extracts_simple_preg_match(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $fixtureFile = __DIR__.'/../../../../../Fixtures/Extractor/simple_preg_match.php';

        $result = $strategy->extract([$fixtureFile]);

        $this->assertCount(1, $result);
        $this->assertSame('/test/', $result[0]->pattern);
        $this->assertSame($fixtureFile, $result[0]->file);
        $this->assertSame(3, $result[0]->line);
        $this->assertSame('preg_match()', $result[0]->source);
    }

    public function test_extracts_multiple_preg_functions(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $fixtureFile = __DIR__.'/../../../../../Fixtures/Extractor/multiple_preg_functions.php';

        $result = $strategy->extract([$fixtureFile]);

        $this->assertCount(3, $result);
        $this->assertSame('/test/', $result[0]->pattern);
        $this->assertSame('/old/', $result[1]->pattern);
        $this->assertSame('/\s+/', $result[2]->pattern);
    }

    public function test_extracts_concatenated_constant_pattern(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $fixtureFile = __DIR__.'/../../../../../Fixtures/Extractor/concatenated_pattern.php';

        $result = $strategy->extract([$fixtureFile]);

        $this->assertCount(1, $result);
        $this->assertSame('/testsuffix', $result[0]->pattern);
    }

    public function test_skips_non_constant_patterns(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $fixtureFile = __DIR__.'/../../../../../Fixtures/Extractor/variable_pattern.php';

        $result = $strategy->extract([$fixtureFile]);

        $this->assertEmpty($result);
    }

    public function test_respects_exclude_paths(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        // Test that strategy doesn't handle exclude paths anymore
        // This responsibility moved to PatternExtractor
        $fixtureFile = __DIR__.'/../../../../../Fixtures/Extractor/simple_preg_match.php';

        $result = $strategy->extract([$fixtureFile]);

        $this->assertCount(1, $result);
        $this->assertSame('/test/', $result[0]->pattern);
    }

    public function test_handles_array_syntax_in_callback_array(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $fixtureFile = __DIR__.'/../../../../../Fixtures/Extractor/callback_array.php';

        $result = $strategy->extract([$fixtureFile]);

        $this->assertCount(2, $result);
        $this->assertSame('/pattern1/', $result[0]->pattern);
        $this->assertSame('/pattern2/', $result[1]->pattern);
    }

    public function test_skips_unknown_functions(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $file = __DIR__.'/../../../../../Fixtures/Extractor/unknown_function.php';

        $result = $strategy->extract([$file]);

        $this->assertEmpty($result);
    }
}
