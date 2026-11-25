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
use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The call a pattern runs in, as the cost of its search depends on it:
 * preg_match_all(), preg_replace(), preg_filter(), preg_replace_callback(),
 * preg_replace_callback_array() and preg_split() go on after each match, and
 * after an empty one PHP retries the same offset; preg_match() and
 * preg_grep() stop at the first match, and a literal limit stops the others
 * after that many. Each occurrence names the function whose loop it runs in,
 * or none, through both extraction strategies.
 *
 * The oracle runs that function: "(?:a+\d)?" matches the empty string at
 * each position of a run of "a", so the number of matches the call makes
 * grows with the run only where the call goes on.
 */
final class EveryMatchCallTest extends TestCase
{
    use TemporaryProject;

    private const PROBE = '/(?:a+\d)?/';

    /**
     * @param list<string> $presets
     */
    #[Test]
    #[DataProvider('provideCalls')]
    public function test_extraction_names_the_function_whose_loop_the_call_runs(string $call, string $function, ?int $limit, ?string $everyMatch, array $presets = []): void
    {
        $short = self::matchesMade($function, $limit, str_repeat('a', 1000));
        $long = self::matchesMade($function, $limit, str_repeat('a', 2000));
        $this->assertSame(null !== $everyMatch, $long > $short, \sprintf('Oracle: %s makes %d then %d matches.', $function, $short, $long));
        if (null !== $everyMatch) {
            $this->assertSame($function, $everyMatch);
        }

        $registry = [] === $presets ? PatternFunctionRegistry::native() : PatternFunctionRegistry::create($presets);
        $code = "<?php\n\$s = 'x';\n".$call."\n";
        $this->assertSame([['/p/', $everyMatch]], $this->readWith(new TokenBasedExtractionStrategy([], $registry), $code), 'The token strategy, on '.$call);
        if (class_exists(ParserFactory::class)) {
            $this->assertSame([['/p/', $everyMatch]], $this->readWith(new PhpParserExtractionStrategy([], $registry), $code), 'The parser strategy, on '.$call);
        }
    }

