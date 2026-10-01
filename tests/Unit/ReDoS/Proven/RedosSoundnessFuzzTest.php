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

namespace PHPRegex\Tests\Unit\ReDoS\Proven;

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The soundness net: small random patterns from a fixed seed, the same on
 * every PHP version (a hand-rolled linear congruential generator, not
 * mt_rand). Two properties hold for every one of them:
 *
 * - a pattern judged "safe (proven)" never exhausts the backtrack limit on
 *   a pump attack, with or without $matches;
 * - a witness the confirmed replay reports as replayed fails the engine
 *   when built again.
 *
 * Besides the general grammar, three families aim at shapes the model got
 * wrong once: a loop whose higher-priority branch has its own loop and a
 * failing tail (attacked with long inputs, PCRE fails from ~1,400 bytes),
 * copies of one small atom written out side by side, and \G with a call
 * offset of 1.
 *
 * Engine: pcre.jit 0, pcre.backtrack_limit 1000000 for the attacks; the
 * replay's own limit (the confirmation's) for the witnesses. PHP has no
 * call without $matches at a non-zero offset: a named offset argument
 * passes $matches implicitly (preg_match('/a+\K|\G\B(b+)+c/', 'a'.str_repeat('b', 25),
 * offset: 0) returns 1, the same call without the named argument returns
 * false), so the offset attacks run with $matches only.
 */
final class RedosSoundnessFuzzTest extends TestCase
{
    private const SEED = 20261001;

    private const PATTERNS = 300;

    /**
     * Exponential verdicts replayed in confirmed mode, at most.
     */
    private const REPLAYED_PATTERNS = 40;

    private const MAX_PUMPS = 64;

    private const MAX_INPUT_LENGTH = 200;

    private const PREFIXES = ['', 'x', '0', '!'];

    private const PUMPS = ['a', 'b', 'x', '0', ' ', "\n", '!', 'é', 'ab', 'a ', 'a!', "a\n", '0a', ' a', '!a'];

    private const SUFFIXES = ['', '!', "\n", ' ', '0', 'b'];

    private const REPETITIONS = [12, 25, 40];

    private const DEAD_BRANCH_PATTERNS = 150;

    private const WRITTEN_OUT_PATTERNS = 60;

    private const OFFSET_PATTERNS = 100;

    private int $state = self::SEED;

    private string|false $backtrackLimit = false;

    private string|false $jit = false;

    protected function setUp(): void
    {
        $this->state = self::SEED;
        $this->backtrackLimit = ini_get('pcre.backtrack_limit');
        $this->jit = ini_get('pcre.jit');
        ini_set('pcre.backtrack_limit', '1000000');
        ini_set('pcre.jit', '0');
    }

    protected function tearDown(): void
    {
        if (false !== $this->backtrackLimit) {
            ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        }
        if (false !== $this->jit) {
            ini_set('pcre.jit', $this->jit);
        }
    }

    #[Test]
    public function test_generator_is_deterministic(): void
    {
        $first = $this->patterns();
        $this->state = self::SEED;

        $this->assertSame($first, $this->patterns());
        $this->assertCount(self::PATTERNS, $first);
    }

    #[Test]
    public function test_proven_safe_pattern_survives_the_pump_attacks(): void
    {
        $this->assertNoFalseSafeVerdict($this->patterns(), static fn (string $pattern): ?string => self::attack($pattern));
    }

    #[Test]
    public function test_dead_branch_loop_proven_safe_survives_long_pump_attacks(): void
    {
        $this->assertNoFalseSafeVerdict(
            $this->deadBranchPatterns(),
            static fn (string $pattern): ?string => self::attack($pattern, [''], ['a', '0', 'ab', "\n", ' '], ['!'], [1500, 3000], 6001),
        );
    }

