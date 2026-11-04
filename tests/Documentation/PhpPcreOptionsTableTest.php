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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The step debugger traces PCRE2 with the options PHP passes, and PHP's
 * wrapper changes between versions: BSK in lookarounds from 8.1 to 8.4 and
 * gone in 8.5, the n modifier from 8.2, r from 8.4. The page reads those
 * facts from a generated table, one row per exact PHP version its engines
 * report, each row read from php-src's ext/pcre/php_pcre.c at that
 * version's tag and naming the commit. The lines the generator reads are
 * vendored as short excerpts, one file per version, so the test never
 * clones php-src: it runs the generator's parser over the excerpt and
 * compares with the tracked table.
 *
 * @phpstan-type Facts array{modifiers: array<string, list<string>>, extra: array<string, list<string>>, refused: list<string>, bsk: bool, baseRule: string, matchLimit: int, depthLimit: int, errors: array<string, int>}
 * @phpstan-type Row array{commit: string, tag: string, pcre2: string, base: int, baseRule: string, modifiers: array<string, int>, extra: array<string, int>, refused: list<string>, utfCheck: string, matchLimit: int, depthLimit: int, newline: string, bsr: string, errors: array<string, int>}
 */
#[Group('documentation')]
final class PhpPcreOptionsTableTest extends TestCase
{
    private const TABLE = PlaygroundDriver::ROOT.'/docs/assets/js/php-pcre-options.js';
    private const GENERATOR = PlaygroundDriver::ROOT.'/wasm/pcre2-trace/php-pcre-options/generate.php';
    private const FIXTURES = __DIR__.'/Fixtures/php-pcre';

    /**
     * The exact PHP versions the page's engines report (8.0 is out of
     * scope), with the PCRE2 release each bundles. A version here with no
     * excerpt or no row fails with the version named.
     */
    private const ENGINES = [
        '8.1.28' => '10.39',
        '8.2.11' => '10.40',
        '8.3.11' => '10.42',
        '8.4.1' => '10.44',
        '8.5.2' => '10.44',
    ];

    /**
     * PCRE2's option bits, stable from 10.3x to 10.49: a row holds numbers,
     * the worker never sees a name.
     */
    private const CASELESS = 0x8;
    private const MULTILINE = 0x400;
    private const NO_AUTO_CAPTURE = 0x2000;
    private const DOTALL = 0x20;
    private const EXTENDED = 0x80;
    private const ANCHORED = 0x80000000;
    private const DOLLAR_ENDONLY = 0x10;
    private const UNGREEDY = 0x40000;
    private const UTF = 0x80000;
    private const UCP = 0x20000;
    private const DUPNAMES = 0x40;
    private const EXTRA_ALLOW_LOOKAROUND_BSK = 0x40;
    private const EXTRA_CASELESS_RESTRICT = 0x80;

    /**
     * The modifiers every PHP 8.1 and later reads, with their compile bits;
     * n joins from 8.2 and r (an extra option) from 8.4.
     */
    private const COMMON_MODIFIERS = [
        'i' => self::CASELESS,
        'm' => self::MULTILINE,
        's' => self::DOTALL,
        'x' => self::EXTENDED,
        'A' => self::ANCHORED,
        'D' => self::DOLLAR_ENDONLY,
        'U' => self::UNGREEDY,
        'u' => self::UTF | self::UCP,
        'J' => self::DUPNAMES,
        'S' => 0,
        'X' => 0,
    ];

    /**
     * pcre_handle_exec_error, as PREG_* codes: the self-check compares PHP's
     * error codes, never PCRE2's.
     */
    private const ERRORS = ['matchLimit' => 2, 'depthLimit' => 3, 'badUtfOffset' => 5, 'badUtf' => 4, 'jitStackLimit' => 6, 'internal' => 1];

    /**
     * @return iterable<string, array{version: string}>
     */
    public static function provideVersions(): iterable
    {
        foreach (array_keys(self::ENGINES) as $version) {
            yield 'PHP '.$version => ['version' => $version];
        }
    }

