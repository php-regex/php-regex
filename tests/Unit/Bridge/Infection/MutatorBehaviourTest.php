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

use Infection\Mutator\Mutator;
use Infection\Mutator\MutatorCategory;
use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPRegex\Infection\Mutation\CandidateGenerator;
use PHPRegex\Infection\Mutation\Family;
use PHPRegex\Infection\Mutation\MutationPipeline;
use PHPRegex\Infection\Mutator\RegexAlternation;
use PHPRegex\Infection\Mutator\RegexAnchor;
use PHPRegex\Infection\Mutator\RegexCharClass;
use PHPRegex\Infection\Mutator\RegexEscape;
use PHPRegex\Infection\Mutator\RegexFlag;
use PHPRegex\Infection\Mutator\RegexQuantifier;
use PHPRegex\Infection\Site\PatternCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the mutators hand Infection: mutants that compile, none proven
 * equivalent under the way the call reads the pattern, each with the subject
 * that kills it when one is found and checked against the engine.
 */
final class MutatorBehaviourTest extends TestCase
{
    /**
     * @param class-string<Mutator<Node>> $class
     */
    #[Test]
    #[DataProvider('provideMutators')]
    public function test_each_mutator_is_named_after_its_class_and_defined(string $class, string $name, string $category): void
    {
        $mutator = new $class();

        $this->assertSame($name, $mutator->getName());
        $this->assertSame($category, $class::getDefinition()->getCategory());
        $this->assertNotSame('', $class::getDefinition()->getDescription());
        $this->assertStringContainsString('(?#kill:', (string) $class::getDefinition()->getRemedies());
        $this->assertStringStartsWith('- ', $class::getDefinition()->getDiff());
        $this->assertTrue((new \ReflectionClass($class))->isFinal());
    }

    /**
     * Infection shows the diff of a mutator's definition to its users: it is
     * a mutant the mutator writes, kill string included.
     *
     * @param class-string<Mutator<Node>> $class
     */
    #[Test]
    #[DataProvider('provideMutators')]
    public function test_each_definition_diff_is_a_mutant_the_mutator_writes(string $class, string $name, string $category): void
    {
        $this->assertSame($category, $class::getDefinition()->getCategory(), $name);
        $lines = explode("\n", $class::getDefinition()->getDiff());
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('- ', $lines[0]);
        $this->assertStringStartsWith('+ ', $lines[1]);

        $mutants = MutatorHarness::mutants(new $class(), substr($lines[0], 2));
        $printed = array_map(static fn (Node $mutant): string => (new Standard())->prettyPrintExpr($mutant).';', $mutants);

        $this->assertContains(substr($lines[1], 2), $printed);
    }

    /**
     * @return iterable<string, array{class: class-string<Mutator<Node>>, name: string, category: string}>
     */
    public static function provideMutators(): iterable
    {
        yield 'quantifier' => ['class' => RegexQuantifier::class, 'name' => 'RegexQuantifier', 'category' => MutatorCategory::ORTHOGONAL_REPLACEMENT];
        yield 'anchor' => ['class' => RegexAnchor::class, 'name' => 'RegexAnchor', 'category' => MutatorCategory::SEMANTIC_REDUCTION];
        yield 'class' => ['class' => RegexCharClass::class, 'name' => 'RegexCharClass', 'category' => MutatorCategory::ORTHOGONAL_REPLACEMENT];
        yield 'alternation' => ['class' => RegexAlternation::class, 'name' => 'RegexAlternation', 'category' => MutatorCategory::SEMANTIC_REDUCTION];
        yield 'flag' => ['class' => RegexFlag::class, 'name' => 'RegexFlag', 'category' => MutatorCategory::SEMANTIC_REDUCTION];
        yield 'escape' => ['class' => RegexEscape::class, 'name' => 'RegexEscape', 'category' => MutatorCategory::SEMANTIC_ADDITION];
    }

    #[Test]
    public function test_drops_mutant_proven_equivalent_for_boolean_preg_match(): void
    {
        // Whether a match exists: /a/ and /a+?/ answer as /a+/ does.
        $this->assertSame(['/a*/'], MutatorHarness::bare(new RegexQuantifier(), "preg_match('/a+/', \$s)"));
        self::assertTheEngineAgrees(Family::Quantifier, '/a+/', ['/a*/'], static fn (string $p, string $s): mixed => preg_match($p, $s), 'ab!');
    }

