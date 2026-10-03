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
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether some string starting with the input is matched: the question a
 * half-typed field asks, which preg_match() cannot answer, as it says 0 for
 * "2026" against a full date.
 */
final class AcceptsPrefixTest extends TestCase
{
    #[Test]
    public function test_preg_match_cannot_tell_a_viable_prefix_from_a_dead_one(): void
    {
        $this->assertSame(0, preg_match('/^\d{4}-\d{2}-\d{2}$/', '2026'));
        $this->assertSame(0, preg_match('/^\d{4}-\d{2}-\d{2}$/', '202a'));
    }

    #[Test]
    #[DataProvider('provideFullMatchRows')]
    public function test_a_prefix_is_viable_when_some_completion_matches(string $pattern, string $input, bool $viable, ?string $completion): void
    {
        if (null !== $completion) {
            $this->assertSame(1, preg_match($pattern, $input.$completion), \sprintf('"%s" completes "%s" for %s.', $completion, $input, $pattern));
        }

        $this->assertSame($viable, (new LanguageSolver())->acceptsPrefix($pattern, $input));
    }

    #[Test]
    public function test_partial_match_mode_lets_any_input_continue_into_a_match(): void
    {
        $partial = new SolverOptions(matchMode: MatchMode::Partial);
        $solver = new LanguageSolver();

        $this->assertSame(1, preg_match('/z/', 'qqqz'));
        $this->assertTrue($solver->acceptsPrefix('/z/', 'qqq', $partial));
        $this->assertFalse($solver->acceptsPrefix('/z/', 'qqq'));
        $this->assertFalse($solver->acceptsPrefix('/^ab/', 'ax', $partial));
    }

    #[Test]
    public function test_a_pattern_outside_the_regular_subset_is_refused(): void
    {
        $this->expectException(ComplexityException::class);

        (new LanguageSolver())->acceptsPrefix('/(\w)\1/', 'a');
    }

    #[Test]
    public function test_the_dfa_is_built_once_through_the_cache(): void
    {
        $cache = new class implements DfaCacheInterface {
            /**
             * @var array<string, Dfa>
             */
            public array $entries = [];

            public int $writes = 0;

            public function get(string $key): ?Dfa
            {
                return $this->entries[$key] ?? null;
            }

            public function set(string $key, Dfa $dfa): void
            {
                $this->entries[$key] = $dfa;
                $this->writes++;
            }
        };
        $solver = new LanguageSolver(dfaCache: $cache);

        $this->assertTrue($solver->acceptsPrefix('/^ab$/', 'a'));
        $this->assertFalse($solver->acceptsPrefix('/^ab$/', 'b'));
        $this->assertTrue($solver->equivalent('/^ab$/', '/^ab$/')->isEquivalent);

        $this->assertSame(1, $cache->writes);
    }

    /**
     * @return iterable<string, array{pattern: string, input: string, viable: bool, completion: string|null}>
     */
    public static function provideFullMatchRows(): iterable
    {
        $date = '/^\d{4}-\d{2}-\d{2}$/';

        yield 'year' => ['pattern' => $date, 'input' => '2026', 'viable' => true, 'completion' => '-10-03'];
        yield 'part of the year' => ['pattern' => $date, 'input' => '202', 'viable' => true, 'completion' => '6-10-03'];
        yield 'letter in the year' => ['pattern' => $date, 'input' => '202a', 'viable' => false, 'completion' => null];
        yield 'part of the month' => ['pattern' => $date, 'input' => '2026-1', 'viable' => true, 'completion' => '0-03'];
        yield 'part of the day' => ['pattern' => $date, 'input' => '2026-10-0', 'viable' => true, 'completion' => '3'];
        yield 'complete' => ['pattern' => $date, 'input' => '2026-10-03', 'viable' => true, 'completion' => ''];
        yield 'one digit too many' => ['pattern' => $date, 'input' => '2026-10-031', 'viable' => false, 'completion' => null];
        yield 'empty input' => ['pattern' => '/^ab$/', 'input' => '', 'viable' => true, 'completion' => 'ab'];
        yield 'wrong second letter' => ['pattern' => '/^ab$/', 'input' => 'ax', 'viable' => false, 'completion' => null];
        yield 'caseless' => ['pattern' => '/^abc$/i', 'input' => 'AB', 'viable' => true, 'completion' => 'C'];
        yield 'empty language' => ['pattern' => '/[^\s\S]/', 'input' => '', 'viable' => false, 'completion' => null];
        yield 'bytes without u' => ['pattern' => '/^é$/', 'input' => "\xC3", 'viable' => true, 'completion' => "\xA9"];
        yield 'code point cut short' => ['pattern' => '/^é$/u', 'input' => "\xC3", 'viable' => true, 'completion' => "\xA9"];
        yield 'other code point' => ['pattern' => '/^é$/u', 'input' => 'è', 'viable' => false, 'completion' => null];
        yield 'lead byte of a longer sequence' => ['pattern' => '/^é$/u', 'input' => "\xE9", 'viable' => false, 'completion' => null];
        yield 'emoji cut short' => ['pattern' => '/^.$/u', 'input' => "\xF0\x9F", 'viable' => true, 'completion' => "\x98\x80"];
        yield 'lead byte whose range misses' => ['pattern' => '/^\x{80}$/u', 'input' => "\xE0", 'viable' => false, 'completion' => null];
        yield 'surrogate range' => ['pattern' => '/^\x{d7ff}$/u', 'input' => "\xED", 'viable' => true, 'completion' => "\x9F\xBF"];
        yield 'invalid byte' => ['pattern' => '/^.*$/u', 'input' => "\xFF", 'viable' => false, 'completion' => null];
        yield 'lone continuation byte' => ['pattern' => '/^.*$/u', 'input' => "\x80", 'viable' => false, 'completion' => null];
        yield 'overlong lead' => ['pattern' => '/^.*$/u', 'input' => "\xC0", 'viable' => false, 'completion' => null];
        yield 'past the last code point' => ['pattern' => '/^.*$/u', 'input' => "\xF4\x90", 'viable' => false, 'completion' => null];
        yield 'ascii in utf mode' => ['pattern' => '/^ab$/u', 'input' => 'a', 'viable' => true, 'completion' => 'b'];
        yield 'ascii past the end in utf mode' => ['pattern' => '/^ab$/u', 'input' => 'abc', 'viable' => false, 'completion' => null];
        yield 'invalid continuation byte' => ['pattern' => '/^.*$/u', 'input' => "\xC3A", 'viable' => false, 'completion' => null];
    }
}