    /**
     * What each version's wrapper does, read by hand from the excerpts and
     * checked against the oracle where the host can: n and r present or not,
     * BSK on or off.
     *
     * @return iterable<string, array{version: string, hasN: bool, hasR: bool, bsk: bool}>
     */
    public static function provideWrapperFacts(): iterable
    {
        yield 'PHP 8.1.28: no n, no r, BSK' => ['version' => '8.1.28', 'hasN' => false, 'hasR' => false, 'bsk' => true];
        yield 'PHP 8.2.11: n, no r, BSK' => ['version' => '8.2.11', 'hasN' => true, 'hasR' => false, 'bsk' => true];
        yield 'PHP 8.3.11: n, no r, BSK' => ['version' => '8.3.11', 'hasN' => true, 'hasR' => false, 'bsk' => true];
        yield 'PHP 8.4.1: n, r, BSK' => ['version' => '8.4.1', 'hasN' => true, 'hasR' => true, 'bsk' => true];
        yield 'PHP 8.5.2: n, r, no BSK' => ['version' => '8.5.2', 'hasN' => true, 'hasR' => true, 'bsk' => false];
    }

    #[Test]
    public function test_the_generator_writes_the_whole_table_or_reports_it_did_not(): void
    {
        require_once self::GENERATOR;
        $dir = sys_get_temp_dir().'/phpregex-pcre-options-'.bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            $this->assertTrue(phpregex_pcre_options_write($dir.'/table.js', "window.T = {};\n"));
            $this->assertSame("window.T = {};\n", file_get_contents($dir.'/table.js'));
            $this->assertSame(['table.js'], array_values(array_diff((array) scandir($dir), ['.', '..'])), 'No temporary file is left beside the table.');

            $this->assertFalse(@phpregex_pcre_options_write($dir.'/missing/table.js', "window.T = {};\n"), 'A table that cannot be written is reported, not announced.');
            $this->assertFileDoesNotExist($dir.'/missing/table.js');
        } finally {
            @unlink($dir.'/table.js');
            @rmdir($dir);
        }
    }

    #[Test]
    public function test_the_generator_refuses_to_write_a_table_without_rows(): void
    {
        $before = (string) file_get_contents(self::TABLE);

        try {
            exec(\sprintf('%s %s --from-file 2>&1', escapeshellarg(\PHP_BINARY), escapeshellarg(self::GENERATOR)), $output, $exit);
            $after = (string) file_get_contents(self::TABLE);
        } finally {
            // A generator without the guard overwrites the tracked table: it
            // is put back then, and only then, so a reader never sees it
            // rewritten when nothing touched it.
            if ((string) file_get_contents(self::TABLE) !== $before) {
                file_put_contents(self::TABLE, $before);
            }
        }

        $this->assertSame(2, $exit, 'No row to write is a usage error: '.implode("\n", $output));
        $this->assertSame($before, $after, 'The tracked table is left as it was.');
        $this->assertStringContainsString('no row', implode("\n", $output));
    }

    #[Test]
    public function test_the_table_has_a_row_per_offered_php_version(): void
    {
        $table = self::table();

        $this->assertSame(array_keys(self::ENGINES), array_keys($table), \sprintf('The table must hold exactly one row per PHP version the page offers (%s).', implode(', ', array_keys(self::ENGINES))));
    }

    #[Test]
    #[DataProvider('provideVersions')]
    public function test_every_offered_php_version_has_an_excerpt_of_its_php_src(string $version): void
    {
        $header = self::header($version);

        $this->assertSame('php-'.$version, $header['tag']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $header['commit']);
    }

    #[Test]
    #[DataProvider('provideVersions')]
    public function test_the_table_row_equals_what_the_generator_reads_from_php_src(string $version): void
    {
        $table = self::table();
        $this->assertArrayHasKey($version, $table, \sprintf('No row for PHP %s in %s.', $version, self::TABLE));

        $header = self::header($version);
        $facts = self::parse($version);
        $row = self::row($facts, $version, $header['commit']);

        $this->assertSame($row, $table[$version], \sprintf('The row for PHP %s differs from what the generator reads from its php_pcre.c excerpt: regenerate the table.', $version));
    }

    #[Test]
    #[DataProvider('provideWrapperFacts')]
    public function test_the_parser_reads_the_wrapper_facts_from_the_excerpt(string $version, bool $hasN, bool $hasR, bool $bsk): void
    {
        $facts = self::parse($version);

        $modifiers = self::COMMON_MODIFIERS;
        if ($hasN) {
            $modifiers['n'] = self::NO_AUTO_CAPTURE;
        }
        $letters = array_keys($modifiers);
        sort($letters);
        $present = array_keys($facts['modifiers']);
        sort($present);
        $this->assertSame($letters, $present, 'The compile-option modifiers of the switch.');
        $this->assertSame(['PCRE2_UTF', 'PCRE2_UCP'], $facts['modifiers']['u'], 'u sets UTF and UCP.');
        $this->assertSame([], $facts['modifiers']['S'], 'S is a no-op.');
        $this->assertSame([], $facts['modifiers']['X'], 'X is a no-op.');
        $this->assertSame(['PCRE2_CASELESS'], $facts['modifiers']['i']);
        $this->assertSame($hasR ? ['r' => ['PCRE2_EXTRA_CASELESS_RESTRICT']] : [], $facts['extra'], 'The extra-option modifiers of the switch.');
        $this->assertSame(['e'], $facts['refused']);
        $this->assertSame($bsk, $facts['bsk'], 'PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK set on the compile context.');
        $this->assertStringContainsString('37 == PCRE2_MINOR', $facts['baseRule']);
        $this->assertStringContainsString('HAVE_BUNDLED_PCRE', $facts['baseRule']);
        $this->assertSame(1000000, $facts['matchLimit'], 'pcre.backtrack_limit default, wired to pcre2_set_match_limit.');
        $this->assertSame(100000, $facts['depthLimit'], 'pcre.recursion_limit default, wired to pcre2_set_depth_limit.');
        $this->assertSame(self::ERRORS, $facts['errors']);
    }

    #[Test]
    #[DataProvider('provideWrapperFacts')]
    public function test_the_table_row_carries_the_wrapper_facts(string $version, bool $hasN, bool $hasR, bool $bsk): void
    {
        $table = self::table();
        $this->assertArrayHasKey($version, $table, \sprintf('No row for PHP %s in %s.', $version, self::TABLE));
        $row = $table[$version];

        $this->assertSame(['commit', 'tag', 'pcre2', 'base', 'baseRule', 'modifiers', 'extra', 'refused', 'utfCheck', 'matchLimit', 'depthLimit', 'newline', 'bsr', 'errors'], array_keys($row));
        $this->assertSame(self::header($version)['commit'], $row['commit'], 'The row names the php-src commit the excerpt was read at.');
        $this->assertSame('php-'.$version, $row['tag']);
        $this->assertSame(self::ENGINES[$version], $row['pcre2']);
        $this->assertSame(0, $row['base'], 'Every compile starts from coptions = 0 on a bundled PCRE2.');
        $this->assertStringContainsString('10.37', $row['baseRule']);

        $modifiers = self::COMMON_MODIFIERS;
        if ($hasN) {
            $modifiers['n'] = self::NO_AUTO_CAPTURE;
        }
        ksort($modifiers);
        $rowModifiers = $row['modifiers'];
        ksort($rowModifiers);
        $this->assertSame($modifiers, $rowModifiers);
        $this->assertSame(self::UTF | self::UCP, $row['modifiers']['u']);
        $this->assertSame(0xA0000, $row['modifiers']['u']);

        $extra = ['always' => $bsk ? self::EXTRA_ALLOW_LOOKAROUND_BSK : 0];
        if ($hasR) {
            $extra['r'] = self::EXTRA_CASELESS_RESTRICT;
        }
        $this->assertSame($extra, $row['extra']);
        $this->assertSame(['e'], $row['refused']);
        $this->assertSame('php', $row['utfCheck']);
        $this->assertSame(1000000, $row['matchLimit']);
        $this->assertSame(100000, $row['depthLimit']);
        $this->assertSame('lf', $row['newline']);
        $this->assertSame('unicode', $row['bsr']);
        $this->assertSame(self::ERRORS, $row['errors']);
    }

    /**
     * The wrapper facts of the host, where the host is one of the rows'
     * minors: the oracle behind the three facts the rows differ on.
     */
    #[Test]
    public function test_the_rows_agree_with_the_host_engine_on_the_facts_they_differ_on(): void
    {
        $minor = \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION;
        $rows = array_filter(self::table(), static fn (string $version): bool => str_starts_with($version, $minor.'.'), \ARRAY_FILTER_USE_KEY);
        if ([] === $rows) {
            // A host outside the offered minors has no row to agree with: the
            // rows are still checked against their excerpts above, and the
            // minors the page offers are the ones the table names.
            $this->assertNotContains($minor, array_map(static fn (string $version): string => substr($version, 0, (int) strrpos($version, '.')), array_keys(self::ENGINES)), 'PHP '.\PHP_VERSION.' is an offered minor with no row.');

            return;
        }
        $row = reset($rows);

        // Each witness compiles on some PHP versions only, so it goes through
        // the engine probe rather than a literal call the analyser would
        // judge against its own PHP.
        $this->assertSame(\array_key_exists('n', $row['modifiers']), EngineProbe::compiles('/(a)/n'), 'PHP '.\PHP_VERSION.' and the row disagree on the n modifier.');
        // php_pcre.c guards r and the BSK default with #ifdef on the PCRE2
        // macro, so a PHP built against an older external PCRE2 has neither:
        // the macros appeared in PCRE2 10.43 and 10.38.
        $pcre2 = explode(' ', \PCRE_VERSION)[0];
        $this->assertSame(\array_key_exists('r', $row['extra']) && version_compare($pcre2, '10.43', '>='), EngineProbe::compiles('/k/ir'), 'PHP '.\PHP_VERSION.' (PCRE2 '.$pcre2.') and the row disagree on the r modifier.');
        $this->assertSame(0 !== ($row['extra']['always'] & self::EXTRA_ALLOW_LOOKAROUND_BSK) && version_compare($pcre2, '10.38', '>='), EngineProbe::compiles('/(?<=a\K)bc/'), 'PHP '.\PHP_VERSION.' (PCRE2 '.$pcre2.') and the row disagree on \K in a lookbehind.');
        $this->assertSame(1, @preg_match('/\w+/u', 'café', $m));
        $this->assertSame(['café'], $m, 'u means UTF|UCP: \w reads é.');
    }

    /**
     * The tracked table, as the page's classic script declares it: the
     * object after "=" is JSON, so the worker and this test read the same
     * numbers.
     *
     * @return array<string, Row>
     */
    private static function table(): array
    {
        self::assertFileExists(self::TABLE);
        $js = (string) file_get_contents(self::TABLE);
        self::assertSame(1, preg_match('/^window\.PHPRegexPcreOptions\s*=\s*(\{.*\});\s*$/s', $js, $m), 'The table is one assignment of a JSON object to window.PHPRegexPcreOptions.');
        /** @var array<string, Row> $table */
        $table = json_decode($m[1], true, 512, \JSON_THROW_ON_ERROR);

        return $table;
    }

    /**
     * The excerpt's first line: the php-src tag and commit the lines below
     * were read at.
     *
     * @return array{tag: string, commit: string}
     */
    private static function header(string $version): array
    {
        $file = self::FIXTURES.'/'.$version.'.txt';
        self::assertFileExists($file, \sprintf('No php_pcre.c excerpt for PHP %s: write %s from the php-%s tag.', $version, $file, $version));
        $first = strtok((string) file_get_contents($file), "\n");
        self::assertIsString($first);
        self::assertSame(1, preg_match('/^# php-src tag (php-[0-9.]+), commit ([0-9a-f]{40}), ext\/pcre\/php_pcre\.c$/', $first, $m), \sprintf('The excerpt for PHP %s must start with "# php-src tag php-%s, commit <sha>, ext/pcre/php_pcre.c".', $version, $version));

        return ['tag' => $m[1], 'commit' => $m[2]];
    }

    /**
     * The generator's parser over the excerpt of one version.
     *
     * @return Facts
     */
    private static function parse(string $version): array
    {
        self::generator();
        /** @var Facts $facts */
        $facts = phpregex_pcre_options_parse((string) file_get_contents(self::FIXTURES.'/'.$version.'.txt'));

        return $facts;
    }

    /**
     * The generator's row for the facts of one version.
     *
     * @param Facts $facts
     *
     * @return Row
     */
    private static function row(array $facts, string $version, string $commit): array
    {
        self::generator();
        /** @var Row $row */
        $row = phpregex_pcre_options_row($facts, $version, $commit, self::ENGINES[$version]);

        return $row;
    }

    private static function generator(): void
    {
        self::assertFileExists(self::GENERATOR);
        require_once self::GENERATOR;
        self::assertTrue(\function_exists('phpregex_pcre_options_parse'), 'The generator declares phpregex_pcre_options_parse().');
        self::assertTrue(\function_exists('phpregex_pcre_options_row'), 'The generator declares phpregex_pcre_options_row().');
    }
}
