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
use PHPRegex\Linter\Extraction\PatternFunctionAwareInterface;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A function whose parameter is marked #[RegexPattern] is a pattern function
 * wherever it is declared: in a file linted with its callers or apart from
 * them, in a project file the run does not lint, or in vendor/. The marked
 * functions are read once, before the files are shared out, so no split of
 * the work loses them.
 */
final class PatternFunctionPrescanTest extends TestCase
{
    use TemporaryProject;

    private const HELPER = <<<'CODE'
        <?php

        namespace App;

        use PHPRegex\Parser\Attribute\RegexPattern;

        function grep(#[RegexPattern] string $regex, string $subject): void
        {
        }
        CODE;

    private const CALLER = "<?php\n\n\\App\\grep('/b02(/', 'x');\n";

    private string $memoryLimit = '-1';

    protected function setUp(): void
    {
        $limit = ini_get('memory_limit');
        $this->memoryLimit = \is_string($limit) ? $limit : '-1';
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memoryLimit);
        LintFunctionOverrides::reset();
    }

    /**
     * Two files on four workers: the declaration and the call land in two
     * chunks, each extracted by a child of its own.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    #[RequiresFunction('pcntl_fork')]
    public function test_an_attribute_declared_in_another_file_is_read_with_workers(\Closure $strategy): void
    {
        $project = $this->makeProject(['lib/a_helper.php' => self::HELPER, 'lib/b_caller.php' => self::CALLER]);

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/lib'], [], null, 4);

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
    }

    /**
     * The console's run: workers, and a progress bar.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    #[RequiresFunction('pcntl_fork')]
    public function test_an_attribute_declared_in_another_file_is_read_with_workers_and_progress(\Closure $strategy): void
    {
        $project = $this->makeProject(['lib/a_helper.php' => self::HELPER, 'lib/b_caller.php' => self::CALLER]);
        $calls = [];

        $occurrences = (new PatternExtractor($strategy()))->extract(
            [$project.'/lib'],
            [],
            static function (int $current, int $total) use (&$calls): void {
                $calls[] = [$current, $total];
            },
            4,
        );

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
        $this->assertSame([[0, 2], [1, 2], [2, 2]], $calls);
    }

    /**
     * The declarations the workers read back are kept when no worker can
     * be started for the extraction, which then runs in the parent.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    #[RequiresFunction('pcntl_fork')]
    public function test_the_declarations_read_on_workers_survive_an_extraction_in_the_parent(\Closure $strategy): void
    {
        $project = $this->makeProject([
            'lib/a_helper.php' => self::HELPER,
            'lib/b_caller.php' => self::CALLER,
            'payload/a' => serialize(['ok' => true, 'result' => ['App\grep#0']]),
            'payload/b' => serialize(['ok' => true, 'result' => 'not a list']),
        ]);
        // The two children of the scan "write" these payloads; the first
        // child of the extraction cannot be forked.
        LintFunctionOverrides::queueTempnam($project.'/payload/a');
        LintFunctionOverrides::queueTempnam($project.'/payload/b');
        LintFunctionOverrides::queuePcntlForkResult(111);
        LintFunctionOverrides::queuePcntlForkResult(222);
        LintFunctionOverrides::queuePcntlForkResult(-1);
        LintFunctionOverrides::$pcntlWaitpidResult = 0;

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/lib'], [], null, 2);

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
    }

    /**
     * When no child can be forked to read the declarations, the parent
     * reads them itself.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    #[RequiresFunction('pcntl_fork')]
    public function test_the_declarations_are_read_in_the_parent_when_no_worker_starts(\Closure $strategy): void
    {
        $project = $this->makeProject(['lib/a_helper.php' => self::HELPER, 'lib/b_caller.php' => self::CALLER]);
        LintFunctionOverrides::queuePcntlForkResult(-1);

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/lib'], [], null, 2);

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
    }

    /**
     * A progress callback has the files extracted one at a time.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_an_attribute_declared_in_another_file_is_read_with_progress(\Closure $strategy): void
    {
        $project = $this->makeProject(['lib/a_helper.php' => self::HELPER, 'lib/b_caller.php' => self::CALLER]);
        $calls = [];

        $occurrences = (new PatternExtractor($strategy()))->extract(
            [$project.'/lib'],
            [],
            static function (int $current, int $total) use (&$calls): void {
                $calls[] = [$current, $total];
            },
        );

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
        $this->assertSame([[0, 2], [1, 2], [2, 2]], $calls);
    }

    /**
     * vendor/ is not linted, yet a library's marked function is read; the
     * name of the vendor directory, in UTF-8, does not stop it.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_an_attribute_declared_in_vendor_is_read_when_vendor_is_excluded(\Closure $strategy): void
    {
        $project = $this->makeProject(['lib/caller.php' => self::CALLER, 'vendor/acmé/grep/helper.php' => self::HELPER]);

        $occurrences = (new PatternExtractor($strategy()))->extract(
            [$project],
            ['vendor'],
            declarationPaths: [$project, $project.'/vendor'],
        );

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
        $this->assertSame([$project.'/lib/caller.php'], array_values(array_unique(array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->file, $occurrences))));
    }

    /**
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_partial_lint_sees_attributes_of_unlinted_project_files(\Closure $strategy): void
    {
        $project = $this->makeProject(['lib/Support/helper.php' => self::HELPER, 'lib/caller.php' => self::CALLER]);

        $occurrences = (new PatternExtractor($strategy()))->extract(
            [$project.'/lib/caller.php'],
            ['vendor'],
            declarationPaths: [$project.'/lib'],
        );

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
    }

    /**
     * An extractor of the project's own (the Symfony bundle takes one as a
     * service) knows nothing of the declarations read beforehand: it is
     * handed the linted files, as before.
     */
    #[Test]
    public function test_a_custom_extractor_without_the_aware_interface_keeps_working(): void
    {
        $project = $this->makeProject(['lib/caller.php' => self::CALLER, 'vendor/acme/helper.php' => self::HELPER]);
        $extractor = new class implements ExtractorInterface {
            /**
             * @var list<list<string>>
             */
            public array $calls = [];

            public function extract(array $files): array
            {
                $this->calls[] = array_values($files);

                return array_map(static fn (string $file): PatternOccurrence => new PatternOccurrence('/custom/', $file, 1, 'custom'), $files);
            }
        };

        $occurrences = (new PatternExtractor($extractor))->extract(
            [$project.'/lib'],
            ['vendor'],
            declarationPaths: [$project.'/vendor'],
        );

        $this->assertSame([[$project.'/lib/caller.php']], $extractor->calls);
        $this->assertSame(['/custom/'], self::patterns($occurrences));
    }

