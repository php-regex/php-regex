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

namespace PHPRegex\Tests\Integration\Bridge\Laravel;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PHPRegex\Laravel\Extractor\ValidationRulePatternSource;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;

/**
 * The validation rule extractor reads a file in time proportional to it: a
 * run of backslashes left open at the end of a line (a string literal that
 * goes on past it) costs no more than its length, and the rules around it
 * are found. When the extractor cannot read a file, regex:lint says so for
 * that file and fails, instead of linting the rest as if it were empty.
 */
final class ValidationRuleExtractionFailureTest extends TestCase
{
    use TemporaryProject;

    private string $backtrackLimit = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->backtrackLimit = (string) \ini_get('pcre.backtrack_limit');
    }

    protected function tearDown(): void
    {
        LibraryPcre::useIniSetter(null);
        \ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        parent::tearDown();
    }

    /**
     * Oracle: the extractor's rule regex today gives up on this file with
     * "Backtrack limit exhausted" (PCRE2 10.49, JIT on and off), and loses
     * "/a/" with it.
     */
    #[Test]
    public function test_validation_extractor_finds_the_rules_around_an_open_backslash_run(): void
    {
        $project = $this->makeProject(['Rules.php' => self::source(30)]);

        $this->assertSame(['/a/', '/b+/'], self::extracted($project));
    }

    /**
     * A file the rule regex gives up on gives no rule, and the failure
     * names it with the reason: the backtrack limit in words. The library
     * raises the caller's limit to its floor through the setter, which puts
     * 0 in its place for the call.
     *
     * Oracle (PCRE2 10.49, JIT on and off): the rule regex on this file
     * fails with PREG_BACKTRACK_LIMIT_ERROR under a backtrack limit of 0.
     */
    #[Test]
    public function test_validation_extractor_names_the_backtrack_limit_it_gave_up_on(): void
    {
        $project = $this->makeProject(['app/Rules.php' => "<?php\n\$rules = ['code' => 'regex:/^[a-z]+$/'];\n"]);
        $source = new ValidationRulePatternSource();

        LibraryPcre::useIniSetter(static fn (string $key, string $value): string|false => \ini_set(
            $key,
            (string) LibraryPcre::BACKTRACK_LIMIT_FLOOR === $value ? '0' : $value,
        ));
        \ini_set('pcre.backtrack_limit', (string) (LibraryPcre::BACKTRACK_LIMIT_FLOOR - 1));

        try {
            $patterns = $source->extract(new PatternSourceContext(paths: [$project.'/app'], excludePaths: []));
        } finally {
            LibraryPcre::useIniSetter(null);
            \ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        }

        $this->assertSame([], $patterns);
        $failures = $source->failures();
        $this->assertCount(1, $failures);
        $this->assertStringEndsWith('/app/Rules.php', $failures[0]['file']);
        $this->assertSame('Validation rules not read: the rule regex gave up on this file (backtrack limit reached).', $failures[0]['message']);
    }

    /**
     * Any other PCRE error is named by its code. The recursion limit binds
     * only with the JIT off, and a pattern PHP compiled earlier with the
     * JIT keeps it: the extraction runs in a PHP process started with the
     * JIT off, where the setter puts 1 in place of the library's floor.
     *
     * Oracle (PCRE2 10.49, JIT off): the rule regex on this file fails with
     * PREG_RECURSION_LIMIT_ERROR (3) under a recursion limit of 1.
     */
    #[Test]
    public function test_validation_extractor_names_another_pcre_error_by_its_code(): void
    {
        $project = $this->makeProject(['app/Rules.php' => "<?php\n\$rules = ['code' => 'regex:/^[a-z]+$/'];\n"]);
        $script = 'require '.var_export(\dirname(__DIR__, 4).'/vendor/autoload.php', true).';'
            .'use PHPRegex\Parser\Internal\LibraryPcre;'
            .'LibraryPcre::useIniSetter(static fn (string $key, string $value): string|false => ini_set($key, "pcre.recursion_limit" === $key && (string) LibraryPcre::RECURSION_LIMIT_FLOOR === $value ? "1" : $value));'
            .'ini_set("pcre.recursion_limit", (string) (LibraryPcre::RECURSION_LIMIT_FLOOR - 1));'
            .'$source = new \PHPRegex\Laravel\Extractor\ValidationRulePatternSource();'
            .'$patterns = $source->extract(new \PHPRegex\Linter\Source\PatternSourceContext(paths: ['.var_export($project.'/app', true).'], excludePaths: []));'
            .'echo json_encode(["jit" => ini_get("pcre.jit"), "patterns" => count($patterns), "failures" => $source->failures()]);';

        $process = proc_open(
            [\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'auto_prepend_file=', '-d', 'pcre.jit=0', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors.$output);

        $result = json_decode($output, true);
        $this->assertIsArray($result, $errors.$output);
        $this->assertSame('0', $result['jit'] ?? null, 'The child process runs with the JIT off.');
        $this->assertSame(0, $result['patterns'] ?? null, $output);
        $this->assertIsArray($result['failures'] ?? null);
        $this->assertCount(1, $result['failures'], $output);
        $this->assertIsArray($result['failures'][0]);
        $this->assertSame(
            \sprintf('Validation rules not read: the rule regex gave up on this file (PCRE error %d).', \PREG_RECURSION_LIMIT_ERROR),
            $result['failures'][0]['message'] ?? null,
            $output,
        );
    }

    /**
     * Twice the backslashes, about twice the time: best of three runs per
     * size, the ratio under 3 and the larger size under a second.
     */
    #[Test]
    public function test_validation_extractor_reads_a_backslash_run_in_linear_time(): void
    {
        $small = $this->makeProject(['Rules.php' => self::source(20_000)]);
        $large = $this->makeProject(['Rules.php' => self::source(40_000)]);

        $smallTime = self::bestOfThree($small);
        $largeTime = self::bestOfThree($large);

        $this->assertLessThan(1.0, $largeTime);
        // The ratio is read only once both readings clear the noise of a
        // shared runner; a quadratic read still shows close to four times
        // the time at twice the size.
        if ($smallTime >= 0.05 && $largeTime >= 0.05) {
            $this->assertLessThan(3.5, $largeTime / $smallTime, \sprintf('%.4fs for 20,000, %.4fs for 40,000', $smallTime, $largeTime));
        }
    }

    /**
     * Every regex the library runs gives up: the caller's backtrack limit
     * sits just below the library's floor, and the setter the library
     * raises it with puts 0 in its place for the call, then gives the
     * caller's value back. The framework's own regexes keep the caller's
     * limit. The rule regex gives up on the file: the report holds a
     * problem for that file and the command fails.
     */
    #[Test]
    public function test_lint_reports_a_file_the_extractor_could_not_read(): void
    {
        $project = $this->makeProject(['app/Rules.php' => "<?php\n\$rules = ['code' => 'regex:/^[a-z]+$/'];\n"]);
        // The commands are built (their names checked by a regex) before
        // the limit drops.
        Artisan::all();

        LibraryPcre::useIniSetter(static fn (string $key, string $value): string|false => \ini_set(
            $key,
            (string) LibraryPcre::BACKTRACK_LIMIT_FLOOR === $value ? '0' : $value,
        ));
        \ini_set('pcre.backtrack_limit', (string) (LibraryPcre::BACKTRACK_LIMIT_FLOOR - 1));

        try {
            // Under a limit of 0 any regex that repeats gives up, a
            // possessive run included (PCRE2 10.49, JIT on and off).
            $this->assertFalse(LibraryPcre::matchAll('/regex:([^\']++)/', "'regex:/^[a-z]+$/'"));
            $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, LibraryPcre::lastError());

            $code = Artisan::call('regex:lint', [
                'paths' => [$project.'/app'],
                '--format' => 'checkstyle',
                '--no-routes' => true,
                '--jobs' => '1',
            ]);
            $output = Artisan::output();
        } finally {
            LibraryPcre::useIniSetter(null);
            \ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        }

        $this->assertSame(1, $code, $output);
        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($output), $output);
        $errors = 0;
        $sources = [];
        $severities = [];
        foreach ($document->getElementsByTagName('file') as $file) {
            if (str_ends_with($file->getAttribute('name'), 'Rules.php')) {
                $errors += $file->getElementsByTagName('error')->length;
                foreach ($file->getElementsByTagName('error') as $error) {
                    $sources[] = $error->getAttribute('source');
                    $severities[] = $error->getAttribute('severity');
                }
            }
        }
        $this->assertGreaterThan(0, $errors, $output);
        // The problem carries its own identifier, so it can be baselined
        // or filtered like any other; Checkstyle prefixes every one.
        $this->assertContains('php-regex.regex.lint.source.unreadable', $sources, $output);
        // The file fails the run: its problem is an error.
        $this->assertSame(['error'], array_values(array_unique($severities)), $output);
    }

    /**
     * In the JSON report the file the extractor could not read is one
     * result on its first line and column, with one error issue named
     * regex.lint.source.unreadable on that line.
     */
    #[Test]
    public function test_lint_json_places_an_unread_file_on_its_first_line(): void
    {
        $project = $this->makeProject(['app/Rules.php' => "<?php\n\$rules = ['code' => 'regex:/^[a-z]+$/'];\n"]);
        Artisan::all();

        LibraryPcre::useIniSetter(static fn (string $key, string $value): string|false => \ini_set(
            $key,
            (string) LibraryPcre::BACKTRACK_LIMIT_FLOOR === $value ? '0' : $value,
        ));
        \ini_set('pcre.backtrack_limit', (string) (LibraryPcre::BACKTRACK_LIMIT_FLOOR - 1));

        try {
            $code = Artisan::call('regex:lint', [
                'paths' => [$project.'/app'],
                '--format' => 'json',
                '--no-routes' => true,
                '--jobs' => '1',
            ]);
            $output = Artisan::output();
        } finally {
            LibraryPcre::useIniSetter(null);
            \ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        }

        $this->assertSame(1, $code, $output);
        $report = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);
        $this->assertIsArray($report['results'] ?? null);
        $unread = array_values(array_filter(
            $report['results'],
            static fn (mixed $result): bool => \is_array($result) && \is_string($result['file'] ?? null) && str_ends_with($result['file'], 'Rules.php'),
        ));
        $this->assertCount(1, $unread, $output);
        $result = $unread[0];
        $this->assertIsArray($result);

        $this->assertSame([1, 1], [$result['line'] ?? null, $result['column'] ?? null], $output);
        $this->assertIsArray($result['issues'] ?? null);
        $this->assertSame(
            [['error', 1, 'regex.lint.source.unreadable']],
            array_map(static fn (array $issue): array => [$issue['severity'] ?? null, $issue['line'] ?? null, $issue['issue_id'] ?? null], $result['issues']),
            $output,
        );
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('php-regex.cache.directory', null);
        $app['config']->set('php-regex.cache.store', null);
        $app['config']->set('php-regex.runtime_pcre_validation', false);
    }

    /**
     * A rule, a literal opened with "regex:" and a run of backslashes that
     * the line ends, then another rule.
     */
    private static function source(int $backslashes): string
    {
        return "<?php\n"
            ."\$first = ['a' => 'regex:/a/'];\n"
            ."\$open = 'regex:".str_repeat('\\', $backslashes)."\n';\n"
            ."\$second = ['b' => 'regex:/b+/'];\n";
    }

    /**
     * @return list<string>
     */
    private static function extracted(string $directory): array
    {
        $patterns = (new ValidationRulePatternSource())->extract(new PatternSourceContext(paths: [$directory], excludePaths: []));

        return array_values(array_filter(
            array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $patterns),
            static fn (string $pattern): bool => \in_array($pattern, ['/a/', '/b+/'], true),
        ));
    }

    private static function bestOfThree(string $directory): float
    {
        $best = \PHP_FLOAT_MAX;
        for ($run = 0; $run < 3; $run++) {
            $start = hrtime(true);
            $found = self::extracted($directory);
            $best = min($best, (hrtime(true) - $start) / 1e9);
            self::assertSame(['/a/', '/b+/'], $found, 'Both rules are found at every size.');
        }

        return $best;
    }
}
