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

use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PHP warns about a source as it tokenizes it when a double-quoted literal
 * holds an octal escape above \377, and prints the warning where
 * display_errors says: on stdout by default. Every reader of a linted file
 * tokenizes it without a word, so stdout holds the report alone, a
 * checkstyle document included.
 */
final class LintSourceWarningTest extends TestCase
{
    use RunsRegexCli;
    use TemporaryProject;

    /**
     * Each file reaches a reader that tokenizes it: the inline markers,
     * the target markers, the attribute scan, the constants of another
     * file, the file-level variables and the spelling of a literal that
     * holds an invisible character.
     */
    private const FILES = [
        'lib/Patterns.php' => <<<'PHP'
            <?php

            namespace App;

            final class Patterns
            {
                public const DIGITS = '/^\d+$/';
                public const BYTE = "\600";
            }
            PHP,
        'lib/rules.php' => <<<'CODE'
            <?php

            namespace App;

            use PHPRegex\Parser\Attribute\RegexPattern;

            function check(#[RegexPattern] string $pattern): bool
            {
                return true;
            }

            $pattern = '/b+/';
            preg_match($pattern, $s);
            preg_match(Patterns::DIGITS, $s); // @regex-target php>=8.2
            check('/c+/');
            preg_match('/(a+)+$/', $s); // @regex-ignore regex.lint.redos

            CODE."preg_match('/a\u{200B}b/u', \$s);\n\$byte = \"\\600\";\n",
    ];

    /**
     * @param \Closure(string): void $parses
     */
    #[Test]
    #[DataProvider('provideFormats')]
    public function test_a_file_php_warns_about_leaves_stdout_to_the_report(string $format, \Closure $parses): void
    {
        $this->enterProject(self::FILES);
        self::assertPhpWarnsAbout(self::FILES['lib/Patterns.php']);

        $display = (string) \ini_get('display_errors');
        \ini_set('display_errors', '1');

        try {
            [, $stdout] = $this->runRegex(['lint', 'lib', '--format='.$format, '--jobs=1']);
        } finally {
            \ini_set('display_errors', $display);
        }

        $this->assertStringNotContainsString('Octal escape sequence overflow', $stdout);
        $parses($stdout);
    }

    /**
     * @return iterable<string, array{format: string, parses: \Closure(string): void}>
     */
    public static function provideFormats(): iterable
    {
        yield 'checkstyle' => ['format' => 'checkstyle', 'parses' => static function (string $stdout): void {
            self::assertNotSame('', $stdout);
            self::assertTrue((new \DOMDocument())->loadXML($stdout), $stdout);
        }];
        yield 'json' => ['format' => 'json', 'parses' => static function (string $stdout): void {
            self::assertIsArray(json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR));
        }];
    }

    /**
     * Without a PHP parser installed, the lint reads the files with the
     * tokenizer, which says nothing either.
     */
    #[Test]
    public function test_the_tokenizer_reads_a_file_php_warns_about_without_a_word(): void
    {
        $project = $this->makeProject(self::FILES);
        self::assertPhpWarnsAbout(self::FILES['lib/rules.php']);
        $source = new PhpFilePatternSource(new PatternExtractor(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native())));
        $display = (string) \ini_get('display_errors');
        \ini_set('display_errors', '1');
        ob_start();

        try {
            $occurrences = $source->extract(new PatternSourceContext([$project.'/lib'], []));
        } finally {
            $output = (string) ob_get_clean();
            \ini_set('display_errors', $display);
        }

        $this->assertSame('', $output);
        $this->assertContains('/(a+)+$/', array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
    }

    /**
     * PHP warns as it tokenizes the code, where display_errors says.
     */
    private static function assertPhpWarnsAbout(string $code): void
    {
        $display = (string) \ini_get('display_errors');
        \ini_set('display_errors', '1');
        ob_start();

        try {
            $tokens = token_get_all($code);
        } finally {
            $output = (string) ob_get_clean();
            \ini_set('display_errors', $display);
        }

        self::assertNotSame([], $tokens);
        self::assertStringContainsString('Octal escape sequence overflow \600 is greater than \377', $output);
    }
}