    #[Test]
    public function test_keeps_the_same_mutants_when_matches_are_observed(): void
    {
        // With $matches, "aa" tells /a/ from /a+/; /a+?/ stops at one "a"
        // as /a/ does, so it is the mutant already kept.
        $this->assertSame(['/a*/', '/a/'], MutatorHarness::bare(new RegexQuantifier(), "preg_match('/a+/', \$s, \$m)"));
        self::assertTheEngineAgrees(Family::Quantifier, '/a+/', ['/a*/', '/a/'], self::capturedBy(...), 'ab!');
        // Followed by more pattern, the lazy repeat stops at the first ">".
        $this->assertSame(['/<.*>/', '/<.>/', '/<.+?>/'], MutatorHarness::bare(new RegexQuantifier(), "preg_match('/<.+>/', \$s, \$m)"));
        self::assertTheEngineAgrees(Family::Quantifier, '/<.+>/', ['/<.*>/', '/<.>/', '/<.+?>/'], self::capturedBy(...), '<>a');
    }

    #[Test]
    public function test_preg_grep_reads_the_pattern_as_a_yes_or_no(): void
    {
        $this->assertSame(['/a*/'], MutatorHarness::bare(new RegexQuantifier(), "preg_grep('/a+/', \$list)"));
        self::assertTheEngineAgrees(Family::Quantifier, '/a+/', ['/a*/'], static fn (string $p, string $s): mixed => preg_grep($p, [$s]), 'ab!');
    }

    /**
     * @param Mutator<Node>                   $mutator
     * @param \Closure(string, string): mixed $call    the call as written, the pattern and the subject left open
     */
    #[Test]
    #[DataProvider('provideCalls')]
    public function test_kill_string_kills_the_mutant_for_the_call_as_written(Mutator $mutator, string $code, string $original, \Closure $call, bool $hinted): void
    {
        $patterns = MutatorHarness::patterns($mutator, $code);
        $this->assertNotSame([], $patterns);

        $found = 0;
        foreach ($patterns as $pattern) {
            [$bare, $subject] = MutatorHarness::split($pattern);
            if (null === $subject) {
                continue;
            }
            $found++;

            $this->assertNotSame($call($original, $subject), $call($bare, $subject), \sprintf('%s does not tell %s from %s', json_encode($subject), $original, $bare));
        }

        if ($hinted) {
            $this->assertGreaterThan(0, $found, 'no mutant carries a kill string');
        }
    }

