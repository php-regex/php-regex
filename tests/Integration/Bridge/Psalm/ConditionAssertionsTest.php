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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPRegex\Psalm\ConditionAssertions;
use PHPRegex\Psalm\PsalmInternals;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Analyzer\IssueData;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Provider\NodeDataProvider;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\InArray;
use Psalm\Storage\Assertion\IsNotType;
use Psalm\Storage\Assertion\IsType;
use Psalm\Storage\Assertion\NotInArray;
use Psalm\Storage\Assertion\Truthy;
use Psalm\Storage\Possibilities;
use Psalm\Type;
use Psalm\Type\Atomic\TNonEmptyString;
use Psalm\Type\Atomic\TNonFalsyString;

/**
 * From 7.11 on, Psalm reads the condition a preg_match() call stands in from
 * its cache of assertions alone: the plugin writes there what it sets on the
 * call, as Psalm would read it from the call, once Psalm is found not to read
 * it itself. Up to 7.10, Psalm reads it, and nothing more is written.
 */
final class ConditionAssertionsTest extends TestCase
{
    use PhpUnitForwardCompat;

    protected function setUp(): void
    {
        self::forget();
    }

    protected function tearDown(): void
    {
        self::forget();
    }

    /**
     * Where the call is true, the argument holds one of the rules: Psalm
     * reads a list of rules on one argument as alternatives.
     */
    #[Test]
    public function test_condition_assertions_translate_what_holds_where_the_call_is_true(): void
    {
        $shape = new IsType(PsalmTypes::parse("array{0: 'a', 1: 'a'}")->getSingleAtomic());
        $nonEmpty = new IsType(new TNonEmptyString());
        $nonFalsy = new IsType(new TNonFalsyString());

        $translated = ConditionAssertions::translate(self::call(), null, null, [new Possibilities(2, [$shape]), new Possibilities(1, [$nonEmpty, $nonFalsy])], null);

        $this->assertSame([['$m' => [[$shape]]], ['$s' => [[$nonEmpty, $nonFalsy]]]], $translated);
    }

    /**
     * Where the call is false, the argument holds none of the rules: each
     * rule's negation holds.
     */
    #[Test]
    public function test_condition_assertions_translate_what_holds_where_the_call_is_false(): void
    {
        $empty = Type::getEmptyArrayAtomic();
        $literals = PsalmTypes::parse("'a'|'b'");

        $translated = ConditionAssertions::translate(self::call(), null, null, [], [new Possibilities(2, [new IsType($empty)]), new Possibilities(1, [new InArray($literals), new IsType($empty)])]);

        $this->assertCount(2, $translated);
        $this->assertSame(['$m'], array_keys($translated[0]));
        $this->assertCount(1, $translated[0]['$m']);
        $this->assertCount(1, $translated[0]['$m'][0]);
        $notEmpty = $translated[0]['$m'][0][0];
        $this->assertInstanceOf(IsNotType::class, $notEmpty);
        $this->assertSame($empty, $notEmpty->type);

        $this->assertSame(['$s'], array_keys($translated[1]));
        $this->assertCount(2, $translated[1]['$s']);
        $this->assertCount(1, $translated[1]['$s'][0]);
        $notIn = $translated[1]['$s'][0][0];
        $this->assertInstanceOf(NotInArray::class, $notIn);
        $this->assertSame($literals, $notIn->type);
        $this->assertCount(1, $translated[1]['$s'][1]);
        $this->assertInstanceOf(IsNotType::class, $translated[1]['$s'][1][0]);
    }

    #[Test]
    public function test_condition_assertions_translate_the_true_side_before_the_false_side(): void
    {
        $shape = new IsType(new TNonEmptyString());
        $empty = new IsType(Type::getEmptyArrayAtomic());

        $translated = ConditionAssertions::translate(self::call(), null, null, [new Possibilities(2, [$shape])], [new Possibilities(2, [$empty])]);

        $this->assertSame(['non-empty-string', '!array<never, never>'], array_map(static fn (array $element): string => (string) $element['$m'][0][0], $translated));
    }