    /**
     * A declaration path may be a single file; one that does not exist is
     * passed over.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_declaration_path_may_be_a_file_or_missing(\Closure $strategy): void
    {
        $project = $this->makeProject(['lib/caller.php' => self::CALLER, 'packages/helper.php' => self::HELPER]);

        $occurrences = (new PatternExtractor($strategy()))->extract(
            [$project.'/lib'],
            ['vendor'],
            declarationPaths: [$project.'/nowhere', $project.'/packages/helper.php'],
        );

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
    }

    /**
     * What the run excludes is excluded from the declarations too, apart
     * from vendor/ when it is named as a declaration path itself.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_an_excluded_directory_of_a_declaration_path_is_not_read(\Closure $strategy): void
    {
        $project = $this->makeProject(['lib/caller.php' => self::CALLER, 'lib/Fixtures/helper.php' => self::HELPER]);

        $occurrences = (new PatternExtractor($strategy()))->extract(
            [$project.'/lib/caller.php'],
            ['Fixtures'],
            declarationPaths: [$project.'/lib'],
        );

        $this->assertSame([], self::patterns($occurrences));
    }

    /**
     * A vendor file that cannot be read is skipped silently: vendor/ is not
     * linted, so nothing reports it.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_vendor_file_that_cannot_be_read_is_skipped(\Closure $strategy): void
    {
        $project = $this->makeProject([
            'lib/caller.php' => self::CALLER,
            'vendor/acme/helper.php' => self::HELPER,
            'vendor/acme/locked.php' => str_replace('grep', 'locked', self::HELPER),
        ]);
        chmod($project.'/vendor/acme/locked.php', 0o000);

        try {
            $occurrences = (new PatternExtractor($strategy()))->extract(
                [$project.'/lib'],
                ['vendor'],
                declarationPaths: [$project.'/vendor'],
            );
        } finally {
            chmod($project.'/vendor/acme/locked.php', 0o600);
        }

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
    }

    /**
     * A vendor file too large to tokenize in the memory left is skipped,
     * not read: its declarations are lost, the run goes on.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_vendor_file_over_the_memory_budget_is_skipped(\Closure $strategy): void
    {
        $huge = str_replace('grep', 'huge', self::HELPER)."\n".str_repeat("// padding\n", 100_000);
        $project = $this->makeProject([
            'lib/caller.php' => self::CALLER."\\App\\huge('/huge(/', 'x');\n",
            'vendor/acme/helper.php' => self::HELPER,
            'vendor/acme/huge.php' => $huge,
        ]);
        $extractor = new PatternExtractor($strategy());
        ini_set('memory_limit', (string) (memory_get_usage(true) + 32 * 1024 * 1024));

        $occurrences = $extractor->extract(
            [$project.'/lib'],
            ['vendor'],
            declarationPaths: [$project.'/vendor'],
        );

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
    }

    /**
     * An empty file, one holding only "<?php", and a declaration nobody
     * calls give no occurrence and no error.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_files_with_nothing_to_find_give_nothing(\Closure $strategy): void
    {
        $project = $this->makeProject(['lib/empty.php' => '', 'lib/open.php' => '<?php', 'lib/helper.php' => self::HELPER]);

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/lib'], [], null, 1, [$project.'/lib']);

        $this->assertSame([], $occurrences);
    }

    /**
     * The pattern functions given are read; the strategy no longer reads
     * the declarations of the files it extracts, which the scan before it
     * already did. On its own it still reads them.
     *
     * @param \Closure(): (ExtractorInterface&PatternFunctionAwareInterface) $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_strategy_reads_the_pattern_functions_it_is_given(\Closure $strategy): void
    {
        $project = $this->makeProject([
            'caller.php' => self::CALLER,
            'both.php' => self::HELPER."\n\\App\\grep('/both(/', 'x');\n",
        ]);
        $standalone = $strategy();

        $given = $standalone->withPatternFunctions(['App\grep#0']);

        $this->assertNotSame($standalone, $given);
        $this->assertSame(['/b02(/'], self::patterns($given->extract([$project.'/caller.php'])));
        $this->assertSame([], self::patterns($standalone->extract([$project.'/caller.php'])));
        $this->assertSame([], self::patterns($standalone->withPatternFunctions([])->extract([$project.'/both.php'])));
        $this->assertSame(['/both(/'], self::patterns($standalone->extract([$project.'/both.php'])));
    }

    /**
     * @return iterable<string, array{strategy: \Closure(): (ExtractorInterface&PatternFunctionAwareInterface)}>
     */
    public static function provideStrategies(): iterable
    {
        yield 'tokens' => ['strategy' => static fn (): TokenBasedExtractionStrategy => new TokenBasedExtractionStrategy()];
        yield 'php-parser' => ['strategy' => static fn (): PhpParserExtractionStrategy => new PhpParserExtractionStrategy()];
    }

    /**
     * @param array<PatternOccurrence> $occurrences
     *
     * @return list<string>
     */
    private static function patterns(array $occurrences): array
    {
        return array_values(array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
    }
}
