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

namespace PHPRegex\Tests\Unit\Benchmark;

use PHPRegex\Redos\RedosProof;
use PHPRegex\Tests\Benchmark\Support\BenchCase;
use PHPRegex\Tests\Benchmark\Support\BenchCases;
use PHPRegex\Tests\Benchmark\Support\GrowthSeries;
use PHPRegex\Tests\Benchmark\Support\Workloads;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The inputs the benchmarks measure: every pathological case is a pattern
 * PCRE compiles (or, marked invalid, refuses), keyed so that adding a case
 * never renames another, and every growth series stays under the default
 * guards so that it measures work rather than the exception path.
 */
final class BenchmarkDataTest extends TestCase
{
    private const GROUPS = ['lexer', 'parser', 'validate', 'redos', 'automata', 'optimizer'];

    private ?string $tempDir = null;

    protected function setUp(): void
    {
        $this->tempDir = null;
    }

    protected function tearDown(): void
    {
        if (null !== $this->tempDir) {
            self::removeDirectory($this->tempDir);
            $this->tempDir = null;
        }
    }

    #[Test]
    #[DataProvider('provideDataCases')]
    public function test_data_case_compiles_under_pcre(BenchCase $case): void
    {
        error_clear_last();
        $result = @preg_match($case->pattern, '');
        // A pattern that does not compile leaves preg_last_error_msg() at
        // "Internal error": the compiler's own message is the last warning.
        $message = \sprintf('%s: %s (PCRE: %s)', $case->key, $case->pattern, error_get_last()['message'] ?? preg_last_error_msg());

        if ($case->invalid) {
            $this->assertFalse($result, 'A case marked invalid must fail to compile. '.$message);
            // The benchmark times the library's error path: the library must
            // refuse the pattern too, or it would time the success path
            // under the name of an error.
            $this->assertFalse(Regex::create(['cache' => null])->validate($case->pattern)->isValid, 'A case marked invalid must be refused by the library. '.$message);

            return;
        }

        $this->assertNotFalse($result, 'A data case must compile. '.$message);
    }

    #[Test]
    #[DataProvider('provideDataCases')]
    public function test_data_case_has_origin_and_note(BenchCase $case): void
    {
        $this->assertNotSame('', trim($case->origin), $case->key.': empty origin');
        $this->assertNotSame('', trim($case->note), $case->key.': empty note');
        $this->assertMatchesRegularExpression('/^(synthetic|issue #\d+|corpus:.+)$/', $case->origin, $case->key.': unknown origin form');
    }

    #[Test]
    #[DataProvider('provideValidDataCases')]
    public function test_data_case_parses(BenchCase $case): void
    {
        // A case the parser rejects would measure the error path by accident.
        $ast = Regex::create(['cache' => null])->parse($case->pattern);

        $this->assertSame(ltrim($case->pattern)[0], $ast->delimiter, $case->key);
    }

    #[Test]
    public function test_data_folder_is_not_empty_and_spans_groups(): void
    {
        $cases = BenchCases::all();

        $this->assertNotSame([], $cases);

        foreach (self::GROUPS as $group) {
            $this->assertNotSame([], BenchCases::forGroup($group), \sprintf('No data case in group "%s".', $group));
        }
    }

    #[Test]
    public function test_data_covers_edge_inputs(): void
    {
        $regex = Regex::create(['cache' => null]);

        $unicode = $nonUtf8Byte = $otherDelimiter = $invalid = false;

        foreach (BenchCases::all() as $case) {
            $delimiter = ltrim($case->pattern)[0] ?? '';
            $otherDelimiter = $otherDelimiter || '/' !== $delimiter;

            if ($case->invalid) {
                $invalid = true;

                continue;
            }

            $flags = $regex->parse($case->pattern)->flags;
            $unicode = $unicode || str_contains($flags, 'u');
            // The oracle reads a subject that is not UTF-8 as an error under /u.
            $nonUtf8Byte = $nonUtf8Byte || (!str_contains($flags, 'u') && false === @preg_match('//u', $case->pattern));
        }

        $this->assertTrue($unicode, 'No valid data case uses the u modifier.');
        $this->assertTrue($nonUtf8Byte, 'No valid non-u data case holds a byte that is not UTF-8.');
        $this->assertTrue($otherDelimiter, 'No data case uses a delimiter other than "/".');
        $this->assertTrue($invalid, 'No data case is marked invalid.');
    }

