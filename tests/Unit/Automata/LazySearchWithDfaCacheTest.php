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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Options\SolverStrategy;
use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Automata\Solver\InMemoryDfaCache;
use PHPRegex\Automata\Solver\KeptNfas;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A DFA cache keeps what the solver compiles; it does not choose how the
 * solver searches. The lazy search runs first with a cache as without one,
 * and the eager search, when the lazy one gives up, reads and fills the
 * cache. A cache used to force the eager search: the sixteen positions
 * "/^(a|b)*a(a|b){15}$/" remembers ran its DFA past the state limit, where
 * the solver without a cache answers at once.
 *
 * A caller brings a cache to ask many questions about the same patterns, a
 * route against every other route: the NFAs the lazy search reads are kept
 * beside the cache, so that each pattern is built once.
 */
final class LazySearchWithDfaCacheTest extends TestCase
{
    use PhpUnitForwardCompat;

    private const PATTERNS = [
        '/a/', '/b/', '/ab/', '/a*/', '/a+/', '/a{0,2}/', '/(a|b)/', '/[ab]+/', '/a*ab/', '/(a|b)*a(a|b){2}/',
        '/^a|b$/', '/x\d+y/', '/(?=a)\w+/', '/\bab\b/', '/[^a]+/', '/^$/', '/k/i', '/^\w+$/u', '/é+/u', '/[\x80-\xFF]/',
    ];

    #[Test]
    public function test_a_cache_does_not_force_the_eager_search(): void
    {
        $left = '/^(a|b)*a(a|b){15}$/';
        $right = '/^b+$/';

        // The engine first: the left pattern needs an "a", the right one
        // takes none.
        foreach (['b', 'bb', str_repeat('b', 20)] as $subject) {
            $this->assertSame(0, preg_match($left, $subject));
            $this->assertSame(1, preg_match($right, $subject));
        }
        $this->assertSame(1, preg_match($left, str_repeat('a', 16)));
        $this->assertSame(0, preg_match($right, str_repeat('a', 16)));

        $cache = self::recordingCache();

        $this->assertTrue((new LanguageSolver())->intersection($left, $right)->isEmpty);
        $this->assertTrue((new LanguageSolver(null, $cache))->intersection($left, $right)->isEmpty);
        $this->assertTrue((new LanguageSolver(null, $cache))->subsetOf($right, '/^(?:(a|b)*a(a|b){15}|b+)$/')->isSubset);

        // The lazy search compiles no DFA: the cache is neither read nor
        // written.
        $this->assertSame([], $cache->calls);
    }

    /**
     * The search the cache forced is still there for who asks for it: its
     * DFA of 2^16 states passes the limit.
     */
    #[Test]
    public function test_the_eager_strategy_still_determinizes_with_a_cache(): void
    {
        $this->assertSame(0, preg_match('/^(a|b)*a(a|b){15}$/', str_repeat('b', 20)));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('DFA state limit exceeded (10000).');

        (new LanguageSolver(null, new InMemoryDfaCache()))->intersection('/^(a|b)*a(a|b){15}$/', '/^b+$/', new SolverOptions(strategy: SolverStrategy::Eager));
    }

    /**
     * @param 'intersection'|'subsetOf'|'equivalent' $method
     */
    #[Test]
    #[DataProvider('provideQuestions')]
    public function test_the_answers_with_a_cache_are_those_without_one(string $method, MatchMode $mode): void
    {
        $parser = RegexParser::create();
        $plain = new LanguageSolver($parser);
        $cached = new LanguageSolver($parser, new InMemoryDfaCache());
        $options = new SolverOptions(matchMode: $mode);
        $compared = 0;

        foreach (self::PATTERNS as $left) {
            foreach (self::PATTERNS as $right) {
                $context = \sprintf('%s(%s, %s) under %s', $method, $left, $right, $mode->name);

                // Asked twice of the solver that keeps what it compiled: the
                // second answer is the first.
                $expected = self::answer($plain, $method, $left, $right, $options);
                $this->assertSame($expected, self::answer($cached, $method, $left, $right, $options), $context);
                $this->assertSame($expected, self::answer($cached, $method, $left, $right, $options), $context.', asked again');
                $compared++;
            }
        }

        $this->assertSame(400, $compared);
    }

