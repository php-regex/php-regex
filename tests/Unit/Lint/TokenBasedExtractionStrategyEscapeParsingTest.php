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

namespace PhpRegex\Tests\Unit\Lint;

use PhpRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPUnit\Framework\TestCase;

final class TokenBasedExtractionStrategyEscapeParsingTest extends TestCase
{
    public function test_extracts_pattern_with_hex_escape(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $file = __DIR__.'/../../Fixtures/Extractor/hex_escape.php';

        $result = $strategy->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertNotEmpty($result[0]->pattern);
    }

    public function test_extracts_pattern_with_hex_escape_braced(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $file = __DIR__.'/../../Fixtures/Extractor/hex_escape_braced.php';

        $result = $strategy->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertNotEmpty($result[0]->pattern);
    }

    public function test_extracts_pattern_with_unicode_escape(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $file = __DIR__.'/../../Fixtures/Extractor/unicode_escape_u.php';

        $result = $strategy->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertNotEmpty($result[0]->pattern);
    }

    public function test_extracts_pattern_with_octal_escape(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $file = __DIR__.'/../../Fixtures/Extractor/octal_escape.php';

        $result = $strategy->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertNotEmpty($result[0]->pattern);
    }

    public function test_extracts_pattern_with_brace_delimiter_and_flags(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $file = __DIR__.'/../../Fixtures/Extractor/brace_delimiter.php';

        $result = $strategy->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertSame('{test}iu', $result[0]->pattern);
    }

    public function test_extracts_pattern_with_tilde_delimiter(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $file = __DIR__.'/../../Fixtures/Extractor/tilde_delimiter.php';

        $result = $strategy->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertSame('~test~', $result[0]->pattern);
    }

    public function test_extracts_pattern_with_hash_delimiter(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $file = __DIR__.'/../../Fixtures/Extractor/hash_delimiter.php';

        $result = $strategy->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertSame('#test#', $result[0]->pattern);
    }

    public function test_extracts_pattern_with_percent_delimiter(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $file = __DIR__.'/../../Fixtures/Extractor/percent_delimiter.php';

        $result = $strategy->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertSame('%test%', $result[0]->pattern);
    }

    public function test_handles_empty_file(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $file = __DIR__.'/../../Fixtures/Extractor/empty_file.php';

        $result = $strategy->extract([$file]);

        $this->assertEmpty($result);
    }

    public function test_handles_file_with_only_whitespace(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $file = __DIR__.'/../../Fixtures/Extractor/whitespace_only.php';

        $result = $strategy->extract([$file]);

        $this->assertEmpty($result);
    }

    public function test_handles_file_with_comments_only(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $file = __DIR__.'/../../Fixtures/Extractor/comments_only.php';

        $result = $strategy->extract([$file]);

        $this->assertEmpty($result);
    }
}