    #[Test]
    public function test_loader_maps_a_file_to_its_case(): void
    {
        $dir = $this->makeTempDir();
        $this->writeCase($dir, 'parser', 'plain', ['pattern' => '/a+b/', 'origin' => 'synthetic', 'note' => 'plain case']);
        $this->writeCase($dir, 'validate', 'broken', ['pattern' => '/(/', 'origin' => 'issue #12', 'note' => 'unclosed group', 'invalid' => true]);

        $cases = BenchCases::all($dir);

        $this->assertSame(['parser/plain', 'validate/broken'], array_keys($cases));

        $plain = $cases['parser/plain'];
        $this->assertSame('parser/plain', $plain->key);
        $this->assertSame('parser', $plain->group);
        $this->assertSame('plain', $plain->slug);
        $this->assertSame('/a+b/', $plain->pattern);
        $this->assertSame('synthetic', $plain->origin);
        $this->assertSame('plain case', $plain->note);
        $this->assertFalse($plain->invalid, 'invalid defaults to false');

        $broken = $cases['validate/broken'];
        $this->assertSame('validate/broken', $broken->key);
        $this->assertSame('validate', $broken->group);
        $this->assertSame('broken', $broken->slug);
        $this->assertTrue($broken->invalid);
    }

    #[Test]
    public function test_loader_for_group_keeps_only_that_group(): void
    {
        $dir = $this->makeTempDir();
        $this->writeCase($dir, 'parser', 'one', ['pattern' => '/a/', 'origin' => 'synthetic', 'note' => 'one']);
        $this->writeCase($dir, 'parser', 'two', ['pattern' => '/b/', 'origin' => 'synthetic', 'note' => 'two']);
        $this->writeCase($dir, 'redos', 'three', ['pattern' => '/c/', 'origin' => 'synthetic', 'note' => 'three', 'expect' => 'proven']);

        $this->assertSame(['parser/one', 'parser/two'], array_keys(BenchCases::forGroup('parser', $dir)));
        $this->assertSame(['redos/three'], array_keys(BenchCases::forGroup('redos', $dir)));
        $this->assertSame([], BenchCases::forGroup('lexer', $dir));
    }

    #[Test]
    public function test_loader_keys_are_stable_when_a_case_is_added(): void
    {
        $dir = $this->makeTempDir();
        $this->writeCase($dir, 'parser', 'm-middle', ['pattern' => '/m/', 'origin' => 'synthetic', 'note' => 'middle']);
        $this->writeCase($dir, 'parser', 'z-last', ['pattern' => '#z#i', 'origin' => 'corpus:acme/lib', 'note' => 'last']);

        $before = BenchCases::all($dir);
        $this->assertSame(['parser/m-middle', 'parser/z-last'], array_keys($before));

        $this->writeCase($dir, 'parser', 'a-first', ['pattern' => '/a/', 'origin' => 'synthetic', 'note' => 'first']);

        $after = BenchCases::all($dir);

        $this->assertSame(['parser/a-first', 'parser/m-middle', 'parser/z-last'], array_keys($after));
        foreach ($before as $key => $case) {
            $this->assertArrayHasKey($key, $after);
            $this->assertEquals($case, $after[$key], $key.' changed when another case was added');
        }
    }

    /**
     * @param array<string, mixed>|string $content the array the file returns, or raw PHP source after "<?php"
     */
    #[Test]
    #[DataProvider('provideMalformedCases')]
    public function test_loader_rejects_a_malformed_case(array|string $content): void
    {
        $dir = $this->makeTempDir();
        $this->writeCase($dir, 'parser', 'bad', $content);

        $this->expectException(\UnexpectedValueException::class);

        BenchCases::all($dir);
    }