    /**
     * @return iterable<string, array{mutator: Mutator<Node>, code: string, original: string, call: \Closure(string, string): mixed, hinted: bool}>
     */
    public static function provideCalls(): iterable
    {
        yield 'preg_match, yes or no' => [
            'mutator' => new RegexAnchor(), 'code' => "preg_match('/^\\d+\$/', \$s)", 'original' => '/^\d+$/',
            'call' => static fn (string $p, string $s): mixed => preg_match($p, $s), 'hinted' => true,
        ];
        yield 'preg_match with matches' => [
            'mutator' => new RegexQuantifier(), 'code' => "preg_match('/a+/', \$s, \$m)", 'original' => '/a+/',
            'call' => static fn (string $p, string $s): mixed => [preg_match($p, $s, $m), $m], 'hinted' => true,
        ];
        yield 'class' => [
            'mutator' => new RegexCharClass(), 'code' => "preg_match('/[a-c]x/', \$s, \$m)", 'original' => '/[a-c]x/',
            'call' => static fn (string $p, string $s): mixed => [preg_match($p, $s, $m), $m], 'hinted' => true,
        ];
        yield 'flag' => [
            'mutator' => new RegexFlag(), 'code' => "preg_match('/^abc\$/i', \$s)", 'original' => '/^abc$/i',
            'call' => static fn (string $p, string $s): mixed => preg_match($p, $s), 'hinted' => true,
        ];
        yield 'escape' => [
            'mutator' => new RegexEscape(), 'code' => "preg_match('/^v\\d+\\.\\d+\$/', \$s)", 'original' => '/^v\d+\.\d+$/',
            'call' => static fn (string $p, string $s): mixed => preg_match($p, $s), 'hinted' => true,
        ];
        yield 'preg_replace with a literal replacement' => [
            'mutator' => new RegexAlternation(), 'code' => "preg_replace('/cat|dog/', 'pet', \$s)", 'original' => '/cat|dog/',
            'call' => static fn (string $p, string $s): mixed => preg_replace($p, 'pet', $s), 'hinted' => true,
        ];
        yield 'preg_filter' => [
            'mutator' => new RegexQuantifier(), 'code' => "preg_filter('/o+/', '0', \$s)", 'original' => '/o+/',
            'call' => static fn (string $p, string $s): mixed => preg_filter($p, '0', $s), 'hinted' => true,
        ];
        yield 'preg_split' => [
            'mutator' => new RegexQuantifier(), 'code' => "preg_split('/,\\s*/', \$s)", 'original' => '/,\s*/',
            'call' => preg_split(...), 'hinted' => true,
        ];
        yield 'preg_split with limit and flags' => [
            'mutator' => new RegexQuantifier(), 'code' => "preg_split('/(,)\\s*/', \$s, 2, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY)", 'original' => '/(,)\s*/',
            'call' => static fn (string $p, string $s): mixed => preg_split($p, $s, 2, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY), 'hinted' => true,
        ];
        yield 'preg_match_all' => [
            'mutator' => new RegexQuantifier(), 'code' => "preg_match_all('/\\d+/', \$s, \$m)", 'original' => '/\d+/',
            'call' => static fn (string $p, string $s): mixed => [preg_match_all($p, $s, $m), $m], 'hinted' => true,
        ];
        yield 'preg_match_all with set order and offsets' => [
            'mutator' => new RegexAnchor(), 'code' => "preg_match_all('/^a/', \$s, \$m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)", 'original' => '/^a/',
            'call' => static fn (string $p, string $s): mixed => [preg_match_all($p, $s, $m, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE), $m], 'hinted' => true,
        ];
        yield 'preg_grep' => [
            'mutator' => new RegexAnchor(), 'code' => "preg_grep('/^\\d+\$/', \$list)", 'original' => '/^\d+$/',
            'call' => static fn (string $p, string $s): mixed => preg_grep($p, [$s]), 'hinted' => true,
        ];
        yield 'preg_match from an offset' => [
            'mutator' => new RegexAnchor(), 'code' => "preg_match('/^a|b\$/', \$s, \$m, 0, 2)", 'original' => '/^a|b$/',
            'call' => static fn (string $p, string $s): mixed => [preg_match($p, $s, $m, 0, 2), $m], 'hinted' => true,
        ];
        yield 'preg_replace_callback hands other matches to the callback' => [
            'mutator' => new RegexQuantifier(), 'code' => "preg_replace_callback('/\\d+/', \$callback, \$s)", 'original' => '/\d+/',
            'call' => static fn (string $p, string $s): mixed => [preg_match_all($p, $s, $m, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE), $m], 'hinted' => true,
        ];
        yield 'preg_match_all, the count alone' => [
            'mutator' => new RegexQuantifier(), 'code' => "preg_match_all('/ab?/', \$s)", 'original' => '/ab?/',
            'call' => static fn (string $p, string $s): mixed => preg_match_all($p, $s), 'hinted' => true,
        ];
        yield 'preg_replace_callback, what the callback receives' => [
            'mutator' => new RegexQuantifier(), 'code' => "preg_replace_callback('/(a*)?(b)/', \$f, \$s)", 'original' => '/(a*)?(b)/',
            'call' => static fn (string $p, string $s): mixed => preg_replace_callback($p, static fn (array $m): string => '['.json_encode($m).']', $s), 'hinted' => false,
        ];
        yield 'outside the solver, the fallback' => [
            'mutator' => new RegexQuantifier(), 'code' => "preg_match('/\\bfo+/', \$s, \$m)", 'original' => '/\bfo+/',
            'call' => static fn (string $p, string $s): mixed => [preg_match($p, $s, $m), $m], 'hinted' => false,
        ];
        yield 'backreference, the fallback' => [
            'mutator' => new RegexQuantifier(), 'code' => "preg_match('/(a)\\1+/', \$s, \$m)", 'original' => '/(a)\1+/',
            'call' => static fn (string $p, string $s): mixed => [preg_match($p, $s, $m), $m], 'hinted' => false,
        ];
    }