    /**
     * @return iterable<string, array{arguments: list<Arg>, index: int, expected: list<string>}>
     */
    public static function provideArguments(): iterable
    {
        yield 'variable' => ['arguments' => self::arguments(new String_('/(a)/'), new Variable('s'), new Variable('m')), 'index' => 2, 'expected' => ['$m']];
        yield 'array element' => ['arguments' => self::arguments(new String_('/(a)/'), new ArrayDimFetch(new Variable('a'), new String_('s'))), 'index' => 1, 'expected' => ["\$a['s']"]];
        yield 'named arguments, by their place in the call' => ['arguments' => [new Arg(new Variable('s'), name: new Identifier('subject')), new Arg(new Variable('m'), name: new Identifier('matches')), new Arg(new String_('/(a)/'), name: new Identifier('pattern'))], 'index' => 1, 'expected' => ['$m']];
        yield 'literal subject' => ['arguments' => self::arguments(new String_('/(a)/'), new String_('abc'), new Variable('m')), 'index' => 1, 'expected' => []];
        yield 'call as subject' => ['arguments' => self::arguments(new String_('/(a)/'), new FuncCall(new Name('trim'), self::arguments(new Variable('s')))), 'index' => 1, 'expected' => []];
        yield 'variable variable' => ['arguments' => self::arguments(new String_('/(a)/'), new Variable(new Variable('n'))), 'index' => 1, 'expected' => []];
    }

    /**
     * @param list<Arg>    $arguments
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideArguments')]
    public function test_condition_assertions_name_the_argument_by_its_variable(array $arguments, int $index, array $expected): void
    {
        $call = new FuncCall(new Name('preg_match'), $arguments);
        $rule = new IsType(new TNonEmptyString());

        $ifTrue = ConditionAssertions::translate($call, null, null, [new Possibilities($index, [$rule])], null);
        $ifFalse = ConditionAssertions::translate($call, null, null, [], [new Possibilities($index, [$rule])]);

        $this->assertSame($expected, array_merge(...array_map(array_keys(...), $ifTrue)));
        $this->assertSame($expected, array_merge(...array_map(array_keys(...), $ifFalse)));
    }

    /**
     * @return iterable<string, array{scraped: list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>, ours: list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>, expected: bool}>
     */
    public static function provideScrapedAssertions(): iterable
    {
        $nonEmpty = new IsType(new TNonEmptyString());
        $nonFalsy = new IsType(new TNonFalsyString());
        $truthy = new Truthy();

        yield 'ours among more' => ['scraped' => [['$m' => [[$nonEmpty]]], ['$s' => [[$truthy]]]], 'ours' => [['$m' => [[$nonEmpty]]]], 'expected' => true];
        yield 'ours in another order' => ['scraped' => [['$s' => [[$truthy]]], ['$m' => [[$nonEmpty]]]], 'ours' => [['$m' => [[$nonEmpty]]], ['$s' => [[$truthy]]]], 'expected' => true];
        yield 'ours split across elements' => ['scraped' => [['$m' => [[$nonEmpty]]], ['$s' => [[$truthy]]]], 'ours' => [['$s' => [[$truthy]], '$m' => [[$nonEmpty]]]], 'expected' => true];
        yield 'equal rules of other objects' => ['scraped' => [['$m' => [[new IsType(new TNonEmptyString())]]]], 'ours' => [['$m' => [[$nonEmpty]]]], 'expected' => true];
        yield 'nothing of ours' => ['scraped' => [['$s' => [[$truthy]]]], 'ours' => [], 'expected' => true];
        yield 'nothing scraped' => ['scraped' => [], 'ours' => [['$m' => [[$nonEmpty]]]], 'expected' => false];
        yield 'only the taint Psalm reads' => ['scraped' => [['$s' => [[$truthy]]]], 'ours' => [['$m' => [[$nonEmpty]]]], 'expected' => false];
        yield 'another rule on the same variable' => ['scraped' => [['$m' => [[$nonFalsy]]]], 'ours' => [['$m' => [[$nonEmpty]]]], 'expected' => false];
        yield 'one of ours missing' => ['scraped' => [['$m' => [[$nonEmpty]]]], 'ours' => [['$m' => [[$nonEmpty]]], ['$s' => [[$truthy]]]], 'expected' => false];
    }