    /**
     * @return iterable<string, array{content: array<string, mixed>|string}>
     */
    public static function provideMalformedCases(): iterable
    {
        yield 'missing note' => ['content' => ['pattern' => '/a/', 'origin' => 'synthetic']];
        yield 'missing origin' => ['content' => ['pattern' => '/a/', 'note' => 'n']];
        yield 'missing pattern' => ['content' => ['origin' => 'synthetic', 'note' => 'n']];
        yield 'non-string pattern' => ['content' => ['pattern' => 42, 'origin' => 'synthetic', 'note' => 'n']];
        yield 'non-string origin' => ['content' => ['pattern' => '/a/', 'origin' => null, 'note' => 'n']];
        yield 'non-string note' => ['content' => ['pattern' => '/a/', 'origin' => 'synthetic', 'note' => ['n']]];
        yield 'unknown key' => ['content' => ['pattern' => '/a/', 'origin' => 'synthetic', 'note' => 'n', 'flags' => 'i']];
        yield 'file returns no array' => ['content' => 'return 1;'];
        yield 'non-bool invalid' => ['content' => ['pattern' => '/a/', 'origin' => 'synthetic', 'note' => 'n', 'invalid' => 'yes']];
        yield 'expect outside redos and automata' => ['content' => ['pattern' => '/a/', 'origin' => 'synthetic', 'note' => 'n', 'expect' => 'proven']];
    }

    /**
     * A corpus file that is not a JSON list stops the realistic set, rather
     * than leaving it empty: a realistic subject would then time nothing.
     * Two patterns sharing a key are refused too; that row is not here, as
     * it needs two patterns whose xxh128 hashes share their first 48 bits.
     */
    #[Test]
    #[DataProvider('provideMalformedCorpora')]
    public function test_realistic_rejects_a_corpus_that_is_no_json_list(string $json): void
    {
        $file = $this->makeTempDir().'/corpus.json';
        file_put_contents($file, $json);

        $this->expectException(\UnexpectedValueException::class);

        BenchCases::realistic($file);
    }

    /**
     * @return iterable<string, array{json: string}>
     */
    public static function provideMalformedCorpora(): iterable
    {
        yield 'invalid JSON' => ['json' => '[{"pattern": "/a/"'];
        yield 'a scalar' => ['json' => '42'];
        yield 'a string' => ['json' => '"/a/"'];
    }

    #[Test]
    public function test_realistic_keeps_a_repeated_pattern_once(): void
    {
        $file = $this->makeTempDir().'/corpus.json';
        file_put_contents($file, '[{"pattern": "/a/"}, {"pattern": "/a/"}, {"pattern": "/b/"}, {"note": "no pattern"}]');

        $expected = [BenchCases::keyFor('/a/') => '/a/', BenchCases::keyFor('/b/') => '/b/'];
        ksort($expected, \SORT_STRING);

        $this->assertSame($expected, BenchCases::realistic($file));
    }

    #[Test]
    #[DataProvider('provideOutcomeCases')]
    public function test_case_outcome_matches_its_declaration(BenchCase $case): void
    {
        // A code change that flips the path (proven to budget_exceeded,
        // complete to guard) would otherwise be timed as the same work.
        $this->assertSame($case->expect, Workloads::outcome($case->group, $case->pattern), $case->key.': '.$case->pattern);
    }

    /**
     * @return iterable<string, array{case: BenchCase}>
     */
    public static function provideOutcomeCases(): iterable
    {
        foreach ([...BenchCases::forGroup('redos'), ...BenchCases::forGroup('automata')] as $key => $case) {
            yield $key => ['case' => $case];
        }
    }

