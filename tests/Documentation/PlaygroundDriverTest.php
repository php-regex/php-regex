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

use PHPRegex\Explain\TextExplainer;
use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Printer\JsonDumper;
use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPRegex\Tests\Unit\Parser\Printer\JsonDumperTest;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Analysis from PlaygroundDriver
 * @phpstan-import-type Match from PlaygroundDriver
 * @phpstan-import-type Matches from PlaygroundDriver
 * @phpstan-import-type Delimited from PlaygroundDriver
 * @phpstan-import-type Finding from PlaygroundDriver
 *
 * The playground's driver is the glue between the library's public API and
 * the page: it parses, explains, validates, dumps and matches, and converts
 * every byte offset the library counts into the UTF-16 index the browser
 * needs. Every row runs twice, on the tree's driver over src/ and on the
 * bundled driver over the bundle's own files: the second is what a visitor
 * runs, the first is what the next bundle will be.
 */
#[Group('documentation')]
final class PlaygroundDriverTest extends TestCase
{
    /**
     * @return iterable<string, array{driver: string, root: string}>
     */
    public static function provideDrivers(): iterable
    {
        yield from PlaygroundDriver::drivers();
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_a_valid_pattern_answers_every_key(string $driver, string $root): void
    {
        $payload = PlaygroundDriver::analyze($driver, $root, '/^(?<date>\d{4})-(\d{2})$/m', "2026-11\n1999-12\nnope");

        $this->assertSame(1, $payload['protocol']);
        foreach (['engine', 'library', 'target', 'input', 'delimited', 'unitOf', 'ast', 'explain', 'errors', 'engineError', 'matches', 'timing'] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }
        $this->assertSame(\PHP_VERSION, $payload['engine']['php']);
        $this->assertSame(explode(' ', \PCRE_VERSION)[0], $payload['engine']['pcre']);
        $this->assertIsBool($payload['engine']['jit']);
        $this->assertSame((int) ini_get('pcre.backtrack_limit'), $payload['engine']['backtrackLimit']);
        $this->assertSame(JsonDumper::VERSION, $payload['library']['jsonDumper']);
        $this->assertSame([], $payload['errors']);
        $this->assertNull($payload['engineError']);
        $this->assertSame(2, self::matchesOf($payload)['count']);
        $this->assertSame(['1' => 'date'], self::matchesOf($payload)['names']);
        $this->assertStringStartsWith('Regex matches', (string) $payload['explain']);
        $this->assertSame('regex', self::tree($payload)['type']);
        $this->assertSame('m', self::delimited($payload)['flags']);
        $this->assertSame('/', self::delimited($payload)['delimiter']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_offsets_are_body_bytes_end_exclusive(string $driver, string $root): void
    {
        $regex = '/a{2,3}?\d/i';
        $payload = PlaygroundDriver::analyze($driver, $root, $regex, 'aa5');

        $pattern = PlaygroundDriver::node(self::tree($payload), 'pattern');
        $this->assertSame(0, $pattern['start']);
        $this->assertSame(9, $pattern['end']);
        $this->assertSame(1, self::delimited($payload)['bodyStart']);
        $this->assertSame(10, self::delimited($payload)['bodyEnd']);
        $this->assertCount(\strlen($regex) + 1, $payload['unitOf']);
        $this->assertSame(range(0, \strlen($regex)), $payload['unitOf'], 'ASCII: one unit per byte.');
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_leading_whitespace_moves_the_body_start(string $driver, string $root): void
    {
        $padded = PlaygroundDriver::analyze($driver, $root, '  /ab/', 'ab');
        $this->assertSame(3, self::delimited($padded)['bodyStart']);
        $this->assertSame([], $padded['errors']);
        $this->assertSame(1, self::matchesOf($padded)['count']);

        $formFeed = PlaygroundDriver::analyze($driver, $root, "\f/ab/", 'ab');
        $this->assertSame(2, self::delimited($formFeed)['bodyStart'], 'PHP skips a form feed like a space.');

        $nul = PlaygroundDriver::analyze($driver, $root, "\0/ab/", 'ab');
        $this->assertNotSame([], $nul['errors'], 'A NUL byte is not whitespace: it is the delimiter PHP refuses.');
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_unit_table_counts_utf16(string $driver, string $root): void
    {
        $payload = PlaygroundDriver::analyze($driver, $root, '/é😀/u', 'é😀');

        // Bytes: "/" 1, "é" 2, the emoji 4, "/" 1, "u" 1 = 9 bytes, 10 entries.
        // Units: "/" is unit 0, "é" unit 1 (two bytes, one unit), the emoji
        // unit 2 (four bytes, two units), "/" unit 4, "u" unit 5, end 6.
        $this->assertSame([0, 1, 1, 2, 2, 2, 2, 4, 5, 6], $payload['unitOf']);
        $this->assertSame(1, self::matchesOf($payload)['count']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_match_offsets_come_back_in_both_counts(string $driver, string $root): void
    {
        $payload = PlaygroundDriver::analyze($driver, $root, '/\d+/', 'é 2026');

        $this->assertSame(1, self::matchesOf($payload)['count']);
        $this->assertSame(['0' => ['2026', 3, 2]], self::matchesOf($payload)['sets'][0], 'Byte 3 is unit 2: the é is two bytes, one unit.');
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_sets_are_sparse_and_capped(string $driver, string $root): void
    {
        $payload = PlaygroundDriver::analyze($driver, $root, '/a|(x)/', str_repeat('a', 600));

        $matches = self::matchesOf($payload);
        $this->assertSame(600, $matches['count']);
        $this->assertStringContainsString('"sets":[{"0":[', PlaygroundDriver::run($driver, $root, ['op' => 'match', 'regex' => '/a|(x)/', 'subject' => 'a'])['stdout'], 'A set is a JSON object, never a list: its keys are group numbers.');
        $this->assertSame(500, $matches['shown']);
        $this->assertSame(500, $matches['cap']);
        $this->assertCount(500, $matches['sets']);
        foreach ($matches['sets'] as $set) {
            $this->assertSame([0], array_keys($set), 'An unmatched group is absent, not null.');
        }
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_an_unclosed_group_reports_both_voices(string $driver, string $root): void
    {
        $regex = '/(?<d>\d{4})-(/';
        $payload = PlaygroundDriver::analyze($driver, $root, $regex, '2026-');

        $this->assertCount(1, $payload['errors']);
        $this->assertSame(13, $payload['errors'][0]['position']);
        $this->assertSame('SyntaxErrorException', $payload['errors'][0]['class']);
        $this->assertSame('regex.group.unclosed', $payload['errors'][0]['code']);
        $this->assertStringContainsString('missing closing parenthesis at offset 13', (string) $payload['engineError']);
        $this->assertStringStartsWith('Compilation failed', (string) $payload['engineError']);
        $this->assertNull($payload['matches']);
        $this->assertNull($payload['ast']);
        $this->assertNull($payload['explain']);

        $lint = PlaygroundDriver::lint($driver, $root, $regex);
        $this->assertNull($lint['lint']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_the_engine_is_asked_even_when_the_library_refuses(string $driver, string $root): void
    {
        // The page's own sequence: the analysis without the match, then the
        // match on its own, whatever the library said about the pattern.
        $regex = '/(?<d>\d{4})-(/';
        $analysis = PlaygroundDriver::analyze($driver, $root, $regex, '2026-', null, [], false);
        $this->assertNull($analysis['ast']);
        $this->assertNull($analysis['matches']);
        $this->assertNull($analysis['engineError'], 'The analysis alone does not ask the engine.');

        $matched = PlaygroundDriver::match($driver, $root, $regex, '2026-');
        $this->assertStringStartsWith('Compilation failed: missing closing parenthesis at offset 13', (string) $matched['engineError']);
        $this->assertNull($matched['matches']);

        // A pattern the library refuses for the engine it judges for, and
        // the engine compiles: the match still runs.
        $refused = PlaygroundDriver::analyze($driver, $root, '/\p{Script=Latin}/u', 'a', ['php' => '8.0.30', 'pcre' => '10.35'], [], false);
        $this->assertCount(1, $refused['errors']);
        $this->assertNull($refused['engineError']);
        $live = PlaygroundDriver::match($driver, $root, '/\p{Script=Latin}/u', 'a');
        if (self::hostKnowsScriptProperties()) {
            $this->assertNull($live['engineError']);
            $this->assertSame(1, self::matchesOf($live)['count']);
        } else {
            $this->assertStringStartsWith('Compilation failed: unknown property name', (string) $live['engineError'], 'A host before PCRE2 10.40 refuses the property itself.');
            $this->assertNull($live['matches']);
        }
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_an_unclosed_class_reports_position_four(string $driver, string $root): void
    {
        $payload = PlaygroundDriver::analyze($driver, $root, '/[a-z/u', 'x');

        $this->assertSame(4, $payload['errors'][0]['position']);
        $this->assertSame('LexerException', $payload['errors'][0]['class']);
        $this->assertStringContainsString('missing terminating ]', (string) $payload['engineError']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_a_validation_error_is_reported_with_its_offset_and_hint(string $driver, string $root): void
    {
        $payload = PlaygroundDriver::analyze($driver, $root, '/\p{Script=Latin}/u', 'a', ['php' => '8.0.30', 'pcre' => '10.35']);

        $this->assertSame('8.0.30', $payload['target']['php']);
        $this->assertSame('10.35', $payload['target']['pcre']);
        $this->assertCount(1, $payload['errors']);
        $this->assertSame('validate', $payload['errors'][0]['source']);
        $this->assertSame('regex.unicode.property_invalid', $payload['errors'][0]['code']);
        $this->assertSame(16, $payload['errors'][0]['position']);
        $this->assertSame('Write \p{Latin}.', $payload['errors'][0]['hint']);
        $this->assertNotNull($payload['ast'], 'The pattern parses: only the target refuses it, so the tree stays.');

        $here = PlaygroundDriver::analyze($driver, $root, '/\p{Script=Latin}/u', 'a');
        $this->assertSame(\PHP_VERSION, $here['target']['php'], 'No target given: the engine judges for itself.');
        if (self::hostKnowsScriptProperties()) {
            $this->assertSame([], $here['errors']);
        } else {
            $this->assertSame('regex.unicode.property_invalid', $here['errors'][0]['code'] ?? null, 'A host before PCRE2 10.40 refuses the property like the 8.0 target does.');
        }
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_nested_quantifier_lint_carries_its_span(string $driver, string $root): void
    {
        $payload = PlaygroundDriver::lint($driver, $root, '/(a+)+$/');

        $this->assertSame(1, $payload['protocol']);
        $this->assertNotNull($payload['lint']);
        $nested = array_values(array_filter($payload['lint'], static fn (array $finding): bool => 'regex.lint.quantifier.nested' === $finding['id']));
        $this->assertCount(1, $nested);
        $this->assertSame(0, $nested[0]['offset']);
        $this->assertSame(5, $nested[0]['end']);
        $this->assertSame('warning', $nested[0]['severity']);
        $this->assertArrayHasKey('lintMs', $payload['timing']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_backtrack_limit_is_an_engine_answer_not_a_hang(string $driver, string $root): void
    {
        $started = microtime(true);
        $payload = PlaygroundDriver::analyze($driver, $root, '/(a+)+$/', str_repeat('a', 30).'b', null, ['pcre.backtrack_limit=100000', 'pcre.jit=0']);

        $this->assertLessThan(5.0, microtime(true) - $started);
        $this->assertSame(0, self::matchesOf($payload)['count']);
        $this->assertSame('Backtrack limit exhausted', self::matchesOf($payload)['error']);
        $this->assertSame(100000, $payload['engine']['backtrackLimit']);
        $this->assertFalse($payload['engine']['jit']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_a_deeply_nested_pattern_still_answers(string $driver, string $root): void
    {
        // One JSON level per group and one per child list: past json_encode's
        // default depth of 512 well before the pattern cap.
        $regex = '/'.str_repeat('(a', 170).'a'.str_repeat(')', 170).'/';
        $payload = PlaygroundDriver::analyze($driver, $root, $regex, 'a');

        $this->assertArrayNotHasKey('fatal', $payload);
        $this->assertSame('regex', self::tree($payload)['type']);

        $deeper = PlaygroundDriver::analyze($driver, $root, '/'.str_repeat('(', 510).'a'.str_repeat(')', 510).'/', 'a', null, [], false);
        $this->assertArrayNotHasKey('fatal', $deeper);
        $this->assertSame('regex', self::tree($deeper)['type']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_the_analysis_does_not_wait_for_a_hostile_match(string $driver, string $root): void
    {
        // A thousand empty alternatives on ten thousand bytes: the engine
        // retries every alternative at every position, for seconds. The
        // page asks for the analysis alone first; it must answer fast.
        $regex = '/'.substr(implode('|', array_fill(0, 1217, '()')), 0, 1997).'/';
        $started = microtime(true);
        $payload = PlaygroundDriver::analyze($driver, $root, $regex, str_repeat('a', 10000), null, [], false);

        $this->assertLessThan(4.0, microtime(true) - $started);
        $this->assertNull($payload['matches'], 'No match was asked for.');
        $this->assertSame('regex', self::tree($payload)['type']);
        $this->assertLessThan(4000.0, $payload['timing']['analyzeMs']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_group_names_come_from_the_tree_even_when_duplicated(string $driver, string $root): void
    {
        $payload = PlaygroundDriver::analyze($driver, $root, '/(?J)(?<n>a)|(?<n>b)/', 'ab');

        $this->assertSame(['1' => 'n', '2' => 'n'], $payload['groupNames'], 'The engine\'s match array can name a group once; the tree names both.');
        $this->assertSame(['1' => 'n'], self::matchesOf($payload)['names']);
        $this->assertSame(2, self::matchesOf($payload)['count']);

        $reset = PlaygroundDriver::analyze($driver, $root, '/(?|(?<x>a)|(?<x>b))(?<y>c)?/', 'ac');
        $this->assertSame(['1' => 'x', '2' => 'y'], $reset['groupNames']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_describe_pairs_with_the_dumped_tree_for_every_node_kind(string $driver, string $root): void
    {
        // The corpus reaches into syntax the newest PCRE2 knows (the class
        // set operators of 10.45): judged for that release whatever the
        // local engine is, as JsonDumperTest itself parses it.
        $target = ['php' => \PHP_VERSION, 'pcre' => '10.49'];
        foreach (JsonDumperTest::provideCorpus() as $name => $row) {
            $regex = $row['pattern'];
            $analysis = PlaygroundDriver::analyze($driver, $root, $regex, '', $target, [], false);
            $parseErrors = array_filter($analysis['errors'], static fn (array $error): bool => 'parse' === $error['source']);
            $this->assertSame([], $parseErrors, $name.': the corpus parses (a validation verdict is another matter).');

            $spans = [];
            self::preorder(self::tree($analysis), $spans);
            $this->assertGreaterThan(1, \count($spans), $name);

            foreach ($spans as $index => [$start, $end]) {
                $described = PlaygroundDriver::describe($driver, $root, $regex, $index, $target);
                $this->assertSame($index, $described['index'], $name);
                $this->assertSame($start, $described['start'], sprintf('%s: node %d starts where the dump says.', $name, $index));
                $this->assertSame($end, $described['end'], sprintf('%s: node %d ends where the dump says.', $name, $index));
                $this->assertIsString($described['text'], $name);
                $this->assertNotSame('', $described['text'], $name);
                $this->assertStringNotContainsString("\n", (string) $described['text'], 'One line per node.');
            }

            $past = PlaygroundDriver::describe($driver, $root, $regex, \count($spans), $target);
            $this->assertNull($past['text'], $name.': past the tree there is nothing to describe.');
        }
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_a_pattern_over_the_cap_is_refused_cleanly(string $driver, string $root): void
    {
        $long = '/'.str_repeat('a', 1999).'/';
        $this->assertSame(2001, \strlen($long));
        $payload = PlaygroundDriver::analyze($driver, $root, $long, 'a');

        $this->assertArrayNotHasKey('fatal', $payload);
        $this->assertCount(1, $payload['errors']);
        $this->assertSame('ResourceLimitException', $payload['errors'][0]['class']);
        $this->assertSame(1999, $payload['errors'][0]['position'], 'The library puts the caret where the cap was crossed, counted from the body.');
        $this->assertSame(2001, $payload['input']['regexBytes']);
        $this->assertNull($payload['matches'], 'The engine is not asked to compile what the library refused.');
        $this->assertCount(2001, $payload['unitOf'], 'The table stops at the cap: the caret never lands past it.');

        $huge = PlaygroundDriver::analyze($driver, $root, '/'.str_repeat('a', 50000).'/', 'a');
        $this->assertNull($huge['matches']);
        $this->assertCount(2001, $huge['unitOf']);

        $exact = PlaygroundDriver::analyze($driver, $root, '/'.str_repeat('a', 1998).'/', 'a');
        $this->assertSame([], $exact['errors'], '2000 bytes is the cap, inclusive.');

        $subject = str_repeat('é', 5000).'x';
        $this->assertSame(10001, \strlen($subject));
        $cut = PlaygroundDriver::analyze($driver, $root, '/x/', $subject);
        $this->assertTrue($cut['input']['truncated']);
        $this->assertSame(10000, $cut['input']['subjectBytes']);
        $this->assertSame(0, self::matchesOf($cut)['count'], 'The x past the cap is gone.');
        $this->assertTrue(mb_check_encoding((string) json_encode($cut), 'UTF-8'));
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_the_envelope_is_the_only_output(string $driver, string $root): void
    {
        foreach ([
            ['op' => 'analyze', 'regex' => '/^(?<date>\d{4})-(\d{2})$/m', 'subject' => "2026-11\n"],
            ['op' => 'analyze', 'regex' => '/(?<d>\d{4})-(/', 'subject' => ''],
            ['op' => 'analyze', 'regex' => '', 'subject' => ''],
            ['op' => 'analyze', 'regex' => '/', 'subject' => ''],
            ['op' => 'lint', 'regex' => '/(a+)+$/'],
            ['op' => 'describe', 'regex' => '/a/', 'index' => 0],
            ['op' => 'describe', 'regex' => '/(/', 'index' => 0],
            ['op' => 'match', 'regex' => '/a/', 'subject' => 'a'],
            ['op' => 'nonsense'],
        ] as $input) {
            $run = PlaygroundDriver::run($driver, $root, $input);
            $label = json_encode($input, \JSON_THROW_ON_ERROR);
            $this->assertSame(0, $run['exit'], $label.': '.$run['stderr']);
            $this->assertSame('', $run['stderr'], $label);
            $this->assertMatchesRegularExpression('/\A\x1e\{.*\}\x1e\z/s', $run['stdout'], $label);
            $payload = PlaygroundDriver::payload($run);
            $this->assertSame(1, $payload['protocol']);
        }
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_the_match_op_needs_no_library(string $driver, string $root): void
    {
        $payload = PlaygroundDriver::match($driver, $root.'/does-not-exist', '/(?<n>\d)/', 'a1b2');

        $this->assertArrayNotHasKey('fatal', $payload);
        $this->assertSame(2, self::matchesOf($payload)['count']);
        $this->assertSame(['1' => 'n'], self::matchesOf($payload)['names']);
        $this->assertSame(\PHP_VERSION, $payload['engine']['php']);
        $this->assertNull($payload['engineError']);

        $broken = PlaygroundDriver::match($driver, $root.'/does-not-exist', '/(/', '');
        $this->assertNull($broken['matches']);
        $this->assertStringStartsWith('Compilation failed', (string) $broken['engineError']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_an_unknown_op_and_a_missing_input_are_answered_not_fatal(string $driver, string $root): void
    {
        $payload = PlaygroundDriver::ask($driver, $root, ['op' => 'nonsense']);
        $this->assertSame('Unknown op "nonsense".', $payload['fatal']);

        $empty = PlaygroundDriver::analyze($driver, $root, '', '');
        $this->assertArrayNotHasKey('fatal', $empty);
        $this->assertCount(1, $empty['errors']);
        $this->assertNull($empty['delimited']);
        $this->assertNull($empty['matches']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_the_bootstrap_is_idempotent(string $driver, string $root): void
    {
        $run = PlaygroundDriver::run($driver, $root, ['op' => 'analyze', 'regex' => '/a/', 'subject' => 'a'], [], 2);

        $this->assertSame(0, $run['exit'], $run['stderr']);
        $this->assertSame('', $run['stderr']);
        $payload = PlaygroundDriver::payload($run);
        $this->assertIsArray($payload['matches']);
        $this->assertSame(1, $payload['matches']['count']);
    }

    #[Test]
    public function test_the_driver_names_only_the_public_surface(): void
    {
        $source = (string) file_get_contents(PlaygroundDriver::TREE_DRIVER);

        $allowed = [
            Regex::class,
            DelimitedPattern::class,
            AlternationNode::class,
            CharClassNode::class,
            NodeInterface::class,
            JsonDumper::class,
            RegexException::class,
            SemanticErrorException::class,
            TextExplainer::class,
        ];
        preg_match_all('/^use ([\w\\\\]+);$/m', $source, $uses);
        $this->assertNotSame([], $uses[1], 'The driver imports what it uses.');
        foreach ($uses[1] as $use) {
            $this->assertContains($use, $allowed, $use.' is not on the public surface the playground may read.');
        }
        preg_match_all('/PHPRegex\\\\(?:\w+\\\\)*\w+/', $source, $names);
        foreach (array_unique($names[0]) as $name) {
            $this->assertContains($name, $allowed, $name.' is named outside the import list.');
        }

        $this->assertStringNotContainsString('{{', $source, 'Liquid would read a double brace.');
        $this->assertStringNotContainsString('{%', $source, 'Liquid would read a tag.');
        $this->assertStringNotContainsString('readonly ', $source, 'The driver runs on the PHP 8.0 engine for the match op: 8.0 syntax only.');
        $this->assertStringNotContainsString('enum ', $source, 'The driver runs on the PHP 8.0 engine for the match op: 8.0 syntax only.');

        exec(sprintf('%s -n -l %s 2>&1', escapeshellarg(\PHP_BINARY), escapeshellarg(PlaygroundDriver::TREE_DRIVER)), $lines, $exit);
        $this->assertSame(0, $exit, implode("\n", $lines));
    }

    /**
     * \p{Script=Latin} arrived in PCRE2 10.40; a host bundling an older
     * release refuses it, and so does the library judging for that host.
     */
    private static function hostKnowsScriptProperties(): bool
    {
        return version_compare(explode(' ', \PCRE_VERSION)[0], '10.40', '>=');
    }

    /**
     * @param Analysis|Match $payload
     *
     * @return Matches
     */
    private static function matchesOf(array $payload): array
    {
        self::assertNotNull($payload['matches'], 'The engine refused the pattern.');

        return $payload['matches'];
    }

    /**
     * @param Analysis $payload
     *
     * @return Delimited
     */
    private static function delimited(array $payload): array
    {
        self::assertNotNull($payload['delimited'], 'The pattern has no delimiters.');

        return $payload['delimited'];
    }

    /**
     * @param Analysis $payload
     *
     * @return array<string, mixed>
     */
    private static function tree(array $payload): array
    {
        self::assertNotNull($payload['ast'], 'The pattern does not parse.');

        return $payload['ast'];
    }

    /**
     * The nodes of a dumped document in pre-order: every object with a
     * "type" key, children in the order the document lists them.
     *
     * @param array<mixed>          $node
     * @param list<array{int, int}> $spans
     */
    private static function preorder(array $node, array &$spans): void
    {
        self::assertIsInt($node['start']);
        self::assertIsInt($node['end']);
        $spans[] = [$node['start'], $node['end']];
        foreach ($node as $value) {
            if (\is_array($value) && isset($value['type'])) {
                self::preorder($value, $spans);
            } elseif (\is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    if (\is_array($item) && isset($item['type'])) {
                        self::preorder($item, $spans);
                    }
                }
            }
        }
    }
}
