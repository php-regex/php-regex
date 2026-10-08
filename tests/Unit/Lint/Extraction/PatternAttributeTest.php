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

use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternAttributeScanner;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\Attribute\Pattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A parameter marked #[Pattern] makes its function a pattern function, as
 * if it were configured: a call to it in any linted file is read.
 */
final class PatternAttributeTest extends TestCase
{
    private const DECLARATIONS = <<<'CODE'
        <?php

        namespace App\Support;

        use PHPRegex\Parser\Attribute\Pattern;
        use PHPRegex\Parser\Attribute as Regex;

        final class Str
        {
            public static function matches(string $subject, #[Pattern] string $regex): bool
            {
                return 1 === preg_match($regex, $subject);
            }

            public static function first(#[\PHPRegex\Parser\Attribute\Pattern] string $regex, array $lines): ?string
            {
                return null;
            }

            public static function aliased(string $a, string $b, #[Regex\Pattern] string $regex): void
            {
            }

            public function onInstance(#[Pattern] string $regex): void
            {
            }
        }

        function grep(#[Pattern] string $regex, array $lines): array
        {
            $filter = static fn (#[Pattern] string $inner): bool => true;

            return [];
        }
        CODE;

    private const CALLS = <<<'CODE'
        <?php

        namespace App;

        use App\Support\Str;

        Str::matches($subject, '/(a+)+$/');
        Str::first('/first/', $lines);
        Str::aliased('x', 'y', '/aliased/');
        \App\Support\grep('/grep/', $lines);
        (new Str())->onInstance('/instance/');
        Other\Thing::matches($subject, '/other/');
        CODE;

    /**
     * @var list<string>
     */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_call_to_a_function_marked_with_the_attribute_is_read(\Closure $strategy): void
    {
        $this->assertTrue(class_exists(Pattern::class));
        $declarations = $this->write(self::DECLARATIONS);
        $calls = $this->write(self::CALLS);

        $found = [];
        foreach ($strategy()->extract([$declarations, $calls]) as $occurrence) {
            if ($occurrence->file === $calls) {
                $found[$occurrence->pattern] = $occurrence->line;
            }
        }

        $this->assertSame(['/(a+)+$/' => 7, '/first/' => 8, '/aliased/' => 9, '/grep/' => 10], $found);
    }

    /**
     * A class of another namespace named Pattern is not the attribute.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_an_attribute_of_another_namespace_is_not_read(\Closure $strategy): void
    {
        $declarations = $this->write(<<<'CODE'
            <?php

            namespace App;

            use Other\Pattern;

            function look(#[Pattern] string $text): void
            {
            }
            CODE);
        $calls = $this->write("<?php\n\\App\\look('/text/');\n");

        $patterns = array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $strategy()->extract([$declarations, $calls]));

        $this->assertNotContains('/text/', $patterns);
    }

    #[Test]
    public function test_the_scan_reads_declarations_as_php_writes_them(): void
    {
        $specs = PatternAttributeScanner::scan(<<<'CODE'
            <?php

            namespace App;

            use function strlen;
            use const PHP_EOL;
            use PHPRegex\Parser\{Attribute\Pattern, RegexParser};

            function &byReference(#[Pattern] string $regex) {}
            function plain(string $text) {}
            function defaults(array $options = [1, 2], string $mode = PHP_EOL, int $n = strlen('ab'), #[Pattern] string $regex = '/x/') {}
            function relative(#[namespace\Pattern] string $regex) {}
            function unimported(#[Unknown] string $regex) {}
            $closure = function () use ($x) { return 1; };
            function afterClosure(#[Pattern] string $regex) {}
            CODE);

        $this->assertSame(['App\byReference#0', 'App\defaults#3', 'App\afterClosure#0'], $specs);
    }

    /**
     * In the attribute's own namespace, the bare and the relative names
     * reach it with no import.
     */
    #[Test]
    public function test_the_scan_resolves_names_in_the_attribute_namespace(): void
    {
        $specs = PatternAttributeScanner::scan(<<<'CODE'
            <?php

            namespace PHPRegex\Parser\Attribute;

            function bare(#[Pattern] string $regex) {}
            function relative(#[namespace\Pattern] string $regex) {}
            CODE);

        $this->assertSame(['PHPRegex\Parser\Attribute\bare#0', 'PHPRegex\Parser\Attribute\relative#0'], $specs);
    }

    /**
     * A file being edited may stop anywhere: the scan reads what is there.
     */
    #[Test]
    public function test_the_scan_reads_a_truncated_file(): void
    {
        $this->assertSame([], PatternAttributeScanner::scan("<?php\nuse PHPRegex\\Parser\\Attribute\\Pattern"));
        $this->assertSame([], PatternAttributeScanner::scan("<?php\nfunction f"));
        $this->assertSame([], PatternAttributeScanner::scan("<?php\nfunction f(string \$a, array \$b = [1"));
    }

    /**
     * @return iterable<string, array{strategy: \Closure(): ExtractorInterface}>
     */
    public static function provideStrategies(): iterable
    {
        yield 'tokens' => ['strategy' => static fn (): ExtractorInterface => new TokenBasedExtractionStrategy()];
        yield 'php-parser' => ['strategy' => static fn (): ExtractorInterface => new PhpParserExtractionStrategy()];
    }

    private function write(string $content): string
    {
        $base = tempnam(sys_get_temp_dir(), 'regex-attribute-');
        $this->assertIsString($base);
        unlink($base);
        file_put_contents($base.'.php', $content);
        $this->files[] = $base.'.php';

        return $base.'.php';
    }
}