    /**
     * @param array<string, mixed> $content
     */
    #[Test]
    #[DataProvider('provideBadExpects')]
    public function test_loader_rejects_a_bad_expect(string $group, string $allowed, array $content): void
    {
        $dir = $this->makeTempDir();

        // The same group with an outcome it allows loads: the rejection below
        // comes from the value of "expect", not from the key being unknown.
        $this->writeCase($dir.'/good', $group, 'good', ['pattern' => '/a/', 'origin' => 'synthetic', 'note' => 'n', 'expect' => $allowed]);
        $this->assertSame($allowed, BenchCases::get($group.'/good', $dir.'/good')->expect);

        $this->writeCase($dir.'/bad', $group, 'bad', $content);

        $this->expectException(\UnexpectedValueException::class);

        BenchCases::all($dir.'/bad');
    }

    /**
     * @return iterable<string, array{group: string, allowed: string, content: array<string, mixed>}>
     */
    public static function provideBadExpects(): iterable
    {
        $base = ['pattern' => '/a/', 'origin' => 'synthetic', 'note' => 'n'];

        yield 'redos with the automata vocabulary' => ['group' => 'redos', 'allowed' => 'proven', 'content' => [...$base, 'expect' => 'complete']];
        yield 'automata with the redos vocabulary' => ['group' => 'automata', 'allowed' => 'complete', 'content' => [...$base, 'expect' => 'proven']];
        yield 'automata missing expect' => ['group' => 'automata', 'allowed' => 'complete', 'content' => $base];
        yield 'redos missing expect' => ['group' => 'redos', 'allowed' => 'proven', 'content' => $base];
        yield 'redos with a non-string expect' => ['group' => 'redos', 'allowed' => 'proven', 'content' => [...$base, 'expect' => 42]];
        yield 'automata with a non-string expect' => ['group' => 'automata', 'allowed' => 'complete', 'content' => [...$base, 'expect' => 42]];
        // not_analyzed is an outcome Workloads::outcome() can report, never
        // one a case may declare: it would be timing the error path.
        yield 'redos declaring not_analyzed' => ['group' => 'redos', 'allowed' => 'proven', 'content' => [...$base, 'expect' => 'not_analyzed']];
        yield 'redos with the enum case name instead of its value' => ['group' => 'redos', 'allowed' => 'proven', 'content' => [...$base, 'expect' => 'Proven']];
    }

    #[Test]
    public function test_loader_reads_expect(): void
    {
        $dir = $this->makeTempDir();
        $declared = [
            'automata/complete' => 'complete',
            'automata/guard' => 'guard',
            'redos/budget-exceeded' => 'budget_exceeded',
            'redos/heuristic' => 'heuristic',
            'redos/proven' => 'proven',
        ];
        foreach ($declared as $key => $expect) {
            [$group, $slug] = explode('/', $key);
            $this->writeCase($dir, $group, $slug, ['pattern' => '/a/', 'origin' => 'synthetic', 'note' => 'n', 'expect' => $expect]);
        }
        $this->writeCase($dir, 'lexer', 'plain', ['pattern' => '/a/', 'origin' => 'synthetic', 'note' => 'n']);

        $cases = BenchCases::all($dir);

        $expectedKeys = [...array_keys($declared), 'lexer/plain'];
        sort($expectedKeys, \SORT_STRING);
        $this->assertSame($expectedKeys, array_keys($cases), 'keys come out sorted');
        foreach ($declared as $key => $expect) {
            $this->assertSame($expect, $cases[$key]->expect, $key);
        }
        $this->assertNull($cases['lexer/plain']->expect, 'expect defaults to null outside redos and automata');
    }

    #[Test]
    #[DataProvider('provideOutcomes')]
    public function test_outcome_reports_each_path(string $group, string $pattern, string $expected): void
    {
        $this->assertSame($expected, Workloads::outcome($group, $pattern));
    }

