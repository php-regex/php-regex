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

use PHPRegex\Linter\Extraction\MemoryBudget;
use PHPUnit\Framework\TestCase;

final class MemoryBudgetTest extends TestCase
{
    private string $memoryLimit;

    protected function setUp(): void
    {
        $limit = ini_get('memory_limit');
        $this->memoryLimit = \is_string($limit) ? $limit : '-1';
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memoryLimit);
    }

    public function test_an_unlimited_process_analyses_everything(): void
    {
        ini_set('memory_limit', '-1');

        $this->assertTrue(MemoryBudget::allows(str_repeat('a', 4096), MemoryBudget::TOKENIZE_FACTOR));
    }

    public function test_a_small_file_fits(): void
    {
        $this->limitHeadroomTo(32 * 1024 * 1024);

        $this->assertTrue(MemoryBudget::allows('<?php preg_match("/a/", $s);', MemoryBudget::TOKENIZE_FACTOR));
    }

    public function test_a_file_that_would_exhaust_the_limit_is_refused(): void
    {
        $this->limitHeadroomTo(32 * 1024 * 1024);

        // 1 MB of source needs about 60 MB to tokenize, more than the headroom.
        $this->assertFalse(MemoryBudget::allows(str_repeat('a', 1024 * 1024), MemoryBudget::TOKENIZE_FACTOR));
    }

    public function test_building_an_ast_is_budgeted_higher_than_tokenizing(): void
    {
        $this->assertGreaterThan(MemoryBudget::TOKENIZE_FACTOR, MemoryBudget::PARSE_FACTOR);

        $this->limitHeadroomTo(32 * 1024 * 1024);

        // 384 KB: about 23 MB of tokens, but some 42 MB of AST.
        $content = str_repeat('a', 384 * 1024);

        $this->assertTrue(MemoryBudget::allows($content, MemoryBudget::TOKENIZE_FACTOR));
        $this->assertFalse(MemoryBudget::allows($content, MemoryBudget::PARSE_FACTOR));
    }

    public function test_the_limit_is_read_in_every_shorthand(): void
    {
        $content = str_repeat('a', 1024 * 1024);
        // A gigabyte above what the process already holds, which a full run
        // under coverage brings close to a gigabyte itself.
        $gigabytes = (int) ceil(memory_get_usage(true) / 1024 ** 3) + 1;

        ini_set('memory_limit', $gigabytes.'G');
        $this->assertTrue(MemoryBudget::allows($content, MemoryBudget::TOKENIZE_FACTOR));

        ini_set('memory_limit', ($gigabytes * 1024 * 1024).'K');
        $this->assertTrue(MemoryBudget::allows($content, MemoryBudget::TOKENIZE_FACTOR));

        // Plain byte counts are read as-is.
        $this->limitHeadroomTo(16 * 1024 * 1024);
        $this->assertFalse(MemoryBudget::allows($content, MemoryBudget::TOKENIZE_FACTOR));
    }

    /**
     * Leave the process the given number of bytes to work with.
     */
    private function limitHeadroomTo(int $headroom): void
    {
        ini_set('memory_limit', (string) (memory_get_usage(true) + $headroom));
    }
}