    /**
     * @return iterable<string, array{method: string, mode: MatchMode}>
     */
    public static function provideQuestions(): iterable
    {
        foreach (['intersection', 'subsetOf', 'equivalent'] as $method) {
            yield $method.', full' => ['method' => $method, 'mode' => MatchMode::Full];
            yield $method.', partial' => ['method' => $method, 'mode' => MatchMode::Partial];
        }
    }

    /**
     * Seven hundred branches start one pair each, past a limit of a hundred
     * pairs: the lazy search gives up, and the eager one compiles both
     * patterns into the cache, three states for the branches. Asked again,
     * it reads them back.
     */
    #[Test]
    public function test_past_its_limits_the_eager_search_answers_from_the_cache(): void
    {
        $body = implode('|', array_fill(0, 700, 'a'));
        $branches = '/(?:'.$body.')/';
        $this->assertSame(1, preg_match('/^(?:'.$body.')$/', 'a'));
        $this->assertSame(0, preg_match('/^(?:'.$body.')$/', 'aa'));
        $this->assertSame(0, preg_match('/^(?:'.$body.')$/', 'b'));

        $cache = self::recordingCache();
        $solver = new LanguageSolver(null, $cache);
        $narrow = new SolverOptions(maxDfaStates: 100);

        $this->assertTrue($solver->subsetOf($branches, '/a/', $narrow)->isSubset);
        $this->assertSame(['miss', 'set', 'miss', 'set'], $cache->calls);

        $this->assertTrue($solver->subsetOf($branches, '/a/', $narrow)->isSubset);
        $this->assertSame(['miss', 'set', 'miss', 'set', 'hit', 'hit'], $cache->calls);

        $this->assertFalse($solver->subsetOf($branches, '/b/', $narrow)->isSubset);
        $this->assertSame(['miss', 'set', 'miss', 'set', 'hit', 'hit', 'hit', 'miss', 'set'], $cache->calls);
    }

    /**
     * A pattern read as UTF-8 beside one read as bytes is compared on the
     * UTF-8 of each character, which the eager search alone reads: its DFAs
     * go to the cache as before.
     */
    #[Test]
    public function test_a_pattern_read_as_utf8_beside_one_read_as_bytes_is_compiled_into_the_cache(): void
    {
        $this->assertSame(1, preg_match('/^é$/u', 'é'));
        $this->assertSame(1, preg_match('/^\xC3\xA9$/', 'é'));

        $cache = self::recordingCache();
        $result = (new LanguageSolver(null, $cache))->equivalent('/é/u', '/\xC3\xA9/');

        $this->assertTrue($result->isEquivalent);
        $this->assertSame(['miss', 'set', 'miss', 'set'], $cache->calls);
    }

    /**
     * Beside the cache, the NFA of each pattern is kept under the key its
     * DFA would take, the match mode and the limits in it: a second question
     * reads the automaton the first one built.
     */
    #[Test]
    public function test_the_nfa_of_a_pattern_is_built_once_beside_the_cache(): void
    {
        $this->assertSame(1, preg_match('/^a+$/', 'aa'));
        $this->assertSame(0, preg_match('/^b$/', 'aa'));

        $parser = RegexParser::create();
        $cache = new InMemoryDfaCache();
        $solver = new LanguageSolver($parser, $cache);
        $full = new SolverOptions();
        $partial = new SolverOptions(matchMode: MatchMode::Partial);
        $kept = static fn (string $pattern, SolverOptions $options): ?Nfa => self::kept($cache, $parser, $pattern, $options);

        $this->assertNull($kept('/a+/', $full));

        $this->assertTrue($solver->intersection('/a+/', '/b/', $full)->isEmpty);
        $built = $kept('/a+/', $full);
        $this->assertInstanceOf(Nfa::class, $built);
        $this->assertInstanceOf(Nfa::class, $kept('/b/', $full));
        $this->assertNull($kept('/a+/', $partial), 'A pattern read under another mode is another automaton.');

        // Another question, another solver on the same cache: the same NFA.
        $this->assertSame('aa', (new LanguageSolver($parser, $cache))->subsetOf('/a+/', '/a/', $full)->counterExample);
        $this->assertSame($built, $kept('/a+/', $full));

        $this->assertFalse($solver->intersection('/a+/', '/ba/', $partial)->isEmpty);
        $this->assertInstanceOf(Nfa::class, $kept('/a+/', $partial));
        $this->assertNotSame($built, $kept('/a+/', $partial));
        $this->assertSame($built, $kept('/a+/', $full));

        // A pattern beside itself is read once.
        $this->assertTrue($solver->equivalent('/c/', '/c/', $full)->isEquivalent);
        $this->assertInstanceOf(Nfa::class, $kept('/c/', $full));
    }