    /**
     * @return iterable<string, array{group: string, pattern: string, expected: string}>
     */
    public static function provideOutcomes(): iterable
    {
        yield 'redos proven' => ['group' => 'redos', 'pattern' => '/(a+)+$/', 'expected' => 'proven'];
        // A backreference sits outside the backtracking model.
        yield 'redos heuristic' => ['group' => 'redos', 'pattern' => '/(a+)+\1$/', 'expected' => 'heuristic'];
        yield 'redos budget exceeded' => ['group' => 'redos', 'pattern' => '/^(?:(?:a{16}){16}){16}$/', 'expected' => 'budget_exceeded'];
        // PCRE refuses "/(/": the analysis does not run.
        yield 'redos not analyzed' => ['group' => 'redos', 'pattern' => '/(/', 'expected' => 'not_analyzed'];
        yield 'automata complete' => ['group' => 'automata', 'pattern' => '/a/', 'expected' => 'complete'];
        yield 'automata guard' => ['group' => 'automata', 'pattern' => '/a{4500}/', 'expected' => 'guard'];
    }

    #[Test]
    public function test_key_for_is_stable_and_well_formed(): void
    {
        $key = BenchCases::keyFor('/(a+)+$/');

        $this->assertMatchesRegularExpression('/^p[0-9a-f]{12}$/', $key);
        $this->assertSame($key, BenchCases::keyFor('/(a+)+$/'));
        $this->assertNotSame($key, BenchCases::keyFor('/(a+)+$/u'));
        $this->assertMatchesRegularExpression('/^p[0-9a-f]{12}$/', BenchCases::keyFor(''));
    }

