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
use PHPRegex\Cli\Output;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "regex transpile" on a console: the result names the dialect and the
 * source before the translation; a pattern the parser refuses fails with
 * its message, whichever stage of the parser met the fault; and a valid
 * pattern the dialect cannot carry fails with the message of the dialect
 * and where the construct stands.
 */
final class TranspileConsoleOutputTest extends TestCase
{
    #[Test]
    public function test_the_result_names_the_dialect_and_the_source(): void
    {
        [$code, $output] = $this->runRegex(['transpile', '/\d{4}/', '--target=js']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("  Target: JAVASCRIPT\n  Source: /\\d{4}/\n", $output);
        $this->assertStringContainsString('new RegExp("\\\\d{4}", "")', $output);
    }

    #[Test]
    #[DataProvider('provideMalformedPatterns')]
    public function test_a_malformed_pattern_fails_with_its_message(string $regex, string $message): void
    {
        [$code, $output] = $this->runRegex(['transpile', $regex, '--target=js']);

        $this->assertSame(1, $code);
        $this->assertSame('  Transpile failed: '.$message."\n", $output);
    }

    /**
     * @return iterable<string, array{regex: string, message: string}>
     */
    public static function provideMalformedPatterns(): iterable
    {
        yield 'a group left open, met while reading the grammar' => ['regex' => '/a(/', 'message' => 'Expected ) at end of input (found eof).'];
        yield 'a class left open, met while reading the tokens' => ['regex' => '/[a/', 'message' => 'Unclosed character class "]" at end of input.'];
    }

    #[Test]
    public function test_a_construct_the_dialect_cannot_carry_fails_with_its_offset(): void
    {
        [$code, $output] = $this->runRegex(['transpile', '/a\Kb/', '--target=js']);

        $this->assertSame(1, $code);
        $this->assertSame("  Transpile failed: \\K is not supported in JavaScript.\n  At offset 1\n", $output);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
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

        fclose($errors);

        return [$code, $output];
    }
}
