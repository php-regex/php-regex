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
 * where its tokens do, in the value assigned to a variable or after a
 * keyword that nothing follows. What is read there is what the complete
 * part of the file gives.
 *
 * The tokenizer reads these alone: PHP parses none of the files cut
 * short.
 */
final class QuietReadingTest extends TestCase
{
    use TemporaryProject;

    /**
     * @return iterable<string, array{code: string, patterns: list<string>}>
     */
    public static function provideSources(): iterable
    {
        yield 'a heredoc that interpolates, with no indentation' => [
            'code' => "<?php\n\nfunction run(string \$s): int\n{\n    \$part = 'x';\n\n    return preg_match(<<<RE\n/^{\$part}\$/\nRE, \$s);\n}\n",
            'patterns' => ['/^x$/'],
        ];

        yield 'a file cut short in the value assigned to the variable read' => [
            'code' => "<?php\n\npreg_match(\$re, \$s);\n\$re = \$base",
            'patterns' => [],
        ];

        yield 'a file cut short after a for' => [
            'code' => "<?php\n\n\$re = '/x/';\npreg_match(\$re, \$s);\nfor",
            'patterns' => ['/x/'],
        ];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideSources')]
    public function test_a_source_is_read_without_a_warning(string $code, array $patterns): void
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
        foreach ($occurrences as $occurrence) {
            if (null === $occurrence->state) {
                $read[] = $occurrence->pattern;
            }
        }

        $this->assertSame($patterns, $read);
    }
}