    #[Test]
    #[DataProvider('provideUnevaluableCalls')]
    public function test_a_call_whose_arguments_cannot_be_read_gets_no_hint(string $code): void
    {
        $patterns = MutatorHarness::patterns(new RegexQuantifier(), $code);

        $this->assertNotSame([], $patterns);
        foreach ($patterns as $pattern) {
            $this->assertNull(MutatorHarness::split($pattern)[1], $pattern);
        }
    }

    /**
     * @return iterable<string, array{code: string}>
     */
    public static function provideUnevaluableCalls(): iterable
    {
        yield 'offset from a variable' => ['code' => "preg_match('/a+/', \$s, \$m, 0, \$offset)"];
        yield 'flags from a variable' => ['code' => "preg_match_all('/a+/', \$s, \$m, \$flags)"];
        yield 'callback flags from a variable' => ['code' => "preg_replace_callback('/a+/', \$f, \$s, -1, \$count, \$flags)"];
        yield 'replacement from a variable' => ['code' => "preg_replace('/a+/', \$replacement, \$s)"];
        yield 'limit from a variable' => ['code' => "preg_split('/,+/', \$s, \$limit)"];
        yield 'flags from a constant that is no PREG_ flag' => ['code' => "preg_match_all('/a+/', \$s, \$m, E_ALL)"];
        yield 'flags from a sum with a variable' => ['code' => "preg_match_all('/a+/', \$s, \$m, PREG_SET_ORDER | \$extra)"];
        yield 'flag constant of another namespace' => ['code' => "<?php\nnamespace App;\nuse const Lib\\PREG_SET_ORDER;\nfunction f(\$s) { return preg_match_all('/a+/', \$s, \$m, PREG_SET_ORDER); }\n"];
    }

    /**
     * @param Mutator<Node> $mutator
     * @param list<?string> $expected
     */
    #[Test]
    #[DataProvider('provideKillStrings')]
    public function test_kill_strings_are_the_ones_the_call_needs(Mutator $mutator, string $code, array $expected): void
    {
        $this->assertSame($expected, array_map(static fn (string $pattern): ?string => MutatorHarness::split($pattern)[1], MutatorHarness::patterns($mutator, $code)));
    }

    /**
     * @return iterable<string, array{mutator: Mutator<Node>, code: string, expected: list<?string>}>
     */
    public static function provideKillStrings(): iterable
    {
        // Searched from offset 2: the subject starts two bytes ahead.
        yield 'from an offset' => ['mutator' => new RegexAnchor(), 'code' => "preg_match('/^a|b\$/', \$s, \$m, 0, 2)", 'expected' => ['!!!a', '!!b!', "!!b\n"]];
        // A backreference keeps the automata out: the sample generator
        // finds what tells the repeats apart.
        yield 'from the sample generator' => ['mutator' => new RegexQuantifier(), 'code' => "preg_match('/(a)\\1+/', \$s, \$m)", 'expected' => [null, 'aaaa', 'aaaa']];
        // From two bytes before the end: only the whole subject "ab" lets
        // "^" hold there, so "$" and "\z" can be told from nothing.
        yield 'from a negative offset' => ['mutator' => new RegexAnchor(), 'code' => "preg_match('/^ab\$/', \$s, \$m, 0, -2)", 'expected' => ['!ab', null, null]];
    }

    #[Test]
    public function test_unprovable_mutants_are_emitted(): void
    {
        $this->assertSame(['/(a)\1*/', '/(a)\1/', '/(a)\1+?/'], MutatorHarness::bare(new RegexQuantifier(), "preg_match('/(a)\\1+/', \$s, \$m)"));
        $this->assertContains('/^ab/m', MutatorHarness::bare(new RegexFlag(), "preg_match('/^ab/mi', \$s)"));
    }

    #[Test]
    public function test_the_mutants_and_their_hints_are_the_same_on_every_run(): void
    {
        foreach (["preg_match('/\\bfo+[a-z]*/', \$s, \$m)", "preg_match('/^(\\w+)-\\1\$/', \$s)", "preg_replace('/[aeiou]{2,}/i', '_', \$s)"] as $code) {
            $first = MutatorHarness::patterns(new RegexQuantifier(), $code);

            $this->assertSame($first, MutatorHarness::patterns(new RegexQuantifier(), $code), $code);
            $this->assertSame($first, MutatorHarness::patterns(new RegexQuantifier(), $code), $code);
        }
    }

