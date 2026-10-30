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
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\Parallel\ForkedWorkerPool;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The extractor reads the constants of every file before the patterns, so
 * that a call of one file reads a constant of another, whatever the
 * number of workers; a pattern several files read is reported once.
 */
final class PatternExtractorCrossFileTest extends TestCase
{
    use TemporaryProject;

    private const PATTERNS = "<?php\n\nnamespace App;\n\nfinal class Patterns\n{\n    public const EMAIL = '/^[^@]+@[^@]+$/';\n    public const SLUG = '/^[a-z-]+$/'; // @regex-ignore\n}\n";
    private const READER = "<?php\n\nnamespace App\\Service;\n\nuse App\\Patterns;\n\npreg_match(Patterns::EMAIL, 'x');\npreg_match(Patterns::SLUG, 'x');\n";
    private const OTHER_READER = "<?php\n\npreg_match(\\App\\Patterns::EMAIL, 'y');\npreg_match('/own/', 'y');\n";

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
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategiesAndWorkers')]
    public function test_a_constant_of_another_file_is_reported_once_at_its_literal(string $strategy, int $workers): void
    {
        if ($workers > 1 && !ForkedWorkerPool::canFork()) {
            $workers = 1;
        }

        $project = $this->makeProject([
            'src/Patterns.php' => self::PATTERNS,
            'src/Service/Reader.php' => self::READER,
            'src/other.php' => self::OTHER_READER,
        ]);
        $occurrences = (new PatternExtractor(new $strategy([], PatternFunctionRegistry::native())))->extract([$project.'/src'], [], null, $workers);

        $this->assertSame([
            ['/^[^@]+@[^@]+$/', 'src/Patterns.php', 7, 'via Patterns::EMAIL', false],
            ['/^[a-z-]+$/', 'src/Patterns.php', 8, 'via Patterns::SLUG', true],
            ['/own/', 'src/other.php', 4, null, false],
        ], self::rows($occurrences, $project));
    }

    /**
     * The constants of the configured paths are read whatever files the
     * run lints, as the declarations are: linting one file still reads
     * the constant it names in another.
     */
    #[Test]
    public function test_the_declaration_paths_give_the_constants_of_a_file_the_run_does_not_lint(): void
    {
        $project = $this->makeProject([
            'src/Patterns.php' => self::PATTERNS,
            'src/Service/Reader.php' => self::READER,
        ]);
        $extractor = new PatternExtractor(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()));

        $linted = $extractor->extract([$project.'/src/Service/Reader.php'], [], null, 1, declarationPaths: [$project.'/src']);
        $this->assertSame([
            ['/^[^@]+@[^@]+$/', 'src/Patterns.php', 7, 'via Patterns::EMAIL', false],
            ['/^[a-z-]+$/', 'src/Patterns.php', 8, 'via Patterns::SLUG', true],
        ], self::rows($linted, $project));

        $alone = $extractor->extract([$project.'/src/Service/Reader.php'], [], null, 1);
        $this->assertSame([], self::rows($alone, $project), 'Without the declaration paths, the other file is not read.');
    }

    /**
     * A library's constant is read when a project file names it, from the
     * libraries the declarations are read from; a project class of the same
     * name hides it.
     */
    #[Test]
    public function test_a_library_constant_is_read_when_a_project_file_names_it(): void
    {
        $project = $this->makeProject([
            'src/Reader.php' => "<?php\n\nuse Acme\\Lib\\Patterns;\nuse Acme\\Lib\\Shadowed;\n\npreg_match(Patterns::RE, 'x');\npreg_match(Shadowed::RE, 'x');\n",
            'src/Shadowed.php' => "<?php\n\nnamespace Acme\\Lib;\n\nfinal class Shadowed\n{\n    public const RE = '/project/';\n}\n",
            'vendor/acme/lib/src/Patterns.php' => "<?php\n\nnamespace Acme\\Lib;\n\nfinal class Patterns\n{\n    public const RE = '/library/';\n    public const UNUSED = '/unused/';\n}\n",
            'vendor/acme/lib/src/Shadowed.php' => "<?php\n\nnamespace Acme\\Lib;\n\nfinal class Shadowed\n{\n    public const RE = '/library-shadowed/';\n}\n",
        ]);
        $extractor = new PatternExtractor(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()));

        $occurrences = $extractor->extract([$project.'/src'], ['vendor'], null, 1, vendorPaths: [$project.'/vendor']);

        $this->assertSame([
            ['/project/', 'src/Shadowed.php', 7, 'via Shadowed::RE', false],
            ['/library/', 'vendor/acme/lib/src/Patterns.php', 7, 'via Patterns::RE', false],
        ], self::rows($occurrences, $project));
    }

    /**
     * @param array<PatternOccurrence> $occurrences
     *
     * @return list<array{string, string, int, string|null, bool}> each pattern, its file, line, location and whether a marker silences it
     */
    private static function rows(array $occurrences, string $project): array
    {
        $rows = [];
        foreach ($occurrences as $occurrence) {
            if ('' === $occurrence->pattern) {
                continue;
            }

            $rows[] = [$occurrence->pattern, substr($occurrence->file, \strlen($project) + 1), $occurrence->line, $occurrence->location, $occurrence->isIgnored];
        }

        usort($rows, static fn (array $a, array $b): int => [$a[1], $a[2], $a[0]] <=> [$b[1], $b[2], $b[0]]);

        return $rows;
    }
}
