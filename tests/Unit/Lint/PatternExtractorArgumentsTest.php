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

namespace PHPRegex\Tests\Unit\Lint;

use PhpParser\ParserFactory;
use PHPRegex\Linter\Extraction\ArgumentAwareInterface;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\Parallel\ForkedWorkerPool;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\HelperCallExtractor;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pattern arguments travel on the path of the lint run only: scan()
 * gives them, each with its state, where the extractor can; extract()
 * gives the patterns and nothing else, for every caller it has.
 */
final class PatternExtractorArgumentsTest extends TestCase
{
    use TemporaryProject;

    private const WHOLE = "<?php\n\nfunction whole(string \$s): int\n{\n    return preg_match('/whole/', \$s);\n}\n";
    private const DYNAMIC = "<?php\n\nfunction dynamic(string \$s, string \$re): int\n{\n    return preg_match(\$re, \$s);\n}\n";
    private const SHELLS = "<?php\n\nfunction shells(string \$s, string \$word): int\n{\n    return preg_match('/^' . \$word . '$/', \$s)\n        + preg_match('/^été' . preg_quote(\$word, '/') . '$/u', \$s);\n}\n";

    private const PATTERNS = "<?php\n\nnamespace App;\n\nfinal class Patterns\n{\n    public const EMAIL = '/^[^@]+@[^@]+$/';\n}\n";
    private const READER = "<?php\n\nnamespace App\\Service;\n\nuse App\\Patterns;\n\npreg_match(Patterns::EMAIL, 'x');\n";
    private const OTHER_READER = "<?php\n\npreg_match(\\App\\Patterns::EMAIL, 'y');\n";

    /**
     * @return iterable<string, array{strategy: class-string<ExtractorInterface>, workers: int}>
     */
    public static function provideStrategiesAndWorkers(): iterable
    {
        foreach ([1, 3] as $workers) {
            yield 'tokens, '.$workers.' worker(s)' => ['strategy' => TokenBasedExtractionStrategy::class, 'workers' => $workers];
            if (class_exists(ParserFactory::class)) {
                yield 'php-parser, '.$workers.' worker(s)' => ['strategy' => PhpParserExtractionStrategy::class, 'workers' => $workers];
            }
        }
    }