    /**
     * What is kept is what is read: an automaton planted under the key of
     * a pattern answers for it, as a DFA planted in the cache would.
     */
    #[Test]
    public function test_the_lazy_search_reads_the_nfa_kept_for_a_pattern(): void
    {
        $this->assertSame(0, preg_match('/^a$/', 'b'));

        $parser = RegexParser::create();
        $cache = new InMemoryDfaCache();
        $options = new SolverOptions();
        $plain = new LanguageSolver($parser);
        $this->assertTrue($plain->intersection('/a/', '/b/', $options)->isEmpty);

        KeptNfas::beside($cache)->set(
            LanguageSolver::dfaCacheKey('/a/', $parser->target()->cacheKey(), $options, \PCRE_VERSION),
            $plain->nfa('/b/', $options),
        );

        $this->assertSame('b', (new LanguageSolver($parser, $cache))->intersection('/a/', '/b/', $options)->example);
        $this->assertTrue($plain->intersection('/a/', '/b/', $options)->isEmpty, 'A solver without a cache keeps nothing.');
    }

    /**
     * A pattern the solver refuses leaves nothing beside the cache.
     */
    #[Test]
    public function test_a_refused_pattern_keeps_no_nfa(): void
    {
        $parser = RegexParser::create();
        $cache = new InMemoryDfaCache();
        $options = new SolverOptions();

        try {
            (new LanguageSolver($parser, $cache))->intersection('/(a)\\1/', '/aa/', $options);
            $this->fail('A backreference was read.');
        } catch (ComplexityException) {
            $this->assertNull(KeptNfas::beside($cache)->get(LanguageSolver::dfaCacheKey('/(a)\\1/', $parser->target()->cacheKey(), $options, \PCRE_VERSION)));
        }
    }

    /**
     * The NFA kept beside the cache for the pattern, read anew at each call.
     *
     * @phpstan-impure
     */
    private static function kept(DfaCacheInterface $cache, RegexParser $parser, string $pattern, SolverOptions $options): ?Nfa
    {
        return KeptNfas::beside($cache)->get(LanguageSolver::dfaCacheKey($pattern, $parser->target()->cacheKey(), $options, \PCRE_VERSION));
    }

    /**
     * The fields of the answer, or the reason it was refused.
     *
     * @return array<mixed>|string
     */
    private static function answer(LanguageSolver $solver, string $method, string $left, string $right, SolverOptions $options): array|string
    {
        try {
            return get_object_vars(match ($method) {
                'intersection' => $solver->intersection($left, $right, $options),
                'subsetOf' => $solver->subsetOf($left, $right, $options),
                default => $solver->equivalent($left, $right, $options),
            });
        } catch (ComplexityException $exception) {
            return 'refused: '.$exception->getMessage();
        }
    }

    /**
     * A cache that keeps what it is given and says how it was asked.
     *
     * @return DfaCacheInterface&object{calls: list<string>}
     */
    private static function recordingCache(): DfaCacheInterface
    {
        return new class implements DfaCacheInterface {
            /**
             * @var list<string>
             */
            public array $calls = [];

            /**
             * @var array<string, Dfa>
             */
            private array $dfas = [];

            public function get(string $key): ?Dfa
            {
                $this->calls[] = isset($this->dfas[$key]) ? 'hit' : 'miss';

                return $this->dfas[$key] ?? null;
            }

            public function set(string $key, Dfa $dfa): void
            {
                $this->calls[] = 'set';
                $this->dfas[$key] = $dfa;
            }
        };
    }
}
