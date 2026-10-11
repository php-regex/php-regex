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

namespace PHPRegex\Tests\Documentation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HeroAstOutputTest extends TestCase
{
    private const DATA = __DIR__.'/../../docs/_data/hero_ast.yml';

    #[Test]
    public function test_the_landing_page_reads_the_ast_demo_from_one_source(): void
    {
        $this->assertFileExists(self::DATA);

        $home = (string) file_get_contents(__DIR__.'/../../docs/index.md');

        $this->assertStringContainsString('site.data.hero_ast.command', $home);
        $this->assertStringContainsString('site.data.hero_ast.output', $home);
    }

    #[Test]
    public function test_the_hero_ast_matches_the_cli_output(): void
    {
        $this->assertFileExists(self::DATA);

        $pattern = '/^(?<date>\d{4}-\d{2}-\d{2})$/';
        $command = sprintf(
            '%s %s diagram %s --format=text --no-visuals --no-ansi',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(__DIR__.'/../../bin/regex'),
            escapeshellarg($pattern),
        );

        exec($command, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);
        $data = (string) file_get_contents(self::DATA);
        $indentedOutput = preg_replace('/^/m', '  ', $output);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString("command: |-\n  $ vendor/bin/regex diagram \\", $data);
        $this->assertStringContainsString("output: |-\n".$indentedOutput, $data);
    }
}
