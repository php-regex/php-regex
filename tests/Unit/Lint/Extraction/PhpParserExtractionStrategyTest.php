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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PhpParser\ParserFactory;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

final class PhpParserExtractionStrategyTest extends TestCase
{
    private PhpParserExtractionStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new PhpParserExtractionStrategy();
    }

    #[DoesNotPerformAssertions]
    public function test_construct(): void
    {
        new PhpParserExtractionStrategy();
    }

    public function test_extract_with_empty_array(): void
    {
        $result = $this->strategy->extract([]);
        $this->assertSame([], $result);
    }

    public function test_extract_with_nonexistent_file(): void
    {
        $result = $this->strategy->extract(['/nonexistent/file.php']);
        $this->assertSame([], $result);
    }

    public function test_extract_with_empty_file(): void
    {
        $file = __DIR__.'/../../../Fixtures/Extractor/empty.php';

        $result = $this->strategy->extract([$file]);
        $this->assertSame([], $result);
    }

    public function test_extract_with_preg_match(): void
    {
        $file = __DIR__.'/../../../Fixtures/Extractor/phpstan_preg_match.php';

        $result = $this->strategy->extract([$file]);

        if ($this->isPhpParserAvailable()) {
            $this->assertCount(1, $result);
            $this->assertSame('/test/', $result[0]->pattern);
            $this->assertSame($file, $result[0]->file);
            $this->assertSame(1, $result[0]->line);
            $this->assertSame('preg_match()', $result[0]->source);

            $content = file_get_contents($file);
            $this->assertIsString($content);

            $expectedOffset = strpos($content, "'/test/'");
            $this->assertIsInt($expectedOffset);

            $this->assertSame($expectedOffset, $result[0]->fileOffset);
            $this->assertSame($expectedOffset + 1, $result[0]->column);
        } else {
            $this->assertSame([], $result);
        }
    }

    public function test_extract_with_multiple_preg_functions(): void
    {
        $file = __DIR__.'/../../../Fixtures/Extractor/phpstan_multiple_preg.php';

        $result = $this->strategy->extract([$file]);

        if ($this->isPhpParserAvailable()) {
            $this->assertCount(3, $result);
            $patterns = array_map(static fn ($occurrence) => $occurrence->pattern, $result);
            $this->assertContains('/pattern1/', $patterns);
            $this->assertContains('/pattern2/', $patterns);
            $this->assertContains('/pattern3/', $patterns);
        } else {
            $this->assertSame([], $result);
        }
    }

    public function test_extract_with_concatenated_strings(): void
    {
        $file = __DIR__.'/../../../Fixtures/Extractor/phpstan_concatenated.php';

        $result = $this->strategy->extract([$file]);

        if ($this->isPhpParserAvailable()) {
            $this->assertCount(1, $result);
            $this->assertSame('/testing/', $result[0]->pattern);
        } else {
            $this->assertSame([], $result);
        }
    }

    public function test_extract_ignores_empty_patterns(): void
    {
        $file = __DIR__.'/../../../Fixtures/Extractor/phpstan_empty_pattern.php';

        $result = $this->strategy->extract([$file]);
        $this->assertSame([], $result);
    }

    public function test_extract_ignores_null_patterns(): void
    {
        $file = __DIR__.'/../../../Fixtures/Extractor/phpstan_null_pattern.php';

        $result = $this->strategy->extract([$file]);
        $this->assertSame([], $result);
    }

    public function test_extract_with_non_preg_function(): void
    {
        $file = __DIR__.'/../../../Fixtures/Extractor/phpstan_non_preg.php';

        $result = $this->strategy->extract([$file]);
        $this->assertSame([], $result);
    }

    public function test_extract_with_complex_concatenation(): void
    {
        $file = __DIR__.'/../../../Fixtures/Extractor/phpstan_complex_concat.php';

        $result = $this->strategy->extract([$file]);
        // Complex concatenation with variables should not extract patterns
        $this->assertSame([], $result);
    }

    public function test_extract_with_malformed_php(): void
    {
        $file = __DIR__.'/../../../Fixtures/Extractor/phpstan_malformed.php';

        $result = $this->strategy->extract([$file]);
        // Malformed PHP should not crash and should return empty
        $this->assertSame([], $result);
    }

    public function test_extract_with_multiple_files(): void
    {
        $file1 = __DIR__.'/../../../Fixtures/Extractor/phpstan_test1.php';
        $file2 = __DIR__.'/../../../Fixtures/Extractor/phpstan_test2.php';

        $result = $this->strategy->extract([$file1, $file2]);

        if ($this->isPhpParserAvailable()) {
            $this->assertCount(2, $result);
            $patterns = array_map(static fn ($occurrence) => $occurrence->pattern, $result);
            $this->assertContains('/test1/', $patterns);
            $this->assertContains('/test2/', $patterns);
        } else {
            $this->assertSame([], $result);
        }
    }

    public function test_extract_with_preg_replace_callback(): void
    {
        $file = __DIR__.'/../../../Fixtures/Extractor/phpstan_preg_replace_callback.php';

        $result = $this->strategy->extract([$file]);

        if ($this->isPhpParserAvailable()) {
            $this->assertCount(1, $result);
            $this->assertSame('/test/', $result[0]->pattern);
            $this->assertSame('preg_replace_callback()', $result[0]->source);
        } else {
            $this->assertSame([], $result);
        }
    }

    public function test_extract_with_preg_replace_callback_array(): void
    {
        $file = __DIR__.'/../../../Fixtures/Extractor/phpstan_preg_replace_callback_array.php';

        $result = $this->strategy->extract([$file]);

        if ($this->isPhpParserAvailable()) {
            // The patterns are the keys of the array passed as first argument.
            $this->assertCount(1, $result);
            $this->assertSame('/test/', $result[0]->pattern);
            $this->assertSame('preg_replace_callback_array()', $result[0]->source);
        } else {
            $this->assertSame([], $result);
        }
    }

    private function isPhpParserAvailable(): bool
    {
        return class_exists(ParserFactory::class);
    }
}
