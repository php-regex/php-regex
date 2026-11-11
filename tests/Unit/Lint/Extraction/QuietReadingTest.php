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

use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading a source raises nothing of PHP's, no warning and no notice,
 * whatever the source spells: a heredoc with no indentation has none to
 * take off its lines; a file cut short, as a buffer being typed is, ends
 * where its tokens do, in the value assigned to a variable, after a
 * keyword that nothing follows or in a heredoc nothing closes. What is
 * read there is what the complete part of the file gives, and a pattern
 * left open is an argument nothing is known of.
 *
 * The tokenizer reads these alone: PHP parses none of the files cut
 * short.
 */
final class QuietReadingTest extends TestCase
{
    use TemporaryProject;

    private const FILE = "<?php\n\n\$re = '/x/';\npreg_match(\$re, \$s);\n";

    private const BODY = "<?php\n\nfunction run(string \$s): void\n{\n    \$local = '/y/';\n    preg_match(\$local, \$s);\n    ";

    /**
     * Each row gives the patterns read and the state of each pattern
     * argument, in the order they are written.
     *
     * @return iterable<string, array{code: string, patterns: list<string>, states: list<string>}>
     */
    public static function provideSources(): iterable
    {
        yield 'a heredoc that interpolates, with no indentation' => [
            'code' => "<?php\n\nfunction run(string \$s): int\n{\n    \$part = 'x';\n\n    return preg_match(<<<RE\n/^{\$part}\$/\nRE, \$s);\n}\n",
            'patterns' => ['/^x$/'],
            'states' => ['constant'],
        ];

        yield 'a file cut short in the value assigned to the variable read' => [
            'code' => "<?php\n\npreg_match(\$re, \$s);\n\$re = \$base",
            'patterns' => [],
            'states' => ['opaque'],
        ];

        foreach (['for', 'new', 'new Options', 'new $class', 'new (', 'new #[Marked]'] as $tail) {
            yield 'a file cut short after "'.$tail.'"' => ['code' => self::FILE.$tail, 'patterns' => ['/x/'], 'states' => ['constant']];
        }

        // The body of the function is left open: its variable is not read.
        foreach (['$local = match ($s', '$local = match ($s)', '$local = match ($s) {'] as $tail) {
            yield 'a function cut short after "'.$tail.'"' => ['code' => self::BODY.$tail, 'patterns' => [], 'states' => ['opaque']];
        }

        yield 'a heredoc left open in the argument' => ['code' => "<?php\n\npreg_match(<<<RE\n/a/\n", 'patterns' => [], 'states' => ['opaque']];
        yield 'a nowdoc left open in the argument' => ['code' => "<?php\n\npreg_match(<<<'RE'\n/a/\n", 'patterns' => [], 'states' => ['opaque']];
    }

    /**
     * @param list<string> $patterns
     * @param list<string> $states
     */
    #[Test]
    #[DataProvider('provideSources')]
    public function test_a_source_is_read_without_a_warning(string $code, array $patterns, array $states): void
    {
        $directory = $this->makeProject(['subject.php' => $code]);
        $strategy = new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native());
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $occurrences = $strategy->extractWithArguments([$directory.'/subject.php']);
        } finally {
            restore_error_handler();
        }

        $read = [];
        $arguments = [];
        foreach ($occurrences as $occurrence) {
            if (null === $occurrence->state) {
                $read[] = $occurrence->pattern;
            } else {
                $arguments[] = $occurrence->state;
            }
        }

        $this->assertSame($patterns, $read);
        $this->assertSame($states, $arguments);
    }
}