    #[Test]
    public function test_a_spent_budget_emits_every_candidate_unproven_and_without_hint(): void
    {
        $call = PatternCall::fromNode(MutatorHarness::call("preg_match('/a+/', \$s)") ?? throw new \LogicException());
        $this->assertInstanceOf(PatternCall::class, $call);

        $this->assertSame(['/a*/', '/a/', '/a+?/'], (new MutationPipeline(Family::Quantifier, 0))->mutants($call));

        $hinted = (new MutationPipeline(Family::Quantifier))->mutants($call);
        $this->assertCount(1, $hinted);
        $this->assertSame('/a*/', MutatorHarness::split($hinted[0])[0]);
        $this->assertNotNull(MutatorHarness::split($hinted[0])[1]);
    }

    #[Test]
    public function test_redos_worse_mutant_is_dropped(): void
    {
        // [^a] turned into [a] makes (a+a)+, which backtracks without end
        // where the original cannot.
        $this->assertSame([], MutatorHarness::bare(new RegexCharClass(), "preg_match('/^(?:a+[^a])+\$/', \$s)"));
        $this->assertSame([1, false], self::underTheReplayLimit('/^(?:a+[^a])+$/', '/^(?:a+[a])+$/', str_repeat('a', 25).'!'));
    }

    #[Test]
    public function test_a_node_keeps_its_mutants_past_one_redos_worse(): void
    {
        // [a-c] and [^b-c] both let a+ meet another "a"; [^a-b] does not,
        // and "ac" kills it.
        $this->assertSame(['/^(?:a+[^a-b])+$/'], MutatorHarness::bare(new RegexCharClass(), "preg_match('/^(?:a+[^a-c])+\$/', \$s)"));
        $this->assertSame([1, false], self::underTheReplayLimit('/^(?:a+[^a-c])+$/', '/^(?:a+[a-c])+$/', str_repeat('a', 25).'!'));
        $this->assertSame([0, false], self::underTheReplayLimit('/^(?:a+[^a-c])+$/', '/^(?:a+[^b-c])+$/', str_repeat('a', 25).'b'));
        $this->assertSame([0, 1], self::underTheReplayLimit('/^(?:a+[^a-c])+$/', '/^(?:a+[^a-b])+$/', 'ac'));
    }

    #[Test]
    public function test_a_mutant_rated_polynomial_is_kept(): void
    {
        // \D* overlaps a* where \d* does not: a cubic rating, no timeout on
        // any subject a test passes, and "xb" kills it.
        $this->assertContains('/^a*a*\D*b$/', MutatorHarness::bare(new RegexCharClass(), "preg_match('/^a*a*\\d*b\$/', \$s, \$m)"));
        $this->assertSame([0, 0], self::underTheReplayLimit('/^a*a*\d*b$/', '/^a*a*\D*b$/', str_repeat('a', 60).'!'));
        $this->assertSame([0, 1], self::underTheReplayLimit('/^a*a*\d*b$/', '/^a*a*\D*b$/', 'xb'));
    }

    #[Test]
    public function test_kill_strings_prefer_printable_bytes(): void
    {
        // The shortest subject the automata find may hold a NUL where any
        // byte but a digit would do: a printable one is written instead.
        $this->assertSame(['v0!0'], array_map(static fn (string $pattern): ?string => MutatorHarness::split($pattern)[1], MutatorHarness::patterns(new RegexEscape(), "preg_match('/^v\\d+\\.\\d+\$/', \$s)")));
        // A printable byte stays as found, a space as well.
        $this->assertSame(['! x0', ' x0!', " x0\n"], array_map(static fn (string $pattern): ?string => MutatorHarness::split($pattern)[1], MutatorHarness::patterns(new RegexAnchor(), "preg_match('/^[ -~]x\\d\$/', \$s)")));
        // A newline that tells $ from \z stays.
        $this->assertContains("0\n", array_map(static fn (string $pattern): ?string => MutatorHarness::split($pattern)[1], MutatorHarness::patterns(new RegexAnchor(), "preg_match('/^\\d+\$/', \$s)")));
    }

    #[Test]
    public function test_redos_guard_with_a_spent_budget_emits_the_mutant(): void
    {
        $call = PatternCall::fromNode(MutatorHarness::call("preg_match('/^(?:a+[^a])+\$/', \$s)") ?? throw new \LogicException());
        $this->assertInstanceOf(PatternCall::class, $call);

        $this->assertSame(['/^(?:a+[a])+$/'], (new MutationPipeline(Family::CharClass, 0))->mutants($call));
    }