    #[Test]
    public function test_written_out_copies_proven_safe_survive_the_pump_attacks(): void
    {
        $this->assertNoFalseSafeVerdict(
            $this->writtenOutPatterns(),
            static fn (string $pattern): ?string => self::attack($pattern, [''], ['a', '1', 'ab', 'a-'], ['', '!'], [8, 16, 24, 32, 48]),
        );
    }

    #[Test]
    public function test_continuation_anchor_proven_safe_survives_attacks_at_an_offset(): void
    {
        $this->assertNoFalseSafeVerdict(
            $this->offsetPatterns(),
            static fn (string $pattern): ?string => self::attack($pattern, ['x', 'a', '-', ' ', '0'], ['a', 'b', '0', ' ', '!', 'ab', 'a!'], ['', '!', 'b'], self::REPETITIONS, offset: 1)
                ?? self::attack($pattern, [''], ['a', 'b', '0', ' ', '!', 'ab', 'a!'], ['', '!', 'b']),
        );
    }

    #[Test]
    public function test_replayed_witness_reproduces(): void
    {
        $analyzer = new RedosAnalyzer();
        $replayed = 0;
        $hits = [];

        foreach ($this->patterns() as $pattern) {
            if ($replayed >= self::REPLAYED_PATTERNS) {
                break;
            }
            $theoretical = $analyzer->analyze($pattern);
            if (RedosProof::Proven !== $theoretical->proof || RedosComplexity::Exponential !== $theoretical->complexity) {
                continue;
            }

            $analysis = $analyzer->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed);
            if (true !== $analysis->replayed || null === $analysis->witness) {
                continue;
            }
            $replayed++;

            $limit = $analysis->confirmation->backtrackLimit ?? 100_000;
            ini_set('pcre.backtrack_limit', (string) $limit);
            $reproduced = false;
            for ($n = 1; !$reproduced && $n <= self::MAX_PUMPS; $n++) {
                $reproduced = false === @preg_match($pattern, $analysis->witness->build($n), $matches)
                    && \PREG_BACKTRACK_LIMIT_ERROR === preg_last_error();
            }
            ini_set('pcre.backtrack_limit', '1000000');

            if (!$reproduced) {
                $hits[] = $pattern.': '.$analysis->witness->render().' (backtrack_limit '.$limit.')';
            }
        }