    /**
     * @param list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>> $scraped
     * @param list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>> $ours
     */
    #[Test]
    #[DataProvider('provideScrapedAssertions')]
    public function test_condition_assertions_hold_where_psalm_read_every_one_of_ours(array $scraped, array $ours, bool $expected): void
    {
        $this->assertSame($expected, ConditionAssertions::holds($scraped, $ours));
    }

    #[Test]
    public function test_condition_assertions_cache_on_the_installed_psalm(): void
    {
        $this->assertTrue(PsalmInternals::cachesAssertions());
    }

    /**
     * An internal the cache needs that a release moves stops the cache
     * alone: the callbacks are still typed, and a Psalm that reads the
     * call's assertions itself still reads them.
     */
    #[Test]
    public function test_condition_assertions_cache_off_leave_the_other_internals_on(): void
    {
        self::forceCaching(false);

        $this->assertFalse(PsalmInternals::cachesAssertions());
        $this->assertTrue(PsalmInternals::available());
    }

    #[Test]
    public function test_condition_assertions_cache_off_leave_the_condition_to_what_psalm_reads(): void
    {
        self::forceCaching(false);

        $trace = self::trace(PsalmRun::issues(['ConditionAssertions/cache_off.php']));

        // Up to 7.10 Psalm reads the call's assertions itself, whatever the cache.
        $this->assertSame(PsalmRun::readsPregMatchConditionFromCacheOnly() ? '$m: array<array-key, string>' : "\$m: array{0: 'a', 1: 'a'}", $trace);
    }

    /**
     * Whether Psalm reads the call's assertions itself is asked of the first
     * call the plugin types, and kept for the process.
     */
    #[Test]
    public function test_condition_assertions_ask_once_whether_psalm_reads_them(): void
    {
        $this->assertNull(self::psalmReadsThem());

        $trace = self::trace(PsalmRun::issues(['ConditionAssertions/memo.php']));

        $this->assertSame("\$m: array{0: 'a', 1: 'a'}", $trace);
        $this->assertSame(!PsalmRun::readsPregMatchConditionFromCacheOnly(), self::psalmReadsThem());
    }

    /**
     * Once Psalm is known to read the call's assertions itself, as up to
     * 7.10, nothing is written for any later call.
     */
    #[Test]
    public function test_condition_assertions_cache_nothing_once_psalm_reads_them(): void
    {
        $analyzer = self::analyzer();
        $call = self::call();
        (new \ReflectionProperty(ConditionAssertions::class, 'psalmReadsThem'))->setValue(null, true);

        ConditionAssertions::cache($analyzer, null, $call, [new Possibilities(2, [new IsType(new TNonEmptyString())])], null);

        $this->assertNull($analyzer->node_data->getAssertions($call));
    }

    /**
     * Where what Psalm reads of the call lacks the plugin's assertions, they
     * are written, and Psalm is known not to read them.
     */
    #[Test]
    public function test_condition_assertions_cache_what_psalm_does_not_read(): void
    {
        $analyzer = self::analyzer();
        $call = self::call();

        ConditionAssertions::cache($analyzer, null, $call, [new Possibilities(2, [new IsType(new TNonEmptyString())])], null);

        $cached = $analyzer->node_data->getAssertions($call);
        $this->assertNotNull($cached);
        $this->assertSame(['$m'], array_keys($cached[0]));
        $this->assertSame('non-empty-string', (string) $cached[0]['$m'][0][0]);
        $this->assertFalse(self::psalmReadsThem());
    }

    #[Test]
    public function test_condition_assertions_cache_off_read_nothing_of_psalm(): void
    {
        $analyzer = self::analyzer();
        $call = self::call();
        $analyzer->node_data->setAssertions($call, [['$m' => [[new Truthy()]]]]);
        self::forceCaching(false);

        $this->assertNull(PsalmInternals::scrapedAssertions($analyzer, $call, null));
        $this->assertNull(PsalmInternals::cachedAssertions($analyzer, $call));
        $this->assertNull(PsalmInternals::variableId(new Variable('m'), null, null));
        $other = self::call();
        PsalmInternals::cacheAssertions($analyzer, $other, [['$m' => [[new Truthy()]]]]);
        $this->assertNull($analyzer->node_data->getAssertions($other));
    }