    #[Test]
    public function test_a_node_keeps_its_mutants_past_one_alike_the_original(): void
    {
        // Dropping the first or the second "a" changes nothing; dropping
        // "b" does.
        $this->assertSame(['/^(?:a|a)$/'], MutatorHarness::bare(new RegexAlternation(), "preg_match('/^(?:a|a|b)\$/', \$s)"));
        self::assertTheEngineAgrees(Family::Alternation, '/^(?:a|a|b)$/', ['/^(?:a|a)$/'], static fn (string $p, string $s): mixed => preg_match($p, $s), 'ab!');
    }

    #[Test]
    public function test_flag_mutator_never_removes_u(): void
    {
        $this->assertSame(['/a/u'], MutatorHarness::bare(new RegexFlag(), "preg_match('/a/iu', \$s, \$m)"));
        $this->assertSame([], MutatorHarness::bare(new RegexFlag(), "preg_match('/a/u', \$s, \$m)"));
    }

    #[Test]
    public function test_uncompilable_mutant_is_never_emitted(): void
    {
        // "*a" repeats nothing: the engine refuses it.
        $this->assertSame([], MutatorHarness::bare(new RegexEscape(), "preg_match('/\\*a/', \$s, \$m)"));
    }

    #[Test]
    public function test_compile_probe_leaves_no_error_state(): void
    {
        error_clear_last();
        MutatorHarness::patterns(new RegexEscape(), "preg_match('/\\*a/', \$s, \$m)");
        MutatorHarness::patterns(new RegexQuantifier(), "preg_match('/^(\\w+)-\\1\$/', \$s)");

        $this->assertNull(error_get_last());
        $this->assertSame(\PREG_NO_ERROR, preg_last_error());
    }

    #[Test]
    public function test_mutually_equivalent_mutants_are_emitted_once_per_mutator(): void
    {
        // a+a+ and a+a both take two a or more: the second adds nothing.
        // aa* and the lazy forms answer as the original does.
        $this->assertSame(['/^a*a*$/', '/^a+a+$/'], MutatorHarness::bare(new RegexQuantifier(), "preg_match('/^a+a*\$/', \$s)"));
        self::assertTheEngineAgrees(Family::Quantifier, '/^a+a*$/', ['/^a*a*$/', '/^a+a+$/'], static fn (string $p, string $s): mixed => preg_match($p, $s), 'ab!');
    }

    /**
     * @param Mutator<Node> $mutator
     * @param list<string>  $expected
     */
    #[Test]
    #[DataProvider('provideStableOrders')]
    public function test_mutant_order_is_stable(Mutator $mutator, string $code, array $expected): void
    {
        $this->assertSame($expected, MutatorHarness::bare($mutator, $code));
    }

    /**
     * @return iterable<string, array{mutator: Mutator<Node>, code: string, expected: list<string>}>
     */
    public static function provideStableOrders(): iterable
    {
        yield 'anchors' => ['mutator' => new RegexAnchor(), 'code' => "preg_match('/^ab\$/', \$s, \$m)", 'expected' => ['/ab$/', '/^ab/', '/^ab\z/']];
        yield 'classes' => ['mutator' => new RegexCharClass(), 'code' => "preg_match('/[a-c]x/', \$s, \$m)", 'expected' => ['/[^a-c]x/', '/[b-c]x/', '/[a-b]x/']];
        yield 'alternation' => ['mutator' => new RegexAlternation(), 'code' => "preg_replace('/cat|dog/', 'pet', \$s)", 'expected' => ['/dog/', '/cat/']];
        yield 'flags' => ['mutator' => new RegexFlag(), 'code' => "preg_match('/^a.c\$/is', \$s)", 'expected' => ['/^a.c$/s', '/^a.c$/i']];
        yield 'escapes' => ['mutator' => new RegexEscape(), 'code' => "preg_match('/^v\\d+\\.\\d+\$/', \$s)", 'expected' => ['/^v\d+.\d+$/']];
    }

