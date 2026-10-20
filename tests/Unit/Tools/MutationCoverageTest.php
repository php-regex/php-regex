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

namespace PHPRegex\Tests\Unit\Tools;

use PHPRegex\Tests\TestUtils\MutationCoverage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PHPUnit XML coverage the mutation runs read, reduced to what they
 * need: the files they mutate, and for each line the test methods that
 * reach it, not each data set of them.
 */
final class MutationCoverageTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../../Tools/reduce_mutation_coverage.php';

    private ?string $root = null;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir().'/mutation-coverage-'.bin2hex(random_bytes(6));
        mkdir($root.'/src/Parser', 0o777, true);
        mkdir($root.'/src/Cli', 0o777, true);
        mkdir($root.'/coverage/Parser', 0o777, true);
        mkdir($root.'/coverage/Cli', 0o777, true);
        $this->root = (string) realpath($root);
        file_put_contents($this->root.'/src/Parser/Lexer.php', str_repeat('x', 300));
        file_put_contents($this->root.'/src/Cli/Command.php', str_repeat('x', 200));
        file_put_contents($this->root.'/src/Parser/Token.php', str_repeat('x', 100));

        file_put_contents($this->root.'/coverage/index.xml', self::index($this->root.'/src'));
        file_put_contents($this->root.'/coverage/Parser/Lexer.php.xml', self::file('Lexer.php', 'Parser', [
            12 => ['LexerTest::test_a#first', 'LexerTest::test_a#second', 'LexerTest::test_b', 'ParserTest::test_c#0'],
            13 => ['LexerTest::test_a#third'],
        ]));
        file_put_contents($this->root.'/coverage/Parser/Token.php.xml', self::file('Token.php', 'Parser', [
            5 => ['TokenTest::test_d'],
        ]));
        file_put_contents($this->root.'/coverage/Cli/Command.php.xml', self::file('Command.php', 'Cli', [
            7 => ['CommandTest::test_e#a'],
        ]));
    }

    protected function tearDown(): void
    {
        if (null === $this->root) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $this->assertInstanceOf(\SplFileInfo::class, $file);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->root);
        $this->root = null;
    }

    #[Test]
    public function test_a_directory_keeps_the_files_under_it_in_the_index(): void
    {
        $kept = MutationCoverage::reduce($this->root().'/coverage', $this->root(), ['src/Parser/']);

        $this->assertSame(['src/Parser/Lexer.php', 'src/Parser/Token.php'], $kept);
        $this->assertSame(['Parser/Lexer.php.xml', 'Parser/Token.php.xml'], $this->indexedFiles());
    }

    #[Test]
    public function test_a_file_keeps_that_file_alone(): void
    {
        $kept = MutationCoverage::reduce($this->root().'/coverage', $this->root(), ['src/Cli/Command.php', 'src/Parser/Missing.php']);

        $this->assertSame(['src/Cli/Command.php'], $kept);
        $this->assertSame(['Cli/Command.php.xml'], $this->indexedFiles());
    }

    #[Test]
    public function test_a_directory_name_does_not_keep_a_sibling_sharing_its_prefix(): void
    {
        $kept = MutationCoverage::reduce($this->root().'/coverage', $this->root(), ['src/Pars']);

        $this->assertSame([], $kept);
    }

    /**
     * Nothing to mutate keeps no file; the project totals stay, so the run
     * still reads a coverage that executed lines.
     */
    #[Test]
    public function test_no_path_keeps_no_file_and_the_totals(): void
    {
        $this->assertSame([], MutationCoverage::reduce($this->root().'/coverage', $this->root(), []));
        $this->assertSame([], $this->indexedFiles());
        $this->assertStringContainsString('executed="40"', (string) file_get_contents($this->root().'/coverage/index.xml'));
    }

    #[Test]
    public function test_the_data_sets_of_a_test_collapse_into_its_method(): void
    {
        MutationCoverage::reduce($this->root().'/coverage', $this->root(), ['src/Parser/Lexer.php']);

        $this->assertSame(
            [
                12 => ['LexerTest::test_a', 'LexerTest::test_b', 'ParserTest::test_c'],
                13 => ['LexerTest::test_a'],
            ],
            self::coveredBy($this->root().'/coverage/Parser/Lexer.php.xml'),
        );
    }

    #[Test]
    public function test_a_file_left_out_of_the_index_is_not_rewritten(): void
    {
        $before = (string) file_get_contents($this->root().'/coverage/Cli/Command.php.xml');

        MutationCoverage::reduce($this->root().'/coverage', $this->root(), ['src/Parser/']);

        $this->assertSame($before, (string) file_get_contents($this->root().'/coverage/Cli/Command.php.xml'));
    }

    /**
     * The files are dealt largest first, each to the shard holding the
     * fewest bytes so far, so the shards get shares of the mutants close to
     * one another.
     */
    #[Test]
    public function test_a_shard_keeps_its_share_of_the_files_largest_first(): void
    {
        $this->assertSame(['src/Parser/Lexer.php'], MutationCoverage::reduce($this->root().'/coverage', $this->root(), ['src/'], 1, 2));
        $this->assertSame(['Parser/Lexer.php.xml'], $this->indexedFiles());
    }

    #[Test]
    public function test_a_file_goes_to_the_lightest_shard(): void
    {
        $this->assertSame(['src/Cli/Command.php', 'src/Parser/Token.php'], MutationCoverage::reduce($this->root().'/coverage', $this->root(), ['src/'], 2, 2));
    }

    #[Test]
    public function test_a_shard_outside_the_count_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MutationCoverage::reduce($this->root().'/coverage', $this->root(), ['src/'], 3, 2);
    }

    #[Test]
    public function test_a_coverage_of_another_project_is_refused(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        MutationCoverage::reduce($this->root().'/coverage', $this->root().'/src/Cli', ['src/Parser/']);
    }

    #[Test]
    public function test_the_script_prints_what_it_kept(): void
    {
        exec(\sprintf(
            'cd %s && %s %s coverage src/Parser/Token.php 2>&1',
            escapeshellarg($this->root()),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(self::SCRIPT),
        ), $output, $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertSame(['Kept the coverage of 1 file for the mutations: src/Parser/Token.php'], $output);
    }

    #[Test]
    public function test_the_script_writes_the_paths_of_its_shard(): void
    {
        exec(\sprintf(
            'cd %s && %s %s coverage --shard=2/2 --list=kept src/ 2>&1',
            escapeshellarg($this->root()),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(self::SCRIPT),
        ), $output, $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertSame("src/Cli/Command.php\nsrc/Parser/Token.php\n", file_get_contents($this->root().'/kept'));
    }

    #[Test]
    public function test_the_script_refuses_a_malformed_shard(): void
    {
        exec(\sprintf(
            'cd %s && %s %s coverage --shard=0/2 src/ 2>&1',
            escapeshellarg($this->root()),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(self::SCRIPT),
        ), $output, $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertSame(['The shard is written K/N, K from 1 to N: 0/2.'], $output);
    }

    #[Test]
    public function test_the_script_refuses_a_directory_without_an_index(): void
    {
        exec(\sprintf(
            'cd %s && %s %s src 2>&1',
            escapeshellarg($this->root()),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(self::SCRIPT),
        ), $output, $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertSame(['Cannot read src/index.xml.'], $output);
    }

    private function root(): string
    {
        \assert(null !== $this->root);

        return $this->root;
    }

    /**
     * @return list<string>
     */
    private function indexedFiles(): array
    {
        preg_match_all('~<file [^>]*href="([^"]+)"~', (string) file_get_contents($this->root().'/coverage/index.xml'), $matches);

        return $matches[1];
    }

    /**
     * @param non-empty-string $file
     *
     * @return array<int, list<string>>
     */
    private static function coveredBy(string $file): array
    {
        $document = new \DOMDocument();
        $document->load($file);
        $lines = [];
        foreach ($document->getElementsByTagName('line') as $line) {
            foreach ($line->getElementsByTagName('covered') as $covered) {
                $lines[(int) $line->getAttribute('nr')][] = $covered->getAttribute('by');
            }
        }

        return $lines;
    }

    private static function index(string $source): string
    {
        return <<<XML
            <?xml version="1.0"?>
            <phpunit xmlns="https://schema.phpunit.de/coverage/1.0">
              <project source="{$source}">
                <tests>
                  <test name="LexerTest::test_a#first" size="unknown" status="success" time="0.1"/>
                </tests>
                <directory name="/">
                  <totals>
                    <lines total="100" comments="0" code="100" executable="50" executed="40" percent="80.00"/>
                  </totals>
                  <directory name="Cli">
                    <file name="Command.php" href="Cli/Command.php.xml"/>
                  </directory>
                  <directory name="Parser">
                    <file name="Lexer.php" href="Parser/Lexer.php.xml"/>
                    <file name="Token.php" href="Parser/Token.php.xml"/>
                  </directory>
                </directory>
              </project>
            </phpunit>

            XML;
    }

    /**
     * @param array<int, list<string>> $lines
     */
    private static function file(string $name, string $path, array $lines): string
    {
        $coverage = '';
        foreach ($lines as $number => $tests) {
            $coverage .= \sprintf("      <line nr=\"%d\">\n", $number);
            foreach ($tests as $test) {
                $coverage .= \sprintf("        <covered by=\"%s\" count=\"1\"/>\n", htmlspecialchars($test, \ENT_XML1 | \ENT_QUOTES));
            }
            $coverage .= "      </line>\n";
        }

        return <<<XML
            <?xml version="1.0"?>
            <phpunit xmlns="https://schema.phpunit.de/coverage/1.0">
              <file name="{$name}" path="/{$path}">
                <totals>
                  <lines total="20" comments="0" code="20" executable="10" executed="8" percent="80.00"/>
                </totals>
                <coverage>
            {$coverage}    </coverage>
              </file>
            </phpunit>

            XML;
    }
}