    /**
     * @return iterable<string, array{call: string, function: string, limit: int|null, everyMatch: string|null, presets?: list<string>}>
     */
    public static function provideCalls(): iterable
    {
        yield 'preg_match' => ['call' => "preg_match('/p/', \$s, \$m);", 'function' => 'preg_match', 'limit' => null, 'everyMatch' => null];
        yield 'preg_match without matches' => ['call' => "preg_match('/p/', \$s);", 'function' => 'preg_match', 'limit' => null, 'everyMatch' => null];
        yield 'preg_grep' => ['call' => "preg_grep('/p/', [\$s]);", 'function' => 'preg_grep', 'limit' => null, 'everyMatch' => null];
        yield 'preg_match_all' => ['call' => "preg_match_all('/p/', \$s);", 'function' => 'preg_match_all', 'limit' => null, 'everyMatch' => 'preg_match_all'];
        yield 'preg_match_all with an offset' => ['call' => "preg_match_all('/p/', \$s, \$m, 0, 3);", 'function' => 'preg_match_all', 'limit' => null, 'everyMatch' => 'preg_match_all'];
        yield 'preg_replace' => ['call' => "preg_replace('/p/', '', \$s);", 'function' => 'preg_replace', 'limit' => null, 'everyMatch' => 'preg_replace'];
        yield 'preg_replace with no limit' => ['call' => "preg_replace('/p/', '', \$s, -1);", 'function' => 'preg_replace', 'limit' => -1, 'everyMatch' => 'preg_replace'];
        yield 'preg_replace with a limit' => ['call' => "preg_replace('/p/', '', \$s, 1);", 'function' => 'preg_replace', 'limit' => 1, 'everyMatch' => null];
        yield 'preg_replace with a limit of zero' => ['call' => "preg_replace('/p/', '', \$s, 0);", 'function' => 'preg_replace', 'limit' => 0, 'everyMatch' => null];
        yield 'preg_replace with a named limit' => ['call' => "preg_replace('/p/', '', limit: 2, subject: \$s);", 'function' => 'preg_replace', 'limit' => 2, 'everyMatch' => null];
        yield 'preg_replace with a limit it cannot read' => ['call' => "preg_replace('/p/', '', \$s, \$n);", 'function' => 'preg_replace', 'limit' => null, 'everyMatch' => 'preg_replace'];
        yield 'preg_replace with a limit it computes' => ['call' => "preg_replace('/p/', '', \$s, 1 + \$n);", 'function' => 'preg_replace', 'limit' => null, 'everyMatch' => 'preg_replace'];
        yield 'preg_replace with a limit under signs and parentheses' => ['call' => "preg_replace('/p/', '', \$s, (- -1));", 'function' => 'preg_replace', 'limit' => 1, 'everyMatch' => null];
        yield 'preg_replace with its arguments spread' => ['call' => "preg_replace('/p/', ...\$rest);", 'function' => 'preg_replace', 'limit' => null, 'everyMatch' => 'preg_replace'];
        yield 'preg_filter' => ['call' => "preg_filter('/p/', '', \$s);", 'function' => 'preg_filter', 'limit' => null, 'everyMatch' => 'preg_filter'];
        yield 'preg_replace_callback' => ['call' => "preg_replace_callback('/p/', static fn (\$m) => '', \$s);", 'function' => 'preg_replace_callback', 'limit' => null, 'everyMatch' => 'preg_replace_callback'];
        yield 'preg_replace_callback with a limit' => ['call' => "preg_replace_callback('/p/', static fn (\$m) => '', \$s, 3);", 'function' => 'preg_replace_callback', 'limit' => 3, 'everyMatch' => null];
        yield 'preg_replace_callback_array' => ['call' => "preg_replace_callback_array(['/p/' => static fn (\$m) => ''], \$s);", 'function' => 'preg_replace_callback_array', 'limit' => null, 'everyMatch' => 'preg_replace_callback_array'];
        yield 'preg_replace_callback_array with a limit' => ['call' => "preg_replace_callback_array(['/p/' => static fn (\$m) => ''], \$s, 1);", 'function' => 'preg_replace_callback_array', 'limit' => 1, 'everyMatch' => null];
        yield 'preg_split' => ['call' => "preg_split('/p/', \$s);", 'function' => 'preg_split', 'limit' => null, 'everyMatch' => 'preg_split'];
        yield 'preg_split with a limit of zero' => ['call' => "preg_split('/p/', \$s, 0);", 'function' => 'preg_split', 'limit' => 0, 'everyMatch' => 'preg_split'];
        yield 'preg_split with no limit and a flag' => ['call' => "preg_split('/p/', \$s, -1, PREG_SPLIT_NO_EMPTY);", 'function' => 'preg_split', 'limit' => -1, 'everyMatch' => 'preg_split'];
        yield 'preg_split with a limit' => ['call' => "preg_split('/p/', \$s, 2);", 'function' => 'preg_split', 'limit' => 2, 'everyMatch' => null];
        yield 'preg_split with a negative limit' => ['call' => "preg_split('/p/', \$s, -2);", 'function' => 'preg_split', 'limit' => -2, 'everyMatch' => null];
        yield 'Safe drop-in' => ['call' => "\\Safe\\preg_match_all('/p/', \$s);", 'function' => 'preg_match_all', 'limit' => null, 'everyMatch' => 'preg_match_all'];
        yield 'composer/pcre matchAll' => ['call' => "\\Composer\\Pcre\\Preg::matchAll('/p/', \$s);", 'function' => 'preg_match_all', 'limit' => null, 'everyMatch' => 'preg_match_all', 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre isMatchAll' => ['call' => "\\Composer\\Pcre\\Preg::isMatchAll('/p/', \$s);", 'function' => 'preg_match_all', 'limit' => null, 'everyMatch' => 'preg_match_all', 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre match' => ['call' => "\\Composer\\Pcre\\Preg::match('/p/', \$s);", 'function' => 'preg_match', 'limit' => null, 'everyMatch' => null, 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre grep' => ['call' => "\\Composer\\Pcre\\Preg::grep('/p/', [\$s]);", 'function' => 'preg_grep', 'limit' => null, 'everyMatch' => null, 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre replace' => ['call' => "\\Composer\\Pcre\\Preg::replace('/p/', '', \$s);", 'function' => 'preg_replace', 'limit' => null, 'everyMatch' => 'preg_replace', 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre replace with a limit' => ['call' => "\\Composer\\Pcre\\Preg::replace('/p/', '', \$s, 1);", 'function' => 'preg_replace', 'limit' => 1, 'everyMatch' => null, 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre replaceCallback' => ['call' => "\\Composer\\Pcre\\Regex::replaceCallback('/p/', static fn (\$m) => '', \$s);", 'function' => 'preg_replace_callback', 'limit' => null, 'everyMatch' => 'preg_replace_callback', 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre replaceCallbackArray' => ['call' => "\\Composer\\Pcre\\Preg::replaceCallbackArray(['/p/' => static fn (\$m) => ''], \$s);", 'function' => 'preg_replace_callback_array', 'limit' => null, 'everyMatch' => 'preg_replace_callback_array', 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre split' => ['call' => "\\Composer\\Pcre\\Preg::split('/p/', \$s);", 'function' => 'preg_split', 'limit' => null, 'everyMatch' => 'preg_split', 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre split with a limit' => ['call' => "\\Composer\\Pcre\\Preg::splitWithOffsets('/p/', \$s, 2);", 'function' => 'preg_split', 'limit' => 2, 'everyMatch' => null, 'presets' => [InteropPresets::COMPOSER_PCRE]];
    }

    /**
     * A literal several calls read, through a variable or a constant, is
     * reported once, where it is written, at its first call: the functions
     * of the other calls that look for every match come with it, each
     * once, in the order of the calls, so that each such call gets the
     * cost of its loop. A call a literal limit bounds, or one that stops at
     * the first match, adds none.
     *
     * @param list<string> $loops     the functions whose loop the oracle runs
     * @param list<string> $elsewhere
     */
    #[Test]
    #[DataProvider('provideLiteralsSeveralCallsRead')]
    public function test_extraction_names_every_loop_a_literal_runs_in(string $code, array $loops, ?string $everyMatch, array $elsewhere): void
    {
        foreach ($loops as $function) {
            $this->assertGreaterThan(self::matchesMade($function, null, str_repeat('a', 1000)), self::matchesMade($function, null, str_repeat('a', 2000)), 'Oracle: '.$function.' stops early.');
        }
        $this->assertSame(1, self::matchesMade('preg_match', null, str_repeat('a', 2000)), 'Oracle: preg_match() goes on.');
        $this->assertSame(1, self::matchesMade('preg_replace', 1, str_repeat('a', 2000)), 'Oracle: the limit does not bound preg_replace().');

        $code = "<?php\n\$s = 'x';\n".$code."\n";
        $expected = [['/p/', $everyMatch, $elsewhere]];
        $this->assertSame($expected, $this->readLoopsWith(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), $code), 'The token strategy.');
        if (class_exists(ParserFactory::class)) {
            $this->assertSame($expected, $this->readLoopsWith(new PhpParserExtractionStrategy([], PatternFunctionRegistry::native()), $code), 'The parser strategy.');
        }
    }

    /**
     * @return iterable<string, array{code: string, loops: list<string>, everyMatch: string|null, elsewhere: list<string>}>
     */
    public static function provideLiteralsSeveralCallsRead(): iterable
    {
        yield 'a variable preg_match() reads first' => ['code' => "function f(\$s) {\n\$p = '/p/';\npreg_match(\$p, \$s);\npreg_match_all(\$p, \$s);\n}", 'loops' => ['preg_match_all'], 'everyMatch' => null, 'elsewhere' => ['preg_match_all']];
        yield 'a variable three loops read' => ['code' => "function f(\$s) {\n\$p = '/p/';\npreg_match_all(\$p, \$s);\npreg_replace(\$p, '', \$s);\npreg_split(\$p, \$s);\n}", 'loops' => ['preg_match_all', 'preg_replace', 'preg_split'], 'everyMatch' => 'preg_match_all', 'elsewhere' => ['preg_replace', 'preg_split']];
        yield 'a variable the same loop reads twice' => ['code' => "function f(\$s) {\n\$p = '/p/';\npreg_match_all(\$p, \$s);\npreg_match_all(\$p, \$s, \$m);\n}", 'loops' => ['preg_match_all'], 'everyMatch' => 'preg_match_all', 'elsewhere' => []];
        yield 'a variable a bounded call reads after preg_match()' => ['code' => "function f(\$s) {\n\$p = '/p/';\npreg_match(\$p, \$s);\npreg_replace(\$p, '', \$s, 1);\n}", 'loops' => [], 'everyMatch' => null, 'elsewhere' => []];
        yield 'a constant preg_match() reads first' => ['code' => "const P = '/p/';\nfunction f(\$s) { preg_match(P, \$s); return preg_split(P, \$s); }", 'loops' => ['preg_split'], 'everyMatch' => null, 'elsewhere' => ['preg_split']];
        yield 'a class constant preg_match() reads first' => ['code' => "class K { const Q = '/p/'; function g(\$s) { preg_match(self::Q, \$s); return preg_replace_callback(self::Q, static fn (\$m) => '', \$s); } }", 'loops' => ['preg_replace_callback'], 'everyMatch' => null, 'elsewhere' => ['preg_replace_callback']];
        yield 'a variable a list reads after preg_match()' => ['code' => "function f(\$s) {\n\$p = '/p/';\npreg_match(\$p, \$s);\npreg_replace([\$p], '', \$s);\n}", 'loops' => ['preg_replace'], 'everyMatch' => null, 'elsewhere' => ['preg_replace']];
    }

    /**
     * A call reads, through a variable, the value of the latest assignment
     * before it in straight-line code: the loop over every match of the
     * call is that literal's alone. Where an assignment in a branch or a
     * loop may stand between, the call names no loop: it is no literal's
     * more than another's.
     *
     * @param list<string>                                   $loops    the functions whose loop the oracle runs
     * @param list<array{string, string|null, list<string>}> $expected
     */
    #[Test]
    #[DataProvider('provideAssignmentsACallReads')]
    public function test_extraction_names_the_loop_of_the_assignment_a_call_reads(string $code, array $loops, array $expected): void
    {
        foreach ($loops as $function) {
            $this->assertGreaterThan(self::matchesMade($function, null, str_repeat('a', 1000)), self::matchesMade($function, null, str_repeat('a', 2000)), 'Oracle: '.$function.' stops early.');
        }
        $this->assertSame(1, self::matchesMade('preg_match', null, str_repeat('a', 2000)), 'Oracle: preg_match() goes on.');

        $code = "<?php\n\$s = 'x';\n".$code."\n";
        $this->assertSame($expected, $this->readLoopsWith(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), $code), 'The token strategy.');
        if (class_exists(ParserFactory::class)) {
            $this->assertSame($expected, $this->readLoopsWith(new PhpParserExtractionStrategy([], PatternFunctionRegistry::native()), $code), 'The parser strategy.');
        }
    }

    /**
     * @return iterable<string, array{code: string, loops: list<string>, expected: list<array{string, string|null, list<string>}>}>
     */
    public static function provideAssignmentsACallReads(): iterable
    {
        yield 'a variable given another literal after the loop that reads it' => [
            'code' => "function f(\$s) { \$p = '/p/'; preg_match_all(\$p, \$s); \$p = '/q/'; preg_match(\$p, \$s); }",
            'loops' => ['preg_match_all'],
            'expected' => [['/p/', 'preg_match_all', []], ['/q/', null, []]],
        ];
        yield 'a variable each loop reads with its own literal' => [
            'code' => "function f(\$s) { \$p = '/p/'; preg_match_all(\$p, \$s); \$p = '/q/'; preg_split(\$p, \$s); }",
            'loops' => ['preg_match_all', 'preg_split'],
            'expected' => [['/p/', 'preg_match_all', []], ['/q/', null, ['preg_split']]],
        ];
        yield 'a variable given its literal once, read by two loops' => [
            'code' => "function f(\$s) { \$p = '/p/'; preg_match_all(\$p, \$s); preg_split(\$p, \$s); }",
            'loops' => ['preg_match_all', 'preg_split'],
            'expected' => [['/p/', 'preg_match_all', ['preg_split']]],
        ];
        yield 'a variable a branch may give another literal' => [
            'code' => "function f(\$s, \$c) { \$p = '/p/'; if (\$c) { \$p = '/q/'; } preg_match_all(\$p, \$s); }",
            'loops' => ['preg_match_all'],
            'expected' => [['/p/', null, []], ['/q/', null, []]],
        ];
        yield 'a variable each branch gives a literal' => [
            'code' => "function f(\$s, \$c) { if (\$c) { \$p = '/p/'; } else { \$p = '/q/'; } preg_match_all(\$p, \$s); }",
            'loops' => ['preg_match_all'],
            'expected' => [['/p/', null, []], ['/q/', null, []]],
        ];
        yield 'a variable a later turn of the loop gives another literal' => [
            'code' => "function f(\$s, \$l) { \$p = '/p/'; foreach (\$l as \$x) { preg_match_all(\$p, \$s); \$p = '/q/'; } }",
            'loops' => ['preg_match_all'],
            'expected' => [['/p/', null, []], ['/q/', null, []]],
        ];
        yield 'a variable read through another one' => [
            'code' => "function f(\$s) { \$q = '/p/'; \$p = \$q; preg_match(\$p, \$s); preg_match_all(\$p, \$s); }",
            'loops' => ['preg_match_all'],
            'expected' => [['/p/', null, ['preg_match_all']]],
        ];
        yield 'a variable read through another one given another literal later' => [
            'code' => "function f(\$s) { \$q = '/p/'; \$p = \$q; preg_match_all(\$p, \$s); \$q = '/q/'; preg_match(\$q, \$s); }",
            'loops' => ['preg_match_all'],
            'expected' => [['/p/', 'preg_match_all', []], ['/q/', null, []]],
        ];
        yield 'a platform constant a loop reads through a variable' => [
            'code' => 'function f($s) { $eol = PHP_EOL; return preg_split($eol, $s); }',
            'loops' => ['preg_split'],
            'expected' => [["\n", 'preg_split', []], ["\r\n", 'preg_split', []]],
        ];
        // The head of a loop assigns the variable at each turn.
        yield 'a variable the head of a foreach gives each literal' => [
            'code' => "function f(\$s) { foreach (['/p/', '/q/'] as \$p) { preg_match_all(\$p, \$s); } }",
            'loops' => ['preg_match_all'],
            'expected' => [['/p/', null, []], ['/q/', null, []]],
        ];
        yield 'a variable a goto may come back to' => [
            'code' => "function f(\$s) { \$p = '/p/'; a: preg_match_all(\$p, \$s); goto a; }",
            'loops' => ['preg_match_all'],
            'expected' => [['/p/', null, []]],
        ];
        yield 'a parameter' => [
            'code' => 'function f($p, $s) { preg_match_all($p, $s); }',
            'loops' => ['preg_match_all'],
            'expected' => [],
        ];
        yield 'a variable read before its assignment' => [
            'code' => "function f(\$s) { preg_match_all(\$p, \$s); \$p = '/p/'; }",
            'loops' => ['preg_match_all'],
            'expected' => [],
        ];
        yield 'a variable composed with another one' => [
            'code' => "function f(\$s) { \$x = 'p'; \$p = '/'.\$x.'/'; preg_match(\$p, \$s); preg_match_all(\$p, \$s); }",
            'loops' => ['preg_match_all'],
            'expected' => [['/p/', null, []]],
        ];
        yield 'a variable holding a list of another one' => [
            'code' => "function f(\$s) { \$x = '/p/'; \$l = [\$x]; preg_match(\$x, \$s); preg_replace(\$l, '', \$s); }",
            'loops' => ['preg_replace'],
            'expected' => [['/p/', null, []]],
        ];
        // At the file level a call between the first assignment and the
        // read may write the variable: what the second call reads is not
        // known.
        yield 'a variable of the file level given another literal after the loop that reads it' => [
            'code' => "\$p = '/p/';\npreg_match_all(\$p, \$s);\n\$p = '/q/';\npreg_match(\$p, \$s);",
            'loops' => ['preg_match_all'],
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
    }

    /**
     * Each pattern of a list runs in the loop of the call.
     */
    #[Test]
    public function test_extraction_names_the_loop_of_each_pattern_of_a_list(): void
    {
        $this->assertGreaterThan(self::matchesMade('preg_replace', null, str_repeat('a', 1000)), self::matchesMade('preg_replace', null, str_repeat('a', 2000)), 'Oracle: preg_replace() stops early.');

        $code = "<?php\n\$s = 'x';\npreg_replace(['/p/', '/q/'], '', \$s);\n";
        $expected = [['/p/', 'preg_replace'], ['/q/', 'preg_replace']];
        $this->assertSame($expected, $this->readWith(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), $code));
        if (class_exists(ParserFactory::class)) {
            $this->assertSame($expected, $this->readWith(new PhpParserExtractionStrategy([], PatternFunctionRegistry::native()), $code));
        }
    }

    /**
     * An occurrence a custom extractor builds names no call: its search
     * keeps the verdict of preg_match().
     */
    #[Test]
    public function test_an_occurrence_found_by_a_custom_extractor_names_no_loop(): void
    {
        $this->assertNull(PatternOccurrence::found('/p/', 'file.php', 1)->everyMatch);
    }

    /**
     * The copies of an occurrence keep the call it runs in.
     */
    #[Test]
    public function test_the_copies_of_an_occurrence_keep_its_loop(): void
    {
        $occurrence = new PatternOccurrence('/p/', 'file.php', 1, 'preg_split()', everyMatch: 'preg_split', everyMatchElsewhere: ['preg_replace']);

        $this->assertSame('preg_split', $occurrence->ignoredWhole()->everyMatch);
        $this->assertSame('preg_split', $occurrence->analysed()->everyMatch);
        $this->assertSame(['preg_replace'], $occurrence->ignoredWhole()->everyMatchElsewhere);
        $this->assertSame(['preg_replace'], $occurrence->analysed()->everyMatchElsewhere);
        $this->assertSame(['preg_replace'], $occurrence->guardedBy(TargetConstraint::phpFrom(80200, null))->everyMatchElsewhere);
        $this->assertSame(['preg_split', 'preg_replace'], $occurrence->everyMatchCalls());
        $this->assertSame(['preg_replace'], (new PatternOccurrence('/p/', 'file.php', 1, 'preg_match()', everyMatchElsewhere: ['preg_replace']))->everyMatchCalls());
        $this->assertSame([], PatternOccurrence::found('/p/', 'file.php', 1)->everyMatchCalls());
    }

    /**
     * How many matches the function makes with the probe on the subject,
     * under the limit when one is given: the oracle of the loop.
     */
    private static function matchesMade(string $function, ?int $limit, string $subject): int
    {
        $count = 0;
        $replace = static fn (array $matches): string => '';
        $made = match ($function) {
            'preg_match' => preg_match(self::PROBE, $subject),
            'preg_grep' => preg_grep(self::PROBE, [$subject]),
            'preg_match_all' => preg_match_all(self::PROBE, $subject),
            'preg_replace' => null === preg_replace(self::PROBE, '', $subject, $limit ?? -1, $count) ? false : $count,
            'preg_filter' => null === preg_filter(self::PROBE, '', $subject, $limit ?? -1, $count) ? false : $count,
            'preg_replace_callback' => null === preg_replace_callback(self::PROBE, $replace, $subject, $limit ?? -1, $count) ? false : $count,
            'preg_replace_callback_array' => null === preg_replace_callback_array([self::PROBE => $replace], $subject, $limit ?? -1, $count) ? false : $count,
            'preg_split' => preg_split(self::PROBE, $subject, $limit ?? -1),
            default => false,
        };
        // The pieces of preg_split() are one more than its matches.
        if (\is_array($made)) {
            $made = \count($made) - ('preg_split' === $function ? 1 : 0);
        }
        self::assertIsInt($made, 'Oracle: '.$function.' failed.');

        return $made;
    }

    /**
     * The pattern, the loop of its call and those of the other calls of
     * each occurrence the strategy reads.
     *
     * @return list<array{string, string|null, list<string>}>
     */
    private function readLoopsWith(ExtractorInterface $strategy, string $code): array
    {
        $directory = $this->makeProject(['subject.php' => $code]);
        $read = [];
        foreach ($strategy->extract([$directory.'/subject.php']) as $occurrence) {
            $this->assertNull($occurrence->parserFallback, 'The parser refused the file: '.($occurrence->parserFallback ?? ''));
            $read[] = [$occurrence->pattern, $occurrence->everyMatch, $occurrence->everyMatchElsewhere];
        }

        return $read;
    }

    /**
     * The pattern and the loop of each occurrence the strategy reads.
     *
     * @return list<array{string, string|null}>
     */
    private function readWith(ExtractorInterface $strategy, string $code): array
    {
        $directory = $this->makeProject(['subject.php' => $code]);
        $read = [];
        foreach ($strategy->extract([$directory.'/subject.php']) as $occurrence) {
            $this->assertNull($occurrence->parserFallback, 'The parser refused the file: '.($occurrence->parserFallback ?? ''));
            $read[] = [$occurrence->pattern, $occurrence->everyMatch];
        }

        return $read;
    }
}
