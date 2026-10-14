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
 * A pattern past the library's length limit, which PCRE compiles: every
 * pattern command reports the limit as the command's own failure, never as
 * an internal error of the application, and validate never calls it
 * invalid.
 */
final class PatternPastTheLimitTest extends TestCase
{
    /**
     * @return iterable<string, array{command: string}>
     */
    public static function provideCommands(): iterable
    {
        foreach (['analyze', 'debug', 'diagram', 'highlight', 'parse', 'explain', 'transpile', 'graph', 'format'] as $command) {
            yield $command => ['command' => $command];
        }
    }

    #[Test]
    public function test_pcre_compiles_the_pattern(): void
    {
        $this->assertSame(1, preg_match(self::pattern(), 'a'));
    }

    #[Test]
    #[DataProvider('provideCommands')]
    public function test_a_command_reports_the_limit_as_its_own_failure(string $command): void
    {
        [$exitCode, $stdout] = $this->runRegex([$command, self::pattern()]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('exceeds maximum length of 100000 characters', $stdout);
        $this->assertStringNotContainsString('"internal"', $stdout, 'The limit reached the application boundary.');
    }

    #[Test]
    public function test_validate_says_the_pattern_was_not_analyzed(): void
    {
        [$exitCode, $stdout] = $this->runRegex(['validate', self::pattern()]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('NOT ANALYZED', $stdout);
        $this->assertStringNotContainsString('INVALID', $stdout);
        $this->assertStringContainsString('raise the "max_pattern_length" option', $stdout);
    }

    /**
     * On a terminal the pattern is drawn in colour, from its tree: the limit
     * met there leaves it drawn as written.
     *
     * @return iterable<string, array{command: string}>
     */
    public static function provideHighlightingCommands(): iterable
    {
        yield 'debug' => ['command' => 'debug'];
        yield 'redos' => ['command' => 'redos'];
    }

    #[Test]
    #[DataProvider('provideHighlightingCommands')]
    public function test_a_command_drawing_the_pattern_in_colour_survives_the_limit(string $command): void
    {
        [, $written] = $this->runRegex([$command, self::pattern()], true);

        $this->assertStringContainsString(str_repeat('x', 100), $written);
        $this->assertStringNotContainsString('"internal"', $written, 'The limit reached the application boundary.');
    }

    /**
     * Past the default "max_pattern_length" of 100,000 bytes, yet compiled
     * small: the comment adds no code.
     */
    private static function pattern(): string
    {
        return '/a(?#'.str_repeat('x', 100_000).')/';
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string} the exit code, and what was written to stdout and stderr
     */
    private function runRegex(array $arguments, bool $terminal = false): array
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);

        $application = ApplicationFactory::create(new Output($terminal, false, '#', '-', $errors));

        ob_start();

        try {
            $exitCode = $application->run(['regex', ...($terminal ? ['--ansi'] : ['--no-ansi', '--no-visuals']), ...$arguments]);
        } finally {
            $written = (string) ob_get_clean();
            rewind($errors);
            $written .= (string) stream_get_contents($errors);
            fclose($errors);
        }

        return [$exitCode, $written];
    }
}