    /**
     * Each file is read in its own worker when there are several: an
     * argument comes back from it whole, its state and its expression
     * with it, multi-byte text included.
     *
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategiesAndWorkers')]
    public function test_scan_gives_each_argument_with_its_state_and_extract_gives_none(string $strategy, int $workers): void
    {
        if ($workers > 1 && !ForkedWorkerPool::canFork()) {
            $workers = 1;
        }

        $project = $this->makeProject([
            'src/a_whole.php' => self::WHOLE,
            'src/b_dynamic.php' => self::DYNAMIC,
            'src/c_shells.php' => self::SHELLS,
        ]);
        $extractor = new PatternExtractor(new $strategy([], PatternFunctionRegistry::native()));

        $scanned = $extractor->scan([$project.'/src'], [], null, $workers);

        $this->assertSame([
            ['constant', "'/whole/'", 'src/a_whole.php', 5, 23, 'preg_match()'],
            ['opaque', '$re', 'src/b_dynamic.php', 5, 23, 'preg_match()'],
            ['raw_shell', "'/^' . \$word . '$/'", 'src/c_shells.php', 5, 23, 'preg_match()'],
            ['quoted_shell', "'/^été' . preg_quote(\$word, '/') . '$/u'", 'src/c_shells.php', 6, 22, 'preg_match()'],
        ], self::arguments($scanned, $project));
        $this->assertSame(['/whole/'], self::patterns($scanned));
        // Each file read still gives its markers, after its occurrences.
        $this->assertCount(3, array_filter($scanned, static fn (PatternOccurrence $occurrence): bool => null !== $occurrence->ignores));

        $extracted = $extractor->extract([$project.'/src'], [], null, $workers);

        $this->assertSame([], self::arguments($extracted, $project));
        $this->assertSame(['/whole/'], array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $extracted));
    }

    /**
     * A file the paths name twice, by its own path and inside its
     * directory, or under two spellings of one path, is read once: its
     * arguments are counted once, as they are written once.
     *
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategiesAndWorkers')]
    public function test_a_file_given_twice_gives_its_arguments_once(string $strategy, int $workers): void
    {
        if ($workers > 1 && !ForkedWorkerPool::canFork()) {
            $workers = 1;
        }

        $project = $this->makeProject([
            'src/a_whole.php' => self::WHOLE,
            'src/b_dynamic.php' => self::DYNAMIC,
        ]);
        $extractor = new PatternExtractor(new $strategy([], PatternFunctionRegistry::native()));

        $given = [
            'the directory, then one of its files' => [$project.'/src', $project.'/src/b_dynamic.php'],
            'a file, then its directory' => [$project.'/src/b_dynamic.php', $project.'/src'],
            'the directory twice' => [$project.'/src', $project.'/src'],
            'the directory, then another spelling of it' => [$project.'/src', $project.'/src/../src'],
            'a file, then another spelling of it' => [$project.'/src/a_whole.php', $project.'/src/b_dynamic.php', $project.'/src/./b_dynamic.php'],
        ];
        foreach ($given as $name => $paths) {
            $scanned = $extractor->scan($paths, [], null, $workers);

            $this->assertSame([
                ['constant', "'/whole/'", 'src/a_whole.php', 5, 23, 'preg_match()'],
                ['opaque', '$re', 'src/b_dynamic.php', 5, 23, 'preg_match()'],
            ], self::arguments($scanned, $project), $name);
            $this->assertSame(['/whole/'], self::patterns($scanned), $name);
            // Each file is read once.
            $this->assertCount(2, array_filter($scanned, static fn (PatternOccurrence $occurrence): bool => null !== $occurrence->ignores), $name);
        }
    }

    /**
     * A constant of one file that two files read is one pattern, where its
     * literal is written, and two arguments, each where its call names
     * the constant: the "reported once" rule passes the arguments through.
     *
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategiesAndWorkers')]
    public function test_a_constant_of_another_file_read_twice_is_one_pattern_and_two_arguments(string $strategy, int $workers): void
    {
        if ($workers > 1 && !ForkedWorkerPool::canFork()) {
            $workers = 1;
        }

        $project = $this->makeProject([
            'src/Patterns.php' => self::PATTERNS,
            'src/Service/Reader.php' => self::READER,
            'src/other.php' => self::OTHER_READER,
        ]);

        $scanned = (new PatternExtractor(new $strategy([], PatternFunctionRegistry::native())))->scan([$project.'/src'], [], null, $workers);

        $this->assertSame(['/^[^@]+@[^@]+$/'], self::patterns($scanned));
        $this->assertSame([
            ['constant', 'Patterns::EMAIL', 'src/Service/Reader.php', 7, 12, 'preg_match()'],
            ['constant', '\\App\\Patterns::EMAIL', 'src/other.php', 3, 12, 'preg_match()'],
        ], self::arguments($scanned, $project));
    }

    /**
     * Without the other file, the constant proves nothing: the argument is
     * there all the same, and nothing is known of it.
     */
    #[Test]
    public function test_a_constant_of_a_file_the_run_does_not_read_is_an_opaque_argument(): void
    {
        $project = $this->makeProject(['src/Service/Reader.php' => self::READER]);

        $scanned = (new PatternExtractor(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native())))->scan([$project.'/src'], [], null, 1);