    #[Test]
    public function test_condition_assertions_guarded_call_returns_its_result(): void
    {
        $this->assertSame(['$m'], PsalmInternals::guarded(static fn (): array => ['$m']));
    }

    /**
     * @return iterable<string, array{error: \TypeError}>
     */
    public static function provideSignatureErrors(): iterable
    {
        yield 'type error' => ['error' => new \TypeError('Argument #1 must be of type string')];
        yield 'argument count error' => ['error' => new \ArgumentCountError('Too few arguments')];
    }

    /**
     * A release that changes the signature of an internal the cache calls,
     * which method_exists() does not see, leaves the call to Psalm.
     */
    #[Test]
    #[DataProvider('provideSignatureErrors')]
    public function test_condition_assertions_guarded_call_gives_null_on_a_changed_signature(\TypeError $error): void
    {
        $this->assertNull(PsalmInternals::guarded(static function () use ($error): never {
            throw $error;
        }));
    }

    /**
     * @return iterable<string, array{error: \Throwable}>
     */
    public static function provideOtherErrors(): iterable
    {
        yield 'runtime exception' => ['error' => new \RuntimeException('Psalm failed')];
        yield 'error' => ['error' => new \Error('Call to undefined method')];
    }

    #[Test]
    #[DataProvider('provideOtherErrors')]
    public function test_condition_assertions_guarded_call_lets_any_other_error_through(\Throwable $error): void
    {
        $this->expectException($error::class);
        $this->expectExceptionMessageIsOrContains($error->getMessage());

        PsalmInternals::guarded(static function () use ($error): never {
            throw $error;
        });
    }

    /**
     * preg_match('/(a)/', $s, $m).
     */
    private static function call(): FuncCall
    {
        return new FuncCall(new Name('preg_match'), self::arguments(new String_('/(a)/'), new Variable('s'), new Variable('m')));
    }

    /**
     * @return list<Arg>
     */
    private static function arguments(Expr ...$values): array
    {
        return array_values(array_map(static fn (Expr $value): Arg => new Arg($value), $values));
    }

    /**
     * The one trace of $m the run reported.
     *
     * @param list<IssueData> $issues
     */
    private static function trace(array $issues): string
    {
        $traces = array_values(array_filter($issues, static fn (IssueData $issue): bool => 'Trace' === $issue->type && str_starts_with($issue->message, '$m: ')));
        self::assertCount(1, $traces);
        self::assertSame([], array_map(PsalmRun::describe(...), array_values(array_filter($issues, static fn (IssueData $issue): bool => 'Trace' !== $issue->type))));

        return $traces[0]->message;
    }

    private static function analyzer(): StatementsAnalyzer
    {
        PsalmTypes::codebase();
        $file = new FileAnalyzer(ProjectAnalyzer::getInstance(), __FILE__, basename(__FILE__));
        $constructor = (new \ReflectionClass(StatementsAnalyzer::class))->getConstructor();
        self::assertNotNull($constructor);

        // Psalm 7 adds whether the statements are the file's own scope.
        return (new \ReflectionClass(StatementsAnalyzer::class))->newInstanceArgs(\array_slice([$file, new NodeDataProvider(), true], 0, $constructor->getNumberOfParameters()));
    }

    private static function psalmReadsThem(): ?bool
    {
        $value = (new \ReflectionProperty(ConditionAssertions::class, 'psalmReadsThem'))->getValue();
        if (null !== $value) {
            self::assertIsBool($value);
        }

        return $value;
    }

    private static function forceCaching(?bool $caches): void
    {
        (new \ReflectionProperty(PsalmInternals::class, 'cachesAssertions'))->setValue(null, $caches);
    }

    private static function forget(): void
    {
        self::forceCaching(null);
        (new \ReflectionProperty(ConditionAssertions::class, 'psalmReadsThem'))->setValue(null, null);
    }
}
