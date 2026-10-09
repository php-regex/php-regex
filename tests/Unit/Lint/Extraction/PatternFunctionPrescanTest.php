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
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternFunctionAwareInterface;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\LintException;
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
     * A child of the scan that fails is reported once every child is waited
     * for and every payload file removed.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_a_failing_scan_worker_is_reported_after_every_worker_is_cleaned_up(): void
    {
        $project = $this->makeProject([
            'lib/a_helper.php' => self::HELPER,
            'lib/b_caller.php' => self::CALLER,
            'payload/a' => serialize(['ok' => false, 'error' => ['message' => 'Boom', 'class' => \RuntimeException::class]]),
            'payload/b' => serialize(['ok' => true, 'result' => []]),
        ]);
        LintFunctionOverrides::queueTempnam($project.'/payload/a');
        LintFunctionOverrides::queueTempnam($project.'/payload/b');
        LintFunctionOverrides::queuePcntlForkResult(111);
        LintFunctionOverrides::queuePcntlForkResult(222);
        LintFunctionOverrides::$pcntlWaitpidResult = 0;

        try {
            (new PatternExtractor(new TokenBasedExtractionStrategy()))->extract([$project.'/lib'], [], null, 2);
            $this->fail('The failure of the child is reported.');
        } catch (LintException $e) {
            $this->assertSame('Parallel collection failed: RuntimeException: Boom', $e->getMessage());
        }

        $this->assertFileDoesNotExist($project.'/payload/a');
        $this->assertFileDoesNotExist($project.'/payload/b');
    }

    /**
     * The progress starts, at zero of the files to lint, before the
     * declarations are read: a large vendor/ does not leave the progress
     * unshown.
     */
    #[Test]
    public function test_the_progress_starts_before_the_declarations_are_read(): void
    {
        $project = $this->makeProject(['lib/caller.php' => self::CALLER]);
        $calls = new \ArrayObject();
        $extractor = new class($calls) implements ExtractorInterface, PatternFunctionAwareInterface {
            /**
             * @var array<int, array{int, int}>|null
             */
            public ?array $callsWhenGiven = null;

            /**
             * @param \ArrayObject<int, array{int, int}> $calls
             */
            public function __construct(private readonly \ArrayObject $calls) {}

            public function withPatternFunctions(array $specs, array $plain = []): static
            {
                $this->callsWhenGiven = $this->calls->getArrayCopy();

                return $this;
            }

            public function extract(array $files): array
            {
                return [];
            }
        };

        (new PatternExtractor($extractor))->extract([$project.'/lib'], [], static function (int $current, int $total) use ($calls): void {
            $calls[] = [$current, $total];
        });

        $this->assertSame([[0, 1]], $extractor->callsWhenGiven);
        $this->assertSame([[0, 1], [1, 1]], $calls->getArrayCopy());
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
     * What the run excludes stays out of the lint, not out of the
     * declarations: a helper declared under an excluded directory is known.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_an_excluded_directory_of_a_declaration_path_is_still_read(\Closure $strategy): void
    {
        $project = $this->makeProject(['lib/caller.php' => self::CALLER, 'lib/Fixtures/helper.php' => self::HELPER]);

        $occurrences = (new PatternExtractor($strategy()))->extract(
            [$project.'/lib'],
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
     * Below a linted path, what the run excludes is still read for
     * declarations, though the declaration path is not walked twice.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_an_excluded_directory_is_read_below_a_linted_path(\Closure $strategy): void
    {
        $project = $this->makeProject(['lib/caller.php' => self::CALLER, 'lib/Fixtures/helper.php' => self::HELPER]);

        $occurrences = (new PatternExtractor($strategy()))->extract([$project], ['Fixtures'], declarationPaths: [$project.'/lib']);

        $this->assertSame(['/b02(/'], self::patterns($occurrences));
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