    #[Test]
    #[DataProvider('provideStringKinds')]
    public function test_mutated_node_preserves_string_kind(string $file, int $kind): void
    {
        $printed = MutatorHarness::printFirstMutant(new RegexAnchor(), $file);

        $statements = (new ParserFactory())->createForHostVersion()->parse($printed) ?? [];
        $literal = (new NodeFinder())->findFirstInstanceOf($statements, String_::class);

        $this->assertInstanceOf(String_::class, $literal, $printed);
        $this->assertSame($kind, $literal->getAttribute('kind'), $printed);
        $this->assertSame('/a/', MutatorHarness::split($literal->value)[0], $printed);
        $this->assertNotNull(MutatorHarness::split($literal->value)[1], $printed);
        $this->assertStringContainsString("// keep me\n", $printed);
    }

    /**
     * @return iterable<string, array{file: string, kind: int}>
     */
    public static function provideStringKinds(): iterable
    {
        $file = static fn (string $literal): string => "<?php\nfunction f(\$s) {\n    // keep me\n    return preg_match(".$literal.", \$s, \$m);\n}\n";

        yield 'single-quoted' => ['file' => $file("'/^a/'"), 'kind' => String_::KIND_SINGLE_QUOTED];
        yield 'double-quoted' => ['file' => $file('"/^a/"'), 'kind' => String_::KIND_DOUBLE_QUOTED];
        yield 'nowdoc' => ['file' => $file("<<<'RE'\n    /^a/\n    RE"), 'kind' => String_::KIND_NOWDOC];
        yield 'heredoc' => ['file' => $file("<<<RE\n    /^a/\n    RE"), 'kind' => String_::KIND_HEREDOC];
    }

    #[Test]
    public function test_families_are_disjoint(): void
    {
        /** @var list<string> $patterns */
        $patterns = require __DIR__.'/../../../Fixtures/Infection/corpus-patterns.php';
        $generator = new CandidateGenerator();

        $owner = [];
        $shared = [];
        foreach ($patterns as $pattern) {
            foreach (Family::cases() as $family) {
                foreach ($generator->candidates($family, $pattern) as $candidate) {
                    if (isset($owner[$candidate]) && $owner[$candidate] !== $family) {
                        $shared[] = $pattern.' → '.$candidate;
                    }
                    $owner[$candidate] = $family;
                }
            }
        }

        $this->assertSame([], $shared);
    }

    /**
     * Every candidate the mutator dropped answers, on the engine, as the
     * original or a mutant kept before it does on every subject up to four
     * bytes of the alphabet; every mutant kept answers otherwise than the
     * original on one of them.
     *
     * @param list<string>                    $kept
     * @param \Closure(string, string): mixed $call
     */
    private static function assertTheEngineAgrees(Family $family, string $original, array $kept, \Closure $call, string $alphabet): void
    {
        $subjects = [''];
        $longest = [''];
        for ($length = 1; $length <= 4; $length++) {
            $longer = [];
            foreach ($longest as $prefix) {
                foreach (str_split($alphabet) as $byte) {
                    $longer[] = $prefix.$byte;
                }
            }
            array_push($subjects, ...$longer);
            $longest = $longer;
        }

        $answers = static fn (string $pattern): array => array_map(static fn (string $subject): string => serialize($call($pattern, $subject)), $subjects);
        $originalAnswers = $answers($original);

        $seen = [$originalAnswers];
        foreach ((new CandidateGenerator())->candidates($family, $original) as $candidate) {
            $candidateAnswers = $answers($candidate);
            if (\in_array($candidate, $kept, true)) {
                self::assertNotSame($originalAnswers, $candidateAnswers, $candidate.' answers as '.$original.' does');
                $seen[] = $candidateAnswers;

                continue;
            }

            self::assertContains($candidateAnswers, $seen, $candidate.' was dropped, yet answers otherwise');
        }
    }

    /**
     * What preg_match() answers for each pattern under the backtrack limit
     * the kill strings are checked with, the JIT off as the engine runs it.
     *
     * @return list<false|int>
     */
    private static function underTheReplayLimit(string $original, string $mutant, string $subject): array
    {
        $limit = (string) ini_get('pcre.backtrack_limit');
        $jit = (string) ini_get('pcre.jit');
        ini_set('pcre.backtrack_limit', '100000');
        ini_set('pcre.jit', '0');

        try {
            return [@preg_match($original, $subject), @preg_match($mutant, $subject)];
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
            ini_set('pcre.jit', $jit);
        }
    }

    /**
     * @return array<array-key, string>
     */
    private static function capturedBy(string $pattern, string $subject): array
    {
        preg_match($pattern, $subject, $matches);

        return $matches;
    }
}
