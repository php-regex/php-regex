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

use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PHP hands preg_match() the bytes of its source: a file holding a byte
 * that is not UTF-8, as a Latin-1 "é" in a comment, keeps every pattern as
 * written, and every offset where it stands.
 */
final class InvalidUtf8SourceTest extends TestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
    }

    #[Test]
    public function test_patterns_keep_their_bytes_beside_a_latin1_byte(): void
    {
        $occurrences = $this->extract("<?php\n// caf\xE9\npreg_match('/\u{E9}+/u', \$s);\npreg_match('/caf\xE9/', \$s);\n");

        $this->assertSame([bin2hex("/\u{E9}+/u"), bin2hex("/caf\xE9/")], array_map(static fn (PatternOccurrence $o): string => bin2hex($o->pattern), $occurrences));
    }

    #[Test]
    public function test_offsets_do_not_move_past_a_latin1_byte(): void
    {
        $latin1 = $this->extract("<?php\n// caf\xE9\npreg_match('/a+/', \$s);\n");
        $ascii = $this->extract("<?php\n// cafe\npreg_match('/a+/', \$s);\n");

        $this->assertCount(1, $latin1);
        $this->assertCount(1, $ascii);
        $this->assertSame($ascii[0]->fileOffset, $latin1[0]->fileOffset);
        $this->assertSame($ascii[0]->column, $latin1[0]->column);
        $this->assertSame($ascii[0]->line, $latin1[0]->line);
    }

    #[Test]
    public function test_a_file_holding_a_nul_byte_is_still_skipped(): void
    {
        $this->assertSame([], $this->extract("<?php\n// caf\xE9\0\npreg_match('/a+/', \$s);\n"));
    }

    /**
     * @return list<PatternOccurrence>
     */
    private function extract(string $content): array
    {
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }

        $base = tempnam(sys_get_temp_dir(), 'regex-latin1-');
        $this->assertIsString($base);
        unlink($base);
        $this->file = $base.'.php';
        file_put_contents($this->file, $content);

        return array_values((new TokenBasedExtractionStrategy())->extract([$this->file]));
    }
}
