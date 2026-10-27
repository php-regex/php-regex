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

use PhpParser\ParserFactory;
use PHPRegex\Linter\Exception\LintException;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternFunctionAwareInterface;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\LintProgress;
use PHPRegex\Linter\Parallel\ForkedWorkerPool;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPRegex\Tests\Support\RecordingLintProgress;
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

    private const TWO_PARAMETERS_FIRST = "<?php\nnamespace App;\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\nfunction pair(#[RegexPattern] string \$a, string \$b) {}\n";

    private const TWO_PARAMETERS_SECOND = "<?php\nnamespace App;\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\nfunction pair(string \$a, #[RegexPattern] string \$b) {}\n";

    private const TWO_PATTERNS_CALL = "<?php\n\\App\\pair('/a(/', '/b(/');\n";

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
     * The console's run: workers, and a progress. The steps come in order,
     * each finished before the next starts, each advanced by every file it
     * covers; each parallel step names the workers it started.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    #[RequiresFunction('pcntl_fork')]
    public function test_an_attribute_declared_in_another_file_is_read_with_workers_and_progress(\Closure $strategy): void
    {
        $project = $this->makeProject(['lib/a_helper.php' => self::HELPER, 'lib/b_caller.php' => self::CALLER]);
        $progress = new RecordingLintProgress();

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/lib'], [], $progress, 4);

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
        $this->assertSame([
            [LintProgress::STEP_FILES, null],
            [LintProgress::STEP_DECLARATIONS, 2],
            [LintProgress::STEP_PATTERNS, 2],
        ], $progress->steps());
        $this->assertSame([
            [LintProgress::STEP_FILES, null, 2],
            [LintProgress::STEP_DECLARATIONS, 2, 2],
            [LintProgress::STEP_PATTERNS, 2, 2],
        ], $progress->stepSums());
        $this->assertSame([LintProgress::STEP_FILES, LintProgress::STEP_DECLARATIONS, LintProgress::STEP_PATTERNS], $progress->finishedSteps());
        $this->assertSame([2], $progress->workerCountsIn(LintProgress::STEP_DECLARATIONS));
        $this->assertSame([2], $progress->workerCountsIn(LintProgress::STEP_PATTERNS));
    }

    /**
     * The declarations the workers read back are kept when the extraction
     * then runs in the parent: one linted file, two files read for
     * declarations.
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
        ]);
        $progress = new RecordingLintProgress();

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/lib/b_caller.php'], [], $progress, 2, [$project.'/lib']);

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
        $this->assertSame([
            [LintProgress::STEP_FILES, null, 1],
            [LintProgress::STEP_DECLARATIONS, 2, 2],
            [LintProgress::STEP_PATTERNS, 1, 1],
        ], $progress->stepSums());
        $this->assertSame([2], $progress->workerCountsIn(LintProgress::STEP_DECLARATIONS));
        $this->assertSame([], $progress->workerCountsIn(LintProgress::STEP_PATTERNS));
    }

    /**
     * The declarations two workers read back are kept when no worker can
     * be forked for the extraction, which then runs in the parent with
     * every file advanced.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    #[RequiresFunction('pcntl_fork')]
    public function test_the_declarations_read_on_workers_survive_an_extraction_that_falls_back_to_the_parent(\Closure $strategy): void
    {
        $project = $this->makeProject(['lib/a_helper.php' => self::HELPER, 'lib/b_caller.php' => self::CALLER]);
        LintFunctionOverrides::$pcntlForkFailAfter = 2;
        $progress = new RecordingLintProgress();

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/lib'], [], $progress, 2);

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
        $this->assertSame(2, LintFunctionOverrides::$pcntlForkRealCount, 'The scan ran on two workers.');
        $this->assertSame([2], $progress->workerCountsIn(LintProgress::STEP_DECLARATIONS));
        $this->assertSame([
            [LintProgress::STEP_FILES, null, 2],
            [LintProgress::STEP_DECLARATIONS, 2, 2],
            [LintProgress::STEP_PATTERNS, 2, 2],
        ], $progress->stepSums());
    }

    /**
     * When no child can be forked to read the declarations, the parent
     * reads them itself, with the same steps and every file advanced.
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
        $progress = new RecordingLintProgress();

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/lib'], [], $progress, 2);

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
        $this->assertSame([
            [LintProgress::STEP_FILES, null, 2],
            [LintProgress::STEP_DECLARATIONS, 2, 2],
            [LintProgress::STEP_PATTERNS, 2, 2],
        ], $progress->stepSums());
    }

    /**
     * A worker of the collection that fails is reported with the file it
     * was reading, once every worker is reaped and no payload file is
     * left; the declarations were read on workers before it.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_a_failing_collection_worker_is_reported_after_every_worker_is_cleaned_up(): void
    {
        $project = $this->makeProject([
            'lib/a_helper.php' => self::HELPER,
            'lib/b_caller.php' => self::CALLER,
        ]);
        $failing = $project.'/lib/b_caller.php';
        $extractor = new class($failing) implements ExtractorInterface, PatternFunctionAwareInterface {
            public function __construct(private readonly string $failing) {}

            public function withPatternFunctions(array $specs, array $plain = []): static
            {
                return $this;
            }

            public function customGlobalFunctions(): array
            {
                return [];
            }

            public function extract(array $files): array
            {
                if (\in_array($this->failing, $files, true)) {
                    throw new \RuntimeException('Boom');
                }

                return [];
            }
        };
        $progress = new RecordingLintProgress();
        $before = self::payloadFiles();
        $payloads = $this->makeProject();
        LintFunctionOverrides::$tempnamDirectory = $payloads;

        try {
            (new PatternExtractor($extractor))->extract([$project.'/lib'], [], $progress, 2);
            $this->fail('The failure of the child is reported.');
        } catch (LintException $e) {
            $this->assertSame('Parallel collection failed: RuntimeException: Boom (in '.$failing.')', $e->getMessage());
        }

        $this->assertSame([], array_values(array_diff(scandir($payloads) ?: [], ['.', '..'])));
        $this->assertSame([], array_values(array_diff(self::payloadFiles(), $before)));
        $this->assertSame([LintProgress::STEP_FILES, LintProgress::STEP_DECLARATIONS, LintProgress::STEP_PATTERNS], array_column($progress->steps(), 0));
    }

    /**
     * The progress starts before the declarations are read: a large vendor/
     * does not leave the progress unshown. The declarations step is
     * advanced by the time the extractor is given the declarations, and
     * the patterns step starts before the first file is extracted.
     */
    #[Test]
    public function test_the_progress_starts_before_the_declarations_are_read(): void
    {
        $project = $this->makeProject(['lib/caller.php' => self::CALLER]);
        $progress = new RecordingLintProgress();
        $extractor = new class($progress) implements ExtractorInterface, PatternFunctionAwareInterface {
            /**
             * @var list<array{string, ?int}>|null
             */
            public ?array $stepsWhenGiven = null;

            public ?int $declarationsAdvancedWhenGiven = null;

            /**
             * @var list<array{string, ?int}>|null
             */
            public ?array $stepsWhenFirstExtracting = null;

            public function __construct(private readonly RecordingLintProgress $progress) {}

            public function withPatternFunctions(array $specs, array $plain = []): static
            {
                $this->stepsWhenGiven = $this->progress->steps();
                $this->declarationsAdvancedWhenGiven = $this->progress->advancedIn(LintProgress::STEP_DECLARATIONS);

                return $this;
            }

            public function customGlobalFunctions(): array
            {
                return [];
            }

            public function extract(array $files): array
            {
                $this->stepsWhenFirstExtracting ??= $this->progress->steps();

                return [];
            }
        };

        (new PatternExtractor($extractor))->extract([$project.'/lib'], [], $progress);

        $this->assertSame([[LintProgress::STEP_FILES, null], [LintProgress::STEP_DECLARATIONS, 1]], $extractor->stepsWhenGiven);
        $this->assertSame(1, $extractor->declarationsAdvancedWhenGiven);
        $this->assertSame([[LintProgress::STEP_FILES, null], [LintProgress::STEP_DECLARATIONS, 1], [LintProgress::STEP_PATTERNS, 1]], $extractor->stepsWhenFirstExtracting);
        $this->assertSame([
            [LintProgress::STEP_FILES, null, 1],
            [LintProgress::STEP_DECLARATIONS, 1, 1],
            [LintProgress::STEP_PATTERNS, 1, 1],
        ], $progress->stepSums());
    }

    /**
     * In the parent alone, the steps are the same, each advanced by every
     * file it covers.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_an_attribute_declared_in_another_file_is_read_with_progress(\Closure $strategy): void
    {
        $project = $this->makeProject(['lib/a_helper.php' => self::HELPER, 'lib/b_caller.php' => self::CALLER]);
        $progress = new RecordingLintProgress();

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/lib'], [], $progress);

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
        $this->assertSame([
            [LintProgress::STEP_FILES, null, 2],
            [LintProgress::STEP_DECLARATIONS, 2, 2],
            [LintProgress::STEP_PATTERNS, 2, 2],
        ], $progress->stepSums());
        $this->assertSame([LintProgress::STEP_FILES, LintProgress::STEP_DECLARATIONS, LintProgress::STEP_PATTERNS], $progress->finishedSteps());
        $this->assertSame([], $progress->workerCounts());
    }

    /**
     * The declarations step covers the project files and the vendor files
     * in one total.
     */
    #[Test]
    public function test_the_declarations_step_counts_the_project_and_the_vendor_files(): void
    {
        $project = $this->makeProject([
            'lib/caller.php' => self::CALLER,
            'vendor/acmé/grep/helper.php' => self::HELPER,
            'vendor/acme/other/unrelated.php' => "<?php\n",
        ]);
        $progress = new RecordingLintProgress();

        $occurrences = (new PatternExtractor(new TokenBasedExtractionStrategy()))->extract([$project.'/lib'], ['vendor'], $progress, vendorPaths: [$project.'/vendor']);

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
        $this->assertSame([
            [LintProgress::STEP_FILES, null, 1],
            [LintProgress::STEP_DECLARATIONS, 3, 3],
            [LintProgress::STEP_PATTERNS, 1, 1],
        ], $progress->stepSums());
    }

    /**
     * The namespaced functions are read, when a global pattern function is
     * configured or declared, under a step of their own, between the
     * declarations and the patterns.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategiesConfiguringGrep')]
    public function test_the_namespaced_function_pass_is_a_step_of_its_own(\Closure $strategy): void
    {
        $project = $this->makeProject([
            'app/own.php' => "<?php\nnamespace App;\nfunction grep(string \$haystack) {}\n",
            'app/caller.php' => "<?php\nnamespace App;\ngrep('a(b');\n",
            'app/Other/elsewhere.php' => "<?php\nnamespace App\\Other;\ngrep('/c(/');\n",
        ]);
        $progress = new RecordingLintProgress();

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/app'], [], $progress);

        $this->assertSame(['/c(/'], self::patterns($occurrences));
        $this->assertSame([
            [LintProgress::STEP_FILES, null, 3],
            [LintProgress::STEP_DECLARATIONS, 3, 3],
            [LintProgress::STEP_FUNCTIONS, 3, 3],
            [LintProgress::STEP_PATTERNS, 3, 3],
        ], $progress->stepSums());
        $this->assertSame([LintProgress::STEP_FILES, LintProgress::STEP_DECLARATIONS, LintProgress::STEP_FUNCTIONS, LintProgress::STEP_PATTERNS], $progress->finishedSteps());
    }

    /**
     * On workers or in the parent alone, the steps and what each advanced
     * are the same, the namespaced-function pass and vendor/ included.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategiesConfiguringGrep')]
    #[RequiresFunction('pcntl_fork')]
    public function test_the_steps_are_the_same_in_the_parent_and_on_workers(\Closure $strategy): void
    {
        $project = $this->makeProject([
            'app/own.php' => "<?php\nnamespace App;\nfunction grep(string \$haystack) {}\n",
            'app/caller.php' => "<?php\nnamespace App;\ngrep('a(b');\n",
            'app/Other/elsewhere.php' => "<?php\nnamespace App\\Other;\ngrep('/c(/');\n",
            'app/Other/more.php' => "<?php\nnamespace App\\Other;\n\\App\\grep('x');\n",
            'vendor/acme/helper.php' => self::HELPER,
            'vendor/acme/other.php' => "<?php\n",
        ]);
        $serialProgress = new RecordingLintProgress();
        $parallelProgress = new RecordingLintProgress();

        $serial = (new PatternExtractor($strategy()))->extract([$project.'/app'], ['vendor'], $serialProgress, 1, vendorPaths: [$project.'/vendor']);
        $parallel = (new PatternExtractor($strategy()))->extract([$project.'/app'], ['vendor'], $parallelProgress, 4, vendorPaths: [$project.'/vendor']);

        $this->assertEquals($serial, $parallel);
        $this->assertSame($serialProgress->stepSums(), $parallelProgress->stepSums());
        $this->assertSame([
            [LintProgress::STEP_FILES, null, 4],
            [LintProgress::STEP_DECLARATIONS, 6, 6],
            [LintProgress::STEP_FUNCTIONS, 6, 6],
            [LintProgress::STEP_PATTERNS, 4, 4],
        ], $parallelProgress->stepSums());
        $this->assertSame($serialProgress->finishedSteps(), $parallelProgress->finishedSteps());
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
            declarationPaths: [$project],
            vendorPaths: [$project.'/vendor'],
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

                return array_values(array_map(static fn (string $file): PatternOccurrence => PatternOccurrence::found('/custom/', $file, 1, source: 'custom'), $files));
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
     * A configured path the run does not lint is read whatever the run
     * excludes: a helper declared under an excluded directory is known.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_an_excluded_directory_of_a_declaration_path_is_still_read(\Closure $strategy): void
    {
        $project = $this->makeProject(['lib/caller.php' => self::CALLER, 'lib/Fixtures/helper.php' => self::HELPER]);

        $occurrences = (new PatternExtractor($strategy()))->extract(
            [$project.'/lib/caller.php'],
            ['Fixtures'],
            declarationPaths: [$project.'/lib'],
        );

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
        $this->assertSame([$project.'/lib/caller.php'], array_values(array_unique(array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->file, $occurrences))));
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
            'lib/caller.php' => self::CALLER."\\App\\locked('/locked(/', 'x');\n",
            'vendor/acme/helper.php' => self::HELPER,
            'vendor/acme/locked.php' => str_replace('grep', 'locked', self::HELPER),
        ]);
        chmod($project.'/vendor/acme/locked.php', 0o000);
        $readable = is_readable($project.'/vendor/acme/locked.php');

        try {
            $occurrences = (new PatternExtractor($strategy()))->extract(
                [$project.'/lib'],
                ['vendor'],
                declarationPaths: [$project.'/vendor'],
            );
        } finally {
            chmod($project.'/vendor/acme/locked.php', 0o600);
        }

        $this->assertContains('/b02(/', self::patterns($occurrences));
        if ($readable) {
            // Running as root, which reads a file whatever its mode: the
            // file is no unreadable one there.
            $this->assertTrue(\function_exists('posix_geteuid') && 0 === posix_geteuid());

            return;
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
     * Below a linted path, the declarations are read as the lint reads the
     * files: what the run excludes there is not read, whether the
     * declaration path is the linted one, below it or above it.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideExcludedBelowLinted')]
    public function test_an_excluded_directory_below_a_linted_path_is_not_read(\Closure $strategy, string $linted, string $declared): void
    {
        $project = $this->makeProject(['lib/caller.php' => self::CALLER, 'lib/Fixtures/helper.php' => self::HELPER]);

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.$linted], ['Fixtures'], declarationPaths: [$project.$declared]);

        $this->assertSame([], self::patterns($occurrences));
    }

    /**
     * @return iterable<string, array{strategy: \Closure(): (ExtractorInterface&PatternFunctionAwareInterface), linted: string, declared: string}>
     */
    public static function provideExcludedBelowLinted(): iterable
    {
        foreach (self::provideStrategies() as $name => $row) {
            yield $name.', the linted path' => ['strategy' => $row['strategy'], 'linted' => '/lib', 'declared' => '/lib'];
            yield $name.', below the linted path' => ['strategy' => $row['strategy'], 'linted' => '', 'declared' => '/lib'];
            yield $name.', above the linted path' => ['strategy' => $row['strategy'], 'linted' => '/lib', 'declared' => ''];
        }
    }

    /**
     * A project declaration wins over a copy of the same function in
     * vendor/, whatever parameter the copy marks.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_project_declaration_wins_over_a_vendor_copy(\Closure $strategy): void
    {
        $project = $this->makeProject([
            'lib/helper.php' => self::TWO_PARAMETERS_FIRST,
            'lib/caller.php' => self::TWO_PATTERNS_CALL,
            'vendor/acme/copy/helper.php' => self::TWO_PARAMETERS_SECOND,
        ]);

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/lib'], ['vendor'], vendorPaths: [$project.'/vendor']);

        $this->assertSame(['/a(/'], self::patterns($occurrences));
    }

    /**
     * vendor/ below a linted path is excluded from the lint and read as
     * vendor/: its copy still loses to the project's declaration.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_vendor_copy_below_a_linted_path_stays_a_vendor_copy(\Closure $strategy): void
    {
        $project = $this->makeProject([
            'lib/helper.php' => self::TWO_PARAMETERS_FIRST,
            'lib/caller.php' => self::TWO_PATTERNS_CALL,
            'vendor/acme/copy/helper.php' => self::TWO_PARAMETERS_SECOND,
        ]);

        $occurrences = (new PatternExtractor($strategy()))->extract([$project], ['vendor'], declarationPaths: [$project], vendorPaths: [$project.'/vendor']);

        $this->assertSame(['/a(/'], self::patterns($occurrences));
    }

    /**
     * A call that leaves out one of the arguments the declarations mark is
     * read at the others.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_call_leaving_out_a_marked_argument_is_read_at_the_others(\Closure $strategy): void
    {
        $project = $this->makeProject([
            'one/helper.php' => self::TWO_PARAMETERS_FIRST,
            'two/helper.php' => self::TWO_PARAMETERS_SECOND,
            'lib/caller.php' => "<?php\n\\App\\pair('/a(/');\n",
        ]);

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/lib'], [], declarationPaths: [$project.'/one', $project.'/two']);

        $this->assertSame(['/a(/'], self::patterns($occurrences));
    }

    /**
     * Two project declarations of one function marking two parameters: both
     * are read, whatever the order of the paths or the number of jobs.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideOrdersAndJobs')]
    public function test_conflicting_declarations_do_not_depend_on_path_order(\Closure $strategy, bool $reversed, int $workers): void
    {
        $project = $this->makeProject([
            'one/helper.php' => self::TWO_PARAMETERS_FIRST,
            'two/helper.php' => self::TWO_PARAMETERS_SECOND,
            'lib/caller.php' => self::TWO_PATTERNS_CALL,
            'lib/other.php' => "<?php\n",
        ]);
        $paths = [$project.'/one', $project.'/two'];

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/lib'], [], null, $workers, $reversed ? array_reverse($paths) : $paths);

        $patterns = self::patterns($occurrences);
        sort($patterns);
        $this->assertSame(['/a(/', '/b(/'], $patterns);
    }

    /**
     * @return iterable<string, array{strategy: \Closure(): (ExtractorInterface&PatternFunctionAwareInterface), reversed: bool, workers: int}>
     */
    public static function provideOrdersAndJobs(): iterable
    {
        foreach (self::provideStrategies() as $name => $row) {
            foreach ([false, true] as $reversed) {
                foreach ([1, 4] as $workers) {
                    yield $name.($reversed ? ', reversed' : '').', '.$workers.' jobs' => ['strategy' => $row['strategy'], 'reversed' => $reversed, 'workers' => $workers];
                }
            }
        }
    }

    /**
     * A configured spec is kept as configured: a declaration found by the
     * scan does not replace it, ":keys" included.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideConfiguredStrategies')]
    public function test_a_configured_spec_is_not_replaced_by_a_scanned_declaration(\Closure $strategy): void
    {
        $project = $this->makeProject([
            'lib/caller.php' => "<?php\n\\Acme\\route(['/(k/' => 'v']);\n",
            'vendor/acme/h.php' => "<?php\nnamespace Acme;\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\nfunction route(#[RegexPattern] array \$map) {}\n",
        ]);

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/lib'], ['vendor'], vendorPaths: [$project.'/vendor']);

        $this->assertSame(['/(k/'], self::patterns($occurrences));
    }

    /**
     * @return iterable<string, array{strategy: \Closure(): (ExtractorInterface&PatternFunctionAwareInterface)}>
     */
    public static function provideConfiguredStrategies(): iterable
    {
        yield 'tokens' => ['strategy' => static fn (): TokenBasedExtractionStrategy => new TokenBasedExtractionStrategy(['Acme\route#0:keys'])];
        yield 'php-parser' => ['strategy' => static fn (): PhpParserExtractionStrategy => new PhpParserExtractionStrategy(['Acme\route#0:keys'])];
    }

    /**
     * A directory of a declaration path that cannot be read is passed over;
     * the run goes on.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_an_unreadable_directory_under_a_declaration_path_is_skipped(\Closure $strategy): void
    {
        $project = $this->makeProject([
            'lib/caller.php' => self::CALLER,
            'packages/helper.php' => self::HELPER,
            'packages/locked/inner.php' => "<?php\n",
        ]);
        chmod($project.'/packages/locked', 0o000);
        $readable = is_readable($project.'/packages/locked');

        try {
            $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/lib'], [], declarationPaths: [$project.'/packages']);
        } finally {
            chmod($project.'/packages/locked', 0o700);
        }

        if ($readable) {
            // Running as root, which reads a directory whatever its mode.
            $this->assertTrue(\function_exists('posix_geteuid') && 0 === posix_geteuid());

            return;
        }
        $this->assertSame(['/b02(/'], self::patterns($occurrences));
    }

    /**
     * A package Composer links into vendor/ from a path repository is read
     * through its symlink; a symlink loop ends the walk instead of running
     * forever.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_declaration_in_a_symlinked_vendor_package_is_read(\Closure $strategy): void
    {
        $project = $this->makeProject([
            'app/caller.php' => self::CALLER,
            'packages/lib/Helpers.php' => self::HELPER,
            'vendor/acme/.keep' => '',
        ]);
        $this->assertTrue(symlink('../../packages/lib', $project.'/vendor/acme/lib'));
        $this->assertTrue(symlink('..', $project.'/vendor/acme/up'));
        $this->assertTrue(symlink('self', $project.'/vendor/self'));

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/app'], ['vendor'], vendorPaths: [$project.'/vendor']);

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
    }

    /**
     * An unqualified call in a namespace calls that namespace's function
     * when it is declared, marked or not: a global function a library marks
     * does not capture it. Where the namespace declares none, the global one
     * is called and read.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_vendor_global_declaration_does_not_capture_a_namespaced_project_function(\Closure $strategy): void
    {
        $project = $this->makeProject([
            'app/own.php' => "<?php\nnamespace App;\nfunction grep(string \$haystack) {}\ngrep('a(b');\n",
            'app/Other/elsewhere.php' => "<?php\nnamespace App\\Other;\ngrep('/c(/');\n",
            'vendor/fix/global.php' => "<?php\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\nfunction grep(#[RegexPattern] string \$p) {}\n",
        ]);

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/app'], ['vendor'], vendorPaths: [$project.'/vendor']);

        $this->assertSame(['/c(/'], self::patterns($occurrences));
    }

    /**
     * A global function the configuration names captures no more than one
     * a library marks: an unqualified call in a namespace that declares its
     * own function of that name, in another file, calls that one.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategiesConfiguringGrep')]
    public function test_a_configured_global_function_does_not_capture_a_namespaced_project_function(\Closure $strategy): void
    {
        $project = $this->makeProject([
            'app/own.php' => "<?php\nnamespace App;\nfunction grep(string \$haystack) {}\n",
            'app/caller.php' => "<?php\nnamespace App;\ngrep('a(b');\n",
            'app/Other/elsewhere.php' => "<?php\nnamespace App\\Other;\ngrep('/c(/');\n",
        ]);

        $occurrences = (new PatternExtractor($strategy()))->extract([$project.'/app']);

        $this->assertSame(['/c(/'], self::patterns($occurrences));
    }

    /**
     * A file the PHP parser refuses is read with the tokenizer, which knows
     * the functions declared in the other files, and the plain namespaced
     * ones that keep a global declaration from capturing a call.
     */
    #[Test]
    #[DataProvider('provideWorkers')]
    #[RequiresFunction('pcntl_fork')]
    public function test_a_file_the_parser_refuses_reads_calls_to_functions_declared_elsewhere(int $workers): void
    {
        $project = $this->makeProject([
            'lib/a_helper.php' => self::HELPER,
            'lib/b_broken.php' => "<?php\nnamespace Own;\n\\App\\grep('/b02(/', 'x');\ngrep('/own(/');\nfunction (\n",
            'lib/c_own.php' => "<?php\nnamespace Own;\nfunction grep(string \$haystack) {}\n",
            'vendor/fix/global.php' => "<?php\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\nfunction grep(#[RegexPattern] string \$p) {}\n",
        ]);
        $this->assertTrue(class_exists(ParserFactory::class));

        $occurrences = (new PatternExtractor(new PhpParserExtractionStrategy()))->extract([$project.'/lib'], ['vendor'], null, $workers, vendorPaths: [$project.'/vendor']);

        $fallbacks = array_values(array_filter($occurrences, static fn (PatternOccurrence $occurrence): bool => null !== $occurrence->parserFallback));
        $this->assertCount(1, $fallbacks);
        $this->assertSame($project.'/lib/b_broken.php', $fallbacks[0]->file);
        $this->assertSame(['/b02(/'], self::patterns(array_filter($occurrences, static fn (PatternOccurrence $occurrence): bool => null === $occurrence->parserFallback)));
    }

    /**
     * @return iterable<string, array{workers: int}>
     */
    public static function provideWorkers(): iterable
    {
        yield 'serial' => ['workers' => 1];
        yield 'four jobs' => ['workers' => 4];
    }

    /**
     * @return iterable<string, array{strategy: \Closure(): (ExtractorInterface&PatternFunctionAwareInterface)}>
     */
    public static function provideStrategies(): iterable
    {
        yield 'tokens' => ['strategy' => static fn (): TokenBasedExtractionStrategy => new TokenBasedExtractionStrategy()];
        yield 'php-parser' => ['strategy' => static fn (): PhpParserExtractionStrategy => new PhpParserExtractionStrategy()];
    }

    public static function provideStrategiesConfiguringGrep(): iterable
    {
        yield 'tokens' => ['strategy' => static fn (): TokenBasedExtractionStrategy => new TokenBasedExtractionStrategy(['grep#0'])];
        yield 'php-parser' => ['strategy' => static fn (): PhpParserExtractionStrategy => new PhpParserExtractionStrategy(['grep#0'])];
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

    /**
     * The payload files a run of workers could leave in the temporary
     * directory.
     *
     * @return list<string>
     */
    private static function payloadFiles(): array
    {
        return array_values(array_filter(glob(sys_get_temp_dir().'/'.ForkedWorkerPool::PAYLOAD_PREFIX.'*') ?: [], is_file(...)));
    }
}
