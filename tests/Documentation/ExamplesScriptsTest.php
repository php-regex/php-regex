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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExamplesScriptsTest extends TestCase
{
    /**
     * @return \Generator<string, array{script: string}>
     */
    public static function provideScripts(): \Generator
    {
        yield 'basic parse' => ['script' => 'examples/basic/parse.php'];
        yield 'basic validate' => ['script' => 'examples/basic/validate.php'];
        yield 'advanced redos analysis' => ['script' => 'examples/advanced/redos-analysis.php'];
        yield 'real-world email validator' => ['script' => 'examples/real-world/email-validator.php'];
        yield 'symfony route analyzer' => ['script' => 'examples/symfony/route-analyzer.php'];
    }

    #[DataProvider('provideScripts')]
    public function test_example_script_runs_clean(string $script): void
    {
        [$output, $exitCode] = $this->runScript($script);

        $this->assertSame(0, $exitCode, $output);
        $this->assertNotSame('', $output);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function runScript(string $script): array
    {
        $path = \dirname(__DIR__, 2).'/'.$script;
        $command = [\PHP_BINARY, $path];
        $escaped = array_map(escapeshellarg(...), $command);
        $output = [];
        $exitCode = 0;

        exec(implode(' ', $escaped).' 2>&1', $output, $exitCode);

        return [implode("\n", $output), $exitCode];
    }
}
