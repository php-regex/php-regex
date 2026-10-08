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

use PHPRegex\Cli\ApplicationFactory;
use PHPRegex\Cli\Command\TranspileCommand;
use PHPRegex\Cli\Output;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "regex transpile --target=html" prints the value of an HTML pattern
 * attribute, and the command names the target in its help.
 */
final class TranspileHtmlTargetTest extends TestCase
{
    #[Test]
    public function test_the_html_target_prints_the_attribute_value(): void
    {
        [$code, $output] = $this->runRegex(['transpile', '/\d{4}/', '--target=html', '--format=json']);

        $this->assertSame(0, $code);
        $report = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);
        $this->assertSame('html-pattern', $report['target'] ?? null);
        $this->assertSame('[\s\S]*(?:\d{4})[\s\S]*', $report['literal'] ?? null);
    }

    #[Test]
    public function test_the_command_names_the_html_target(): void
    {
        $this->assertSame('Transpile PCRE regex to other dialects (js, html, python)', (new TranspileCommand())->getDescription());

        [$code, , $errors] = $this->runRegex(['transpile']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('[--target=js|html|python]', $errors);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function runRegex(array $arguments): array
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);

        $application = ApplicationFactory::create(new Output(false, false, '#', '-', $errors));

        ob_start();

        try {
            $code = $application->run(['regex', '--no-ansi', '--no-visuals', ...$arguments]);
        } finally {
            $output = (string) ob_get_clean();
        }

        rewind($errors);
        $written = (string) stream_get_contents($errors);
        fclose($errors);

        return [$code, $output, $written.$output];
    }
}