    #[Test]
    public function test_realistic_set_is_keyed_by_content(): void
    {
        $realistic = BenchCases::realistic();

        $this->assertNotSame([], $realistic);
        foreach ($realistic as $key => $pattern) {
            $this->assertSame(BenchCases::keyFor($pattern), $key);
        }

        $expected = array_values(array_unique(self::corpusPatterns()));
        $actual = array_values($realistic);
        sort($expected);
        sort($actual);

        $this->assertCount(\count($expected), $realistic, 'duplicates collapse, nothing else is lost');
        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function test_realistic_keys_survive_an_inserted_entry(): void
    {
        $dir = $this->makeTempDir();
        $entries = json_decode((string) file_get_contents(BenchCases::CORPUS), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($entries);
        file_put_contents($dir.'/before.json', json_encode($entries, \JSON_THROW_ON_ERROR));
        array_unshift($entries, ['pattern' => '/inserted-first-[0-9]+/', 'issues' => []]);
        file_put_contents($dir.'/after.json', json_encode($entries, \JSON_THROW_ON_ERROR));

        $before = BenchCases::realistic($dir.'/before.json');
        $after = BenchCases::realistic($dir.'/after.json');

        $this->assertCount(\count($before) + 1, $after);
        foreach ($before as $key => $pattern) {
            $this->assertSame($pattern, $after[$key] ?? null, \sprintf('key %s moved when an entry was inserted', $key));
        }
        $this->assertSame('/inserted-first-[0-9]+/', $after[BenchCases::keyFor('/inserted-first-[0-9]+/')] ?? null);
    }

    #[Test]
    #[DataProvider('provideSeriesGroups')]
    public function test_growth_series_ladders_ascend(string $group): void
    {
        $series = GrowthSeries::forGroup($group);
        $families = GrowthSeries::families($group);

        $this->assertNotSame([], $families);

        $ladders = [];
        foreach ($series as $key => $point) {
            $this->assertSame($point['family'].'@'.$point['n'], $key);
            $ladders[$point['family']][] = $point['n'];
        }

        $this->assertSame($families, array_keys($ladders), 'families() lists the families forGroup() holds, in order');

        foreach ($ladders as $family => $ladder) {
            $this->assertGreaterThanOrEqual(3, \count($ladder), $family.' has fewer than three points');
            for ($i = 1, $n = \count($ladder); $i < $n; $i++) {
                $this->assertGreaterThan($ladder[$i - 1], $ladder[$i], $family.' ladder does not strictly ascend');
            }
        }
    }

    #[Test]
    #[DataProvider('provideSeriesGroups')]
    public function test_growth_series_compiles_under_pcre(string $group): void
    {
        foreach (GrowthSeries::forGroup($group) as $key => $point) {
            $this->assertNotFalse(@preg_match($point['pattern'], ''), \sprintf('%s/%s: %s (PCRE: %s)', $group, $key, $point['pattern'], preg_last_error_msg()));
        }
    }

    /**
     * @return iterable<string, array{group: string}>
     */
    public static function provideSeriesGroups(): iterable
    {
        yield 'automata' => ['group' => 'automata'];
        yield 'redos' => ['group' => 'redos'];
    }

    #[Test]
    #[DataProvider('provideRedosLargestPoints')]
    public function test_growth_series_stay_under_default_guards_for_redos(string $pattern): void
    {
        $analysis = Workloads::redos($pattern);

        $this->assertNotSame(RedosProof::BudgetExceeded, $analysis->proof, $pattern.' exceeds the default budget');
        // NotAnalyzed is what an invalid pattern or a library exception
        // gives: the series would be timing the error path.
        $this->assertNotSame(RedosProof::NotAnalyzed, $analysis->proof, $pattern.' was not analysed');
    }

    #[Test]
    #[DataProvider('provideRedosGrowthPoints')]
    public function test_every_redos_growth_point_is_proven(string $pattern): void
    {
        // The cold growth benchmark warms every point up expecting a proof:
        // a point that slips to heuristic or onto the budget would break the
        // variant instead of timing the prover.
        $this->assertSame('proven', Workloads::outcome('redos', $pattern), $pattern);
    }

    #[Test]
    #[DataProvider('provideAutomataGrowthPoints')]
    public function test_every_automata_growth_point_completes(string $pattern): void
    {
        // The cold growth benchmark warms every point up expecting a built
        // DFA: a point that stops on a guard would time the guard instead.
        $this->assertSame('complete', Workloads::outcome('automata', $pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRedosGrowthPoints(): iterable
    {
        return self::allPoints('redos');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAutomataGrowthPoints(): iterable
    {
        return self::allPoints('automata');
    }

    /**
     * @return iterable<string, array{case: BenchCase}>
     */
    public static function provideDataCases(): iterable
    {
        foreach (BenchCases::all() as $key => $case) {
            yield $key => ['case' => $case];
        }
    }

    /**
     * @return iterable<string, array{case: BenchCase}>
     */
    public static function provideValidDataCases(): iterable
    {
        foreach (BenchCases::all() as $key => $case) {
            if (!$case->invalid) {
                yield $key => ['case' => $case];
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRedosLargestPoints(): iterable
    {
        $largest = [];
        foreach (GrowthSeries::forGroup('redos') as $point) {
            if (!isset($largest[$point['family']]) || $point['n'] > $largest[$point['family']]['n']) {
                $largest[$point['family']] = $point;
            }
        }

        foreach ($largest as $family => $point) {
            yield $family.'@'.$point['n'] => ['pattern' => $point['pattern']];
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    private static function allPoints(string $group): iterable
    {
        foreach (GrowthSeries::forGroup($group) as $key => $point) {
            yield $key => ['pattern' => $point['pattern']];
        }
    }

    /**
     * @return list<string>
     */
    private static function corpusPatterns(): array
    {
        $corpus = json_decode((string) file_get_contents(BenchCases::CORPUS), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($corpus);

        $patterns = [];
        foreach ($corpus as $entry) {
            if (\is_array($entry) && \is_string($entry['pattern'] ?? null)) {
                $patterns[] = $entry['pattern'];
            }
        }

        return $patterns;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/regex-parser-bench-data-'.uniqid('', true);
        mkdir($dir, 0o777, true);
        $this->tempDir = $dir;

        return $dir;
    }

    /**
     * @param array<string, mixed>|string $content
     */
    private function writeCase(string $dir, string $group, string $slug, array|string $content): void
    {
        if (!is_dir($dir.'/'.$group)) {
            mkdir($dir.'/'.$group, 0o777, true);
        }

        $body = \is_array($content) ? 'return '.var_export($content, true).';' : $content;
        file_put_contents($dir.'/'.$group.'/'.$slug.'.php', "<?php\n\n".$body."\n");
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            Assert::assertInstanceOf(\SplFileInfo::class, $item);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
