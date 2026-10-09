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
use PHPRegex\Parser\Attribute\RegexPattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A parameter marked #[RegexPattern] makes its function a pattern function, as
 * if it were configured: a call to it in any linted file is read.
 */
final class RegexPatternAttributeTest extends TestCase
{
    private const DECLARATIONS = <<<'CODE'
        <?php

        namespace App\Support;

        use PHPRegex\Parser\Attribute\RegexPattern;
        use PHPRegex\Parser\Attribute as Regex;

        final class Str
        {
            public static function matches(string $subject, #[RegexPattern] string $regex): bool
            {
                return 1 === preg_match($regex, $subject);
            }

            public static function first(#[\PHPRegex\Parser\Attribute\RegexPattern] string $regex, array $lines): ?string
            {
                return null;
            }

            public static function aliased(string $a, string $b, #[Regex\RegexPattern] string $regex): void
            {
            }

            public function onInstance(#[RegexPattern] string $regex): void
            {
            }
        }

        function grep(#[RegexPattern] string $regex, array $lines): array
        {
            $filter = static fn (#[RegexPattern] string $inner): bool => true;

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
        $this->assertTrue(class_exists(RegexPattern::class));
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
     * A class of another namespace named RegexPattern is not the attribute.
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

            use Other\RegexPattern;

            function look(#[RegexPattern] string $text): void
            {
            }
            CODE);
        $calls = $this->write("<?php\n\\App\\look('/text/');\n");

        $patterns = array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $strategy()->extract([$declarations, $calls]));

        $this->assertNotContains('/text/', $patterns);
    }

    /**
     * PHP calls App\grep() for grep() written in namespace App, when that
     * function exists: the declaring file's own calls are read.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_an_unqualified_call_in_the_declaring_namespace_is_read(\Closure $strategy): void
    {
        $file = $this->write(<<<'CODE'
            <?php

            namespace App;

            use PHPRegex\Parser\Attribute\RegexPattern;

            function grep(#[RegexPattern] string $regex, array $lines): array
            {
                return [];
            }

            grep('/same/', []);
            CODE);

        $found = [];
        foreach ($strategy()->extract([$file]) as $occurrence) {
            $found[$occurrence->pattern] = $occurrence->line;
        }

        $this->assertSame(['/same/' => 12], $found);
    }

    #[Test]
    public function test_the_scan_reads_declarations_as_php_writes_them(): void
    {
        $specs = PatternAttributeScanner::scan(<<<'CODE'
            <?php

            namespace App;

            use function strlen;
            use const PHP_EOL;
            use PHPRegex\Parser\{Attribute\RegexPattern, RegexParser};

            function &byReference(#[RegexPattern] string $regex) {}
            function plain(string $text) {}
            function defaults(array $options = [1, 2], string $mode = PHP_EOL, int $n = strlen('ab'), #[RegexPattern] string $regex = '/x/') {}
            function relative(#[namespace\RegexPattern] string $regex) {}
            function unimported(#[Unknown] string $regex) {}
            $closure = function () use ($x) { return 1; };
            function afterClosure(#[RegexPattern] string $regex) {}
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

            function bare(#[RegexPattern] string $regex) {}
            function relative(#[namespace\RegexPattern] string $regex) {}
            CODE);

        $this->assertSame(['PHPRegex\Parser\Attribute\bare#0', 'PHPRegex\Parser\Attribute\relative#0'], $specs);
    }

    /**
     * PhpStorm's #[Language('RegExp')] (jetbrains/phpstorm-attributes) marks
     * a regex parameter too; any other language is not one.
     */
    #[Test]
    public function test_phpstorms_language_attribute_marks_a_regex_parameter(): void
    {
        $specs = PatternAttributeScanner::scan(<<<'CODE'
            <?php

            namespace App;

            use JetBrains\PhpStorm\Language;

            function single(#[Language('RegExp')] string $regex) {}
            function double(string $subject, #[Language("RegExp")] string $regex) {}
            function named(#[Language(languageName: 'RegExp')] string $regex) {}
            function qualified(#[\JetBrains\PhpStorm\Language('RegExp')] string $regex) {}
            function sql(#[Language('SQL')] string $query) {}
            function bare(#[Language] string $text) {}
            CODE);

        $this->assertSame(['App\single#0', 'App\double#1', 'App\named#0', 'App\qualified#0'], $specs);
    }

    /**
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_call_to_a_function_marked_for_phpstorm_is_read(\Closure $strategy): void
    {
        $declarations = $this->write(<<<'CODE'
            <?php

            namespace App;

            use JetBrains\PhpStorm\Language;

            function grep(#[Language('RegExp')] string $regex, array $lines): array
            {
                return [];
            }
            CODE);
        $calls = $this->write("<?php\n\\App\\grep('/(a+)+$/', []);\n");

        $patterns = array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $strategy()->extract([$declarations, $calls]));

        $this->assertContains('/(a+)+$/', $patterns);
    }

    /**
     * A file being edited may stop anywhere: the scan reads what is there.
     */
    #[Test]
    public function test_the_scan_reads_a_truncated_file(): void
    {
        $this->assertSame([], PatternAttributeScanner::scan("<?php\nuse PHPRegex\\Parser\\Attribute\\RegexPattern"));
        $this->assertSame([], PatternAttributeScanner::scan("<?php\nfunction f"));
        $this->assertSame([], PatternAttributeScanner::scan("<?php\nfunction f(string \$a, array \$b = [1"));
    }

    /**
     * A file reaching the attribute through a group import, or through an
     * import of one of its parent namespaces, is read: it names neither
     * namespace in full.
     */
    #[Test]
    public function test_a_group_imported_attribute_is_read_from_a_file(): void
    {
        $phpstorm = $this->write("<?php\nnamespace Acme;\nuse JetBrains\\PhpStorm\\{Language, Pure};\nclass Str { public static function find(#[Language('RegExp')] string \$p) {} }\n");
        $partial = $this->write("<?php\nnamespace Acme;\nuse PHPRegex\\Parser;\nclass Str2 { public static function find(#[Parser\\Attribute\\RegexPattern] string \$p) {} }\n");

        $this->assertSame(['Acme\Str::find#0', 'Acme\Str2::find#0'], PatternAttributeScanner::specs([$phpstorm, $partial]));
    }

    /**
     * A method may be named with a word PHP reserves elsewhere: match,
     * list, print.
     */
    #[Test]
    public function test_a_method_named_match_is_read(): void
    {
        $specs = PatternAttributeScanner::scan(<<<'CODE'
            <?php

            namespace App;

            use PHPRegex\Parser\Attribute\RegexPattern;

            final class Str
            {
                public static function match(#[RegexPattern] string $regex) {}
                public static function list(string $subject, #[RegexPattern] string $regex) {}
                public function &print(#[RegexPattern] string $regex) {}
            }
            CODE);

        $this->assertSame(['App\Str::match#0', 'App\Str::list#1', 'App\Str::print#0'], $specs);
    }

    /**
     * A file too large to tokenize in the memory left is not even read: a
     * huge file of vendor/ cannot exhaust memory_limit while its size alone
     * says so. A stream wrapper stands for a file of 1 GB.
     */
    #[Test]
    public function test_the_scan_does_not_read_a_file_too_large_for_the_memory_left(): void
    {
        $wrapper = new class {
            public static bool $opened = false;

            /**
             * @var resource|null
             */
            public $context;

            /**
             * @return array<string, int>
             */
            public function url_stat(string $path, int $flags): array
            {
                return ['mode' => 0o100644, 'size' => 1024 ** 3];
            }

            public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
            {
                self::$opened = true;

                return false;
            }
        };
        $limit = ini_get('memory_limit');
        $this->assertTrue(stream_wrapper_register('regex-huge-file', $wrapper::class));
        ini_set('memory_limit', (string) (memory_get_usage(true) + 32 * 1024 * 1024));

        try {
            $this->assertTrue(is_file('regex-huge-file://huge.php'));
            $specs = PatternAttributeScanner::specs(['regex-huge-file://huge.php']);
        } finally {
            ini_set('memory_limit', \is_string($limit) ? $limit : '-1');
            stream_wrapper_unregister('regex-huge-file');
        }

        $this->assertSame([], $specs);
        $this->assertFalse($wrapper::$opened);
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