        $this->assertGreaterThan(0, $replayed, 'no exponential verdict of the net was replayed');
        $this->assertSame([], $hits, \sprintf("%d replayed witnesses do not reproduce:\n%s", \count($hits), implode("\n", $hits)));
    }

    /**
     * @param list<string>              $patterns
     * @param \Closure(string): ?string $attack
     */
    private function assertNoFalseSafeVerdict(array $patterns, \Closure $attack): void
    {
        $analyzer = new RedosAnalyzer();
        $attacked = 0;
        $hits = [];

        foreach ($patterns as $pattern) {
            if (!$analyzer->analyze($pattern)->isProvenSafe()) {
                continue;
            }
            $attacked++;
            $hit = $attack($pattern);
            if (null !== $hit) {
                $hits[] = $pattern.' on '.$hit;
            }
        }

        $this->assertGreaterThan(0, $attacked, 'no pattern of the family was judged safe (proven)');
        $this->assertSame([], $hits, \sprintf("%d of %d \"safe (proven)\" patterns exhaust the backtrack limit:\n%s", \count($hits), $attacked, implode("\n", $hits)));
    }

    /**
     * The first input that exhausts the backtrack limit, described, or null.
     * At offset 0 both call forms run; at another offset only the call with
     * $matches exists.
     *
     * @param list<string> $prefixes
     * @param list<string> $pumps
     * @param list<string> $suffixes
     * @param list<int>    $repetitions
     */
    private static function attack(
        string $pattern,
        array $prefixes = self::PREFIXES,
        array $pumps = self::PUMPS,
        array $suffixes = self::SUFFIXES,
        array $repetitions = self::REPETITIONS,
        int $maxLength = self::MAX_INPUT_LENGTH,
        int $offset = 0,
    ): ?string {
        foreach ($prefixes as $prefix) {
            foreach ($pumps as $pump) {
                foreach ($suffixes as $suffix) {
                    foreach ($repetitions as $count) {
                        $input = $prefix.str_repeat($pump, $count).$suffix;
                        if (\strlen($input) > $maxLength) {
                            continue;
                        }
                        if (false === @preg_match($pattern, $input, $matches, 0, $offset) && \PREG_BACKTRACK_LIMIT_ERROR === preg_last_error()) {
                            return self::describe($prefix, $pump, $suffix, $count, 0 === $offset ? 'with $matches' : 'with $matches, offset '.$offset);
                        }
                        if (0 === $offset && false === @preg_match($pattern, $input) && \PREG_BACKTRACK_LIMIT_ERROR === preg_last_error()) {
                            return self::describe($prefix, $pump, $suffix, $count, 'without $matches');
                        }
                    }
                }
            }
        }

        return null;
    }

    private static function describe(string $prefix, string $pump, string $suffix, int $repetitions, string $call): string
    {
        return \sprintf(
            '%s . %s x %d . %s (%s)',
            json_encode($prefix, \JSON_THROW_ON_ERROR),
            json_encode($pump, \JSON_THROW_ON_ERROR),
            $repetitions,
            json_encode($suffix, \JSON_THROW_ON_ERROR),
            $call,
        );
    }

    /**
     * The net's patterns: each compiles on the running engine.
     *
     * @return list<string>
     */
    private function patterns(): array
    {
        $patterns = [];
        while (\count($patterns) < self::PATTERNS) {
            $flags = '';
            foreach (['i', 'm', 's', 'u', 'x'] as $flag) {
                if (0 === $this->next(5)) {
                    $flags .= $flag;
                }
            }
            $pattern = '/'.$this->sequence(3).'/'.$flags;
            if (false !== @preg_match($pattern, '') && !\in_array($pattern, $patterns, true)) {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    /**
     * A loop whose higher-priority branch has its own loop and a tail that
     * may fail, the other branch one character.
     *
     * @return list<string>
     */
    private function deadBranchPatterns(): array
    {
        $shapes = ['(?:X(?:LB)?)+', '(?:LB|X)+', '(?:X|LB)+', '(?:LB|X)*?', '^(?:X(?:LB)?)*$', '(?:X(?:LB)??)+', '(?:XL?B?)+', '(?>X(?:LB)?)+'];
        $characters = ['\w', 'a', '\d', '[a-z]', '.', '\S', '[^-]'];
        $loops = ['\w+', 'a+', '\w*', 'a*?', '\d+', '[a-z]+?', '.+', '\S*'];
        $tails = ['\b-', '\B!', '^', '\A', '\G', '$\n', '(?=-)', '(?!a)', '\b\W', '-', '\b', '(?<=-)', '\z!', 'x'];

        return $this->compilable(self::DEAD_BRANCH_PATTERNS, fn (): string => '/'.strtr($this->pick($shapes), [
            'X' => $this->pick($characters),
            'L' => $this->pick($loops),
            'B' => $this->pick($tails),
        ]).'/'.$this->pick(['', '', 'm', 's', 'U']));
    }

    /**
     * Two to thirty copies of one small atom, written out, anchored, with
     * an optional tail.
     *
     * @return list<string>
     */
    private function writtenOutPatterns(): array
    {
        $atoms = ['a?', '(?:a|a)', '\d{1,3}', '[a-z0-9]{1,8}-?', 'a{0,2}', '(?:a|ab)', '\w?', '(?:a?)', '(?:a|\w)', '1?'];

        return $this->compilable(self::WRITTEN_OUT_PATTERNS, function () use ($atoms): string {
            $copies = 2 + $this->next(29);

            return '/^'.str_repeat($this->pick($atoms), $copies).$this->pick(['', str_repeat('a', $copies), '\d', 'b', '-?']).'$/';
        });
    }

    /**
     * \G and a word-boundary context before an ambiguous loop and a tail,
     * or before a pattern of the general grammar.
     *
     * @return list<string>
     */
    private function offsetPatterns(): array
    {
        $contexts = ['', '\b', '\B', '-', '\b-', '\B-', '(?<=x)', '(?<!x)', '^'];
        $loops = ['(a+)+', '(a|a)+', '(\w+\s?)+', '(a*)*', '(\d+)+', '(.+)+', '(?:[ab]|a)+', '(\w|\d)+', '(a+?)+?'];
        $tails = ['$', '\z', '\b', '\B', '!', 'b', '(?=b)', '(?!a)', '[^a]', '\W'];

        return $this->compilable(self::OFFSET_PATTERNS, fn (): string => 0 === $this->next(2)
            ? '/\G'.$this->pick($contexts).$this->pick($loops).$this->pick($tails).'/'.$this->pick(['', '', 'm', 's', 'i'])
            : '/\G'.$this->pick($contexts).$this->sequence(2).'/'.$this->pick(['', '', 'm', 's', 'i']));
    }

    /**
     * @param \Closure(): string $generate
     *
     * @return list<string>
     */
    private function compilable(int $count, \Closure $generate): array
    {
        $patterns = [];
        for ($attempts = 0; \count($patterns) < $count && $attempts < 20 * $count; $attempts++) {
            $pattern = $generate();
            if (false !== @preg_match($pattern, '') && !\in_array($pattern, $patterns, true)) {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    private function sequence(int $depth): string
    {
        $sequence = '';
        for ($i = 0, $count = 1 + $this->next(3); $i < $count; $i++) {
            $sequence .= $this->atom($depth);
        }
        if ($depth > 0 && 0 === $this->next(8)) {
            $sequence .= '|'.$this->sequence($depth - 1);
        }

        return $sequence;
    }

    private function atom(int $depth): string
    {
        $roll = $this->next(100);
        if ($depth <= 0 || $roll < 40) {
            return $this->pick(['a', 'a', 'b', 'x', '0', '!', ' ', '\w', '\d', '\s', '.', '[^b]', '[ab]', '\W', '\S']).$this->quantifier();
        }
        if ($roll < 48) {
            return $this->pick(['\b', '\B', '^', '$', '\z']);
        }
        if ($roll < 62) {
            return '('.$this->sequence($depth - 1).')'.$this->quantifier();
        }
        if ($roll < 76) {
            return '(?:'.$this->sequence($depth - 1).'|'.$this->sequence($depth - 1).')'.$this->quantifier();
        }
        if ($roll < 84) {
            return '(?:'.$this->sequence($depth - 1).')'.$this->quantifier();
        }
        if ($roll < 90) {
            return '(?>'.$this->sequence($depth - 1).')'.$this->quantifier();
        }
        if ($roll < 96) {
            return $this->pick(['(?=', '(?!']).$this->sequence($depth - 1).')';
        }

        return $this->pick(['(?<=', '(?<!']).$this->pick(['a', 'b', '\w', '\s', '!']).')';
    }

    private function quantifier(): string
    {
        $base = $this->pick(['', '', '', '*', '+', '?', '{m,n}', '{m,}']);
        if ('' === $base) {
            return '';
        }
        if ('{m,n}' === $base) {
            $min = $this->next(3);
            $base = '{'.$min.','.($min + $this->next(3)).'}';
        } elseif ('{m,}' === $base) {
            $base = '{'.$this->next(3).',}';
        }

        return $base.$this->pick(['', '', '?', '+']);
    }

    /**
     * @param non-empty-list<string> $choices
     */
    private function pick(array $choices): string
    {
        return $choices[$this->next(\count($choices))];
    }

    /**
     * An integer in [0, $bound): glibc's LCG constants, the high bits.
     */
    private function next(int $bound): int
    {
        $this->state = ($this->state * 1103515245 + 12345) % 2147483648;

        return ($this->state >> 16) % $bound;
    }
}