        $this->assertSame([], self::patterns($scanned));
        $this->assertSame([
            ['opaque', 'Patterns::EMAIL', 'src/Service/Reader.php', 7, 12, 'preg_match()'],
        ], self::arguments($scanned, $project));
    }

    /**
     * An extractor of one's own gives patterns and no argument: its
     * arguments are not counted. The built-in one, on the same file,
     * gives the argument of the call it knows.
     */
    #[Test]
    public function test_an_extractor_that_gives_no_argument_yields_none(): void
    {
        $project = $this->makeProject(['src/checks.php' => "<?php\n\ncheck('/own/', \$s);\npreg_match(\$re, \$s);\n"]);

        $own = (new PatternExtractor(new HelperCallExtractor()))->scan([$project.'/src'], [], null, 1);
        $this->assertSame(['/own/'], self::patterns($own));
        $this->assertSame([], self::arguments($own, $project));

        $builtIn = (new PatternExtractor(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native())))->scan([$project.'/src'], [], null, 1);
        $this->assertSame([], self::patterns($builtIn));
        $this->assertSame([
            ['opaque', '$re', 'src/checks.php', 4, 12, 'preg_match()'],
        ], self::arguments($builtIn, $project));
    }

    /**
     * scan() asks an extractor that can for its arguments, and extract()
     * never does.
     */
    #[Test]
    public function test_scan_asks_for_the_arguments_and_extract_does_not(): void
    {
        $project = $this->makeProject(['src/subject.php' => "<?php\n\npreg_match(\$re, \$s);\n"]);
        $extractor = new class implements ArgumentAwareInterface, ExtractorInterface {
            /**
             * @var list<string>
             */
            public array $asked = [];

            public function extract(array $files): array
            {
                $this->asked[] = 'extract';

                return [PatternOccurrence::found('/found/', $files[0] ?? '', 3, 12, 'own()')];
            }

            public function extractWithArguments(array $files): array
            {
                $this->asked[] = 'extractWithArguments';

                return [
                    PatternOccurrence::found('/found/', $files[0] ?? '', 3, 12, 'own()'),
                    PatternOccurrence::argument('opaque', $files[0] ?? '', 3, 12, 18, 'own()', '$re'),
                ];
            }
        };

        $scanned = (new PatternExtractor($extractor))->scan([$project.'/src'], [], null, 1);

        $this->assertSame(['extractWithArguments'], array_values(array_unique($extractor->asked)));
        $this->assertSame(['/found/'], self::patterns($scanned));
        $this->assertSame([['opaque', '$re', 'src/subject.php', 3, 12, 'own()']], self::arguments($scanned, $project));

        $extractor->asked = [];
        $extracted = (new PatternExtractor($extractor))->extract([$project.'/src'], [], null, 1);

        $this->assertSame(['extract'], array_values(array_unique($extractor->asked)));
        $this->assertSame([], self::arguments($extracted, $project));
        $this->assertSame(['/found/'], array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $extracted));
    }

    /**
     * The state, the expression, the file, the line, the column and the
     * source of each argument, in the order of their places.
     *
     * @param array<PatternOccurrence> $occurrences
     *
     * @return list<array{string, string|null, string, int, int|null, string}>
     */
    private static function arguments(array $occurrences, string $project): array
    {
        $arguments = [];
        foreach ($occurrences as $occurrence) {
            if (null !== $occurrence->state) {
                $arguments[] = [$occurrence->state, $occurrence->expression, substr($occurrence->file, \strlen($project) + 1), $occurrence->line, $occurrence->column, $occurrence->source];
            }
        }

        usort($arguments, static fn (array $a, array $b): int => [$a[2], $a[3], $a[4]] <=> [$b[2], $b[3], $b[4]]);

        return $arguments;
    }

    /**
     * The patterns among the occurrences: neither an argument nor a file
     * read.
     *
     * @param array<PatternOccurrence> $occurrences
     *
     * @return list<string>
     */
    private static function patterns(array $occurrences): array
    {
        $patterns = [];
        foreach ($occurrences as $occurrence) {
            if (null === $occurrence->state && null === $occurrence->ignores && null === $occurrence->unread && null === $occurrence->parserFallback) {
                $patterns[] = $occurrence->pattern;
            }
        }

        sort($patterns);

        return $patterns;
    }
}
