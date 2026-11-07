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

namespace PHPRegex\Tests\Unit\Bridge\Infection;

use PhpParser\Node\Expr\FuncCall;
use PHPRegex\Infection\Hint\HintWriter;
use PHPRegex\Infection\Hint\KillString;
use PHPRegex\Infection\Judge\Budget;
use PHPRegex\Infection\Judge\CallReplay;
use PHPRegex\Infection\Judge\DifferentialFallback;
use PHPRegex\Infection\Judge\EquivalenceJudge;
use PHPRegex\Infection\Judge\RedosGuard;
use PHPRegex\Infection\Mutation\CandidateGenerator;
use PHPRegex\Infection\Mutation\Family;
use PHPRegex\Infection\Mutation\MutationPipeline;
use PHPRegex\Infection\Mutator\RegexAnchor;
use PHPRegex\Infection\Mutator\RegexCharClass;
use PHPRegex\Infection\Mutator\RegexQuantifier;
use PHPRegex\Infection\Site\Observation;
use PHPRegex\Infection\Site\PatternCall;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Engine\PcreLimits;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The spellings PHP accepts around and inside a pattern that the mutators
 * must read as PHP does, and the failure paths of each part of the work.
 */
final class EdgeCaseTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideSpellings')]
    public function test_the_mutants_keep_the_spelling_php_accepts(Family $family, string $pattern, array $expected): void
    {
        $this->assertSame($expected, (new CandidateGenerator())->candidates($family, $pattern));
    }

    /**
     * @return iterable<string, array{family: Family, pattern: string, expected: list<string>}>
     */
    public static function provideSpellings(): iterable
    {
        // PHP skips the bytes isspace() accepts before the delimiter, the
        // form feed and the vertical tab included.
        yield 'form feed before the delimiter' => ['family' => Family::Quantifier, 'pattern' => "\f/a+/", 'expected' => ["\f/a*/", "\f/a/", "\f/a+?/"]];
        yield 'vertical tab before the delimiter' => ['family' => Family::Anchor, 'pattern' => "\v/^a/", 'expected' => ["\v/a/"]];
        // PHP skips spaces and newlines among the modifiers.
        yield 'newline after the modifiers' => ['family' => Family::Flag, 'pattern' => "/a/is\n", 'expected' => ["/a/s\n", "/a/i\n"]];
        yield 'space among the modifiers' => ['family' => Family::Flag, 'pattern' => '/a/i s', 'expected' => ['/a/ s', '/a/i ']];
        // Under /x, white space may stand between an atom and its repeat,
        // and around the bar of an alternation.
        yield 'space before a repeat under x' => ['family' => Family::Quantifier, 'pattern' => '/a +/x', 'expected' => ['/a */x', '/a /x', '/a +?/x']];
        yield 'spaces around a bar under x' => ['family' => Family::Alternation, 'pattern' => '/a | b/x', 'expected' => ['/b/x', '/a/x', '/b | a/x']];
        // "\E" outside "\Q" means nothing: it may stand before a bar.
        yield 'inert \E before a bar' => ['family' => Family::Alternation, 'pattern' => '/a\E|b/', 'expected' => ['/b/', '/a/', '/b\E|a/']];
        yield 'empty quote before a bar' => ['family' => Family::Alternation, 'pattern' => '/a\Q\E|b/', 'expected' => ['/b/', '/a/', '/b\Q\E|a/']];
        // A comment between an atom and its repeat: the repeat is left
        // alone rather than rewritten around text it does not own.
        yield 'comment between an atom and its repeat' => ['family' => Family::Quantifier, 'pattern' => '/a(?#c)+/', 'expected' => []];
        yield 'comment before a bar under x' => ['family' => Family::Alternation, 'pattern' => "/a # c\n|b/x", 'expected' => ['/b/x', "/a # c\n/x", "/b|a # c\n/x"]];
    }

    #[Test]
    public function test_spaces_inside_braces_are_read_as_the_target_reads_them(): void
    {
        // PCRE2 10.43 reads spaces around the bounds of a repeat.
        $generator = new CandidateGenerator(RegexParser::create(['pcre_version' => '10.44']));

        $this->assertSame(['/a{3,3}/', '/a{1,3}/', '/a{2,2}/', '/a{2,4}/', '/a/', '/a{ 2,3 }?/'], $generator->candidates(Family::Quantifier, '/a{ 2,3 }/'));
    }

    #[Test]
    public function test_kill_strings_of_a_utf_pattern_stay_utf8(): void
    {
        // (*UTF) turns UTF mode on without /u: PHP then checks no subject,
        // and an invalid one is PCRE2's undefined behaviour.
        $patterns = MutatorHarness::patterns(new RegexCharClass(), "preg_match('/(*UTF)(*UCP)^[\\d0-9]\$/', \$s)");

        $this->assertNotSame([], $patterns);
        foreach ($patterns as $pattern) {
            $kill = MutatorHarness::split($pattern)[1];
            $this->assertTrue(null === $kill || 1 === preg_match('//u', $kill), $pattern);
        }
    }

    #[Test]
    public function test_mutants_are_handed_over_one_at_a_time(): void
    {
        $call = MutatorHarness::call("preg_match('/^a+\$/', \$s)");
        $this->assertInstanceOf(FuncCall::class, $call);

        // The first mutant is there before the others are written.
        $mutants = (new RegexAnchor())->mutate($call);
        $this->assertTrue($mutants->valid());
        $this->assertSame('/a+$/', MutatorHarness::split(MutatorHarness::patternOf($mutants->current()))[0]);
    }

    #[Test]
    public function test_a_long_literal_mutates_within_php_default_memory(): void
    {
        // Three thousand branches: every candidate a 21 KB pattern of its own,
        // more than 128 MB together. Infection takes them one at a time.
        $branches = implode('|', array_map(static fn (int $i): string => \sprintf('w%05d', $i), range(0, 2999)));
        $script = <<<'PHP_WRAP'
            <?php
            require $argv[1].'/tools/phpunit/vendor/autoload.php';
            require $argv[1].'/vendor/autoload.php';
            $call = new PhpParser\Node\Expr\FuncCall(new PhpParser\Node\Name('preg_match'), [new PhpParser\Node\Arg(new PhpParser\Node\Scalar\String_('/'.$argv[2].'/')), new PhpParser\Node\Arg(new PhpParser\Node\Expr\Variable('s'))]);
            $mutator = new PHPRegex\Infection\Mutator\RegexAlternation();
            $count = 0;
            foreach ($mutator->mutate($call) as $mutant) {
                ++$count;
            }
            echo $count;
            PHP_WRAP;
        $file = tempnam(sys_get_temp_dir(), 'mutate');
        $this->assertIsString($file);
        file_put_contents($file, $script);

        try {
            $process = proc_open([\PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'auto_prepend_file=', $file, \dirname(__DIR__, 4), $branches], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
        } finally {
            unlink($file);
        }

        $this->assertSame(0, $status, $errors.$output);
        $this->assertGreaterThan(5000, (int) $output);
    }

    #[Test]
    public function test_a_long_alternation_is_judged_within_seconds(): void
    {
        // Seven hundred branches: a question to the automata about such a
        // pattern costs a second or two, so it takes more of the budget.
        $branches = implode('|', array_map(static fn (int $i): string => 'w'.$i, range(1, 700)));
        $call = PatternCall::fromNode(MutatorHarness::call("preg_match('/".$branches."/', \$s)") ?? throw new \LogicException());
        $this->assertInstanceOf(PatternCall::class, $call);

        $start = hrtime(true);
        $mutants = (new MutationPipeline(Family::Alternation))->mutants($call);

        $this->assertNotSame([], $mutants);
        $this->assertLessThan(20.0, (hrtime(true) - $start) / 1e9);
    }

    #[Test]
    public function test_nothing_is_kept_from_one_literal_to_the_next(): void
    {
        // The library's own caches of what it read are bounded and shared
        // by the process; the mutators keep no tree of the literals they
        // read on top of them.
        $pipeline = new MutationPipeline(Family::CharClass);
        foreach (range(0, 5) as $seed) {
            $call = PatternCall::fromNode(MutatorHarness::call("preg_match('/[a-z0-9]x".$seed."/', \$s, \$m)") ?? throw new \LogicException());
            $this->assertInstanceOf(PatternCall::class, $call);
            $pipeline->mutants($call);
        }

        $parser = (new \ReflectionProperty(MutationPipeline::class, 'parser'))->getValue($pipeline);
        $this->assertInstanceOf(RegexParser::class, $parser);
        $this->assertInstanceOf(NullCache::class, $parser->getCache());
    }

    #[Test]
    public function test_hint_writer_leaves_a_pattern_it_cannot_read(): void
    {
        $this->assertNull((new HintWriter())->write('/(/', 'a'));
    }

    #[Test]
    public function test_pipeline_writes_nothing_for_a_pattern_the_parser_refuses(): void
    {
        // The engine compiles it; the parser stops at its length limit.
        $call = PatternCall::fromNode(MutatorHarness::call("preg_match('/a+(?#".str_repeat('c', 100_000).")/', \$s)") ?? throw new \LogicException());
        $this->assertInstanceOf(PatternCall::class, $call);

        $this->assertSame([], (new MutationPipeline(Family::Quantifier))->mutants($call));
    }

    #[Test]
    public function test_hint_writer_leaves_a_hint_the_parser_cannot_read_back(): void
    {
        // The pattern fits under the parser's length limit, the hinted one
        // does not.
        $pattern = '/a(?#'.str_repeat('c', 99_990).')/';

        $this->assertNotNull((new HintWriter())->write('/a/', 'aa'));
        $this->assertNull((new HintWriter())->write($pattern, 'aa'));
    }

    #[Test]
    public function test_pipeline_writes_nothing_for_a_pattern_only_the_parser_reads(): void
    {
        // The parser reads the bounds, the engine refuses them out of order.
        $call = PatternCall::fromNode(MutatorHarness::call("preg_match('/a{2,1}/', \$s)") ?? throw new \LogicException());
        $this->assertInstanceOf(PatternCall::class, $call);

        $this->assertSame([], (new MutationPipeline(Family::Quantifier))->mutants($call));
    }

    #[Test]
    public function test_pipeline_writes_nothing_for_a_pattern_the_engine_refuses(): void
    {
        $call = PatternCall::fromNode(MutatorHarness::call("preg_match('/(/', \$s)") ?? throw new \LogicException());
        $this->assertInstanceOf(PatternCall::class, $call);

        $this->assertSame([], (new MutationPipeline(Family::Quantifier))->mutants($call));
    }

    #[Test]
    public function test_replay_refuses_a_call_it_cannot_replay(): void
    {
        $call = PatternCall::fromNode(MutatorHarness::call("preg_match('/a/', \$s, \$m, \$flags)") ?? throw new \LogicException());
        $this->assertInstanceOf(PatternCall::class, $call);

        $this->assertFalse((new CallReplay())->replayable($call));
        $this->assertFalse((new CallReplay())->differs($call, '/a/', '/b/', 'a'));
    }

    #[Test]
    #[DataProvider('provideFailingCalls')]
    public function test_replay_of_a_failing_call_tells_nothing_and_leaves_no_error(string $code): void
    {
        $call = PatternCall::fromNode(MutatorHarness::call($code) ?? throw new \LogicException());
        $this->assertInstanceOf(PatternCall::class, $call);

        error_clear_last();

        // Invalid UTF-8 under /u: each call fails, none answers.
        $this->assertFalse((new CallReplay())->differs($call, '/a/u', '/b/u', "a\xff"));
        $this->assertSame(\PREG_NO_ERROR, preg_last_error());
        $this->assertNull(error_get_last());
    }

    /**
     * @return iterable<string, array{code: string}>
     */
    public static function provideFailingCalls(): iterable
    {
        yield 'preg_match' => ['code' => "preg_match('/a/u', \$s, \$m)"];
        yield 'preg_match_all' => ['code' => "preg_match_all('/a/u', \$s, \$m)"];
        yield 'preg_replace' => ['code' => "preg_replace('/a/u', 'b', \$s)"];
        yield 'preg_replace_callback' => ['code' => "preg_replace_callback('/a/u', \$f, \$s)"];
        yield 'preg_split' => ['code' => "preg_split('/a/u', \$s)"];
        yield 'preg_grep' => ['code' => "preg_grep('/a/u', \$list)"];
    }

    #[Test]
    public function test_replay_tells_apart_from_a_negative_offset(): void
    {
        $call = PatternCall::fromNode(MutatorHarness::call("preg_match('/a\$/', \$s, \$m, 0, -2)") ?? throw new \LogicException());
        $this->assertInstanceOf(PatternCall::class, $call);

        // From two bytes before the end: "ba" holds no "a" at its end for
        // /b$/, and one for /a$/.
        $this->assertTrue((new CallReplay())->differs($call, '/a$/', '/b$/', 'xba'));
        $this->assertSame(-2, $call->integer('offset'));
    }

    #[Test]
    public function test_an_argument_the_call_does_not_pass_reads_as_no_integer(): void
    {
        $call = PatternCall::fromNode(MutatorHarness::call("preg_match('/a/', \$s, \$m)") ?? throw new \LogicException());
        $this->assertInstanceOf(PatternCall::class, $call);

        $this->assertNull($call->integer('offset'));
        $this->assertNull($call->integer('flags'));
    }

    #[Test]
    public function test_a_kill_string_too_long_for_a_hint_is_not_looked_for(): void
    {
        // From 100,000 bytes in, every kill string is longer than a hint
        // holds: none is replayed byte by byte to make it printable.
        $start = hrtime(true);
        $patterns = MutatorHarness::patterns(new RegexQuantifier(), "preg_match('/a+/', \$s, \$m, 0, 100000)");

        $this->assertLessThan(2.0, (hrtime(true) - $start) / 1e9);
        $this->assertSame(['/a*/', '/a/'], $patterns);
    }

    #[Test]
    public function test_a_kill_string_as_long_as_a_hint_holds_is_written(): void
    {
        $kills = static fn (int $offset): array => array_map(static fn (string $pattern): ?string => MutatorHarness::split($pattern)[1], MutatorHarness::patterns(new RegexQuantifier(), "preg_match('/a+/', \$s, \$m, 0, {$offset})"));

        // "aa" from the offset tells /a/ apart: two bytes past the last
        // one a hint holds.
        $this->assertSame([str_repeat('!', KillString::MAX_BYTES), null], $kills(KillString::MAX_BYTES));
        $this->assertSame([null, null], $kills(KillString::MAX_BYTES + 1));
    }

    #[Test]
    public function test_judge_gives_a_subject_two_runs_find_once(): void
    {
        $this->assertSame(['a'], (new EquivalenceJudge())->judge(Observation::Matches, '/a*/', '/b*/', new Budget())->subjects);
    }

    #[Test]
    public function test_a_call_that_cannot_be_replayed_spends_nothing_on_a_kill_string(): void
    {
        // Twelve units prove /a+?b+c+/ alike the original, none of them
        // taken by a search for a subject no replay could check.
        $call = PatternCall::fromNode(MutatorHarness::call("preg_match('/a+b+c+/', \$s, \$m, 0, \$offset)") ?? throw new \LogicException());
        $this->assertInstanceOf(PatternCall::class, $call);

        $this->assertSame(['/a*b+c+/', '/ab+c+/', '/a+b*c+/', '/a+bc+/', '/a+b+?c+/', '/a+b+c*/', '/a+b+c/', '/a+b+c+?/'], (new MutationPipeline(Family::Quantifier, 12))->mutants($call));
    }

    #[Test]
    public function test_a_budget_is_spent_once_its_last_unit_is(): void
    {
        $exact = new Budget(1);
        $this->assertTrue($exact->spend('a'));
        $this->assertTrue($exact->spent());

        // A check that costs more than what is left takes it all.
        $short = new Budget(2);
        $this->assertFalse($short->spend(str_repeat('a', 512)));
        $this->assertTrue($short->spent());
        $this->assertFalse($short->spend('a'));
    }

    #[Test]
    public function test_judge_leaves_the_verdict_open_without_budget_or_reading(): void
    {
        $judge = new EquivalenceJudge();

        $this->assertNull($judge->judge(Observation::Matches, '/a/', '/b/', new Budget(0))->alike);
        // Even patterns that read alike wait for a unit.
        $this->assertNull($judge->judge(Observation::Matches, '/[0-9]/', '/\d/', new Budget(0))->alike);
        $this->assertNull($judge->judge(Observation::Match, '/a/', '/b/', new Budget(0))->alike);
        $this->assertNull($judge->judge(Observation::Matches, '/a/', '/(/', new Budget())->alike);
        $this->assertNull($judge->judge(Observation::Match, '/a/', '/(/', new Budget())->alike);
        // One unit asks which runs to compare, none is left to compare them.
        $this->assertNull($judge->judge(Observation::Matches, '/^a/', '/^b/', new Budget(1))->alike);
    }

    #[Test]
    public function test_judge_gives_the_shortest_subject_first(): void
    {
        // Only /^aa$/ matches "aa", only /^b$/ matches "b".
        $this->assertSame(['b', 'aa'], (new EquivalenceJudge())->judge(Observation::Match, '/^aa$/', '/^b$/', new Budget())->subjects);
        $this->assertSame([0, 1], [preg_match('/^aa$/', 'b'), preg_match('/^b$/', 'b')]);
        $this->assertSame([1, 0], [preg_match('/^aa$/', 'aa'), preg_match('/^b$/', 'aa')]);
    }

    #[Test]
    public function test_judge_keeps_apart_what_one_run_told_apart_when_the_budget_runs_out(): void
    {
        // A unit for the runs, a unit for the first: "^" asks for a second
        // run, past the start, which the budget no longer pays.
        $verdict = (new EquivalenceJudge())->judge(Observation::Matches, '/^a*/', '/^b*/', new Budget(2));

        $this->assertFalse($verdict->alike);
        $this->assertSame(['a'], $verdict->subjects);
        preg_match('/^a*/', 'a', $left);
        preg_match('/^b*/', 'a', $right);
        $this->assertSame([['a'], ['']], [$left, $right]);
    }

    #[Test]
    public function test_judge_leaves_open_what_no_run_covers(): void
    {
        // "\b" reads the text before the search: no set of runs covers every
        // call. The verdict stays open, with no subject.
        $verdict = (new EquivalenceJudge())->judge(Observation::Matches, '/\bfo+/', '/\bfo*/', new Budget());

        $this->assertNull($verdict->alike);
        $this->assertSame([], $verdict->subjects);
    }

    #[Test]
    public function test_judge_proves_patterns_that_read_alike_without_running_them(): void
    {
        $this->assertTrue((new EquivalenceJudge())->judge(Observation::Matches, '/[0-9]/', '/\d/', new Budget())->alike);
        foreach (range(0, 255) as $byte) {
            preg_match('/[0-9]/', \chr($byte), $class);
            preg_match('/\d/', \chr($byte), $type);
            $this->assertSame($class, $type, (string) $byte);
        }
    }

    #[Test]
    public function test_redos_guard_has_no_verdict_on_a_pattern_it_cannot_read(): void
    {
        $this->assertNull((new RedosGuard())->catastrophic('/(/', new Budget()));
        $this->assertNull((new RedosGuard())->catastrophic('/(a+)+$/', new Budget(0)));
        $this->assertTrue((new RedosGuard())->catastrophic('/(a+)+$/', new Budget()));
        $this->assertFalse((new RedosGuard())->catastrophic('/^a*a*\d*b$/', new Budget()));
        // The engine agrees: the exponential pattern exhausts a hundred
        // thousand backtracks on 30 bytes, the polynomial one does not.
        $limits = new PcreLimits(100_000, 10_000);
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, (new PcreEngine())->match('/(a+)+$/', str_repeat('a', 29).'!', $limits)->errorCode);
        $this->assertFalse((new PcreEngine())->match('/^a*a*\d*b$/', str_repeat('a', 29).'!', $limits)->matched);
        // A backreference leaves the complexity unknown, with no error.
        $this->assertNull((new RedosGuard())->catastrophic('/(a)\1+/', new Budget()));
    }

    #[Test]
    public function test_fallback_draws_four_samples_from_each_pattern(): void
    {
        $subjects = (new DifferentialFallback())->subjects('/[a-z]{6}/', '/[0-9]{6}/');

        $this->assertCount(2 * DifferentialFallback::SAMPLES, $subjects);
        $letters = array_filter($subjects, static fn (string $subject): bool => 1 === preg_match('/^[a-z]{6}$/', $subject));
        $digits = array_filter($subjects, static fn (string $subject): bool => 1 === preg_match('/^[0-9]{6}$/', $subject));

        $this->assertSame([4, 4], [\count($letters), \count($digits)]);
    }

    #[Test]
    public function test_fallback_draws_from_the_pattern_it_can_sample(): void
    {
        // Nothing matches (?!): the sample generator gives up on it.
        $subjects = (new DifferentialFallback())->subjects('/(?!)/', '/a+/');

        $this->assertNotSame([], $subjects);
        foreach ($subjects as $subject) {
            $this->assertSame(1, preg_match('/^a+$/', $subject), $subject);
        }
    }
}
