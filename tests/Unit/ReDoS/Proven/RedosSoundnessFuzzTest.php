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

use PHPRegex\Parser\PcreTarget;
use PHPRegex\Redos\Confirmation;
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
 * mt_rand). Three properties hold for every pattern:
 *
 * - a pattern judged "safe (proven)" never exhausts the backtrack limit on
 *   a pump attack, with or without $matches;
 * - a pattern outside the model (a backreference, recursion, a subroutine,
 *   a verb, a conditional, \R, \X, a start-of-pattern verb) never carries
 *   a proof at all: "heuristic by design", the day the model grows is the
 *   day this net is re-pointed;
 * - a witness the confirmed replay reports as replayed fails the engine
 *   when built again.
 *
 * Besides the general grammar, families aim at shapes the model got wrong
 * once or that an adversary pass exploited: a loop whose higher-priority
 * branch has its own loop and a failing tail (attacked with long inputs,
 * PCRE fails from ~1,400 bytes), copies of one small atom written out side
 * by side, and \G with a call offset of 1. Later passes widened the grammar
 * (branch reset, \K, POSIX classes, scoped and cancelled inline flags, and
 * the unicode caseless folds) and the attacker with it: the fold pairs
 * k/KELVIN SIGN, s/LONG S and a-ring/ANGSTROM SIGN fold under /iu while
 * the Turkish DOTLESS I folds with nothing (premises pinned below on the
 * engine, pcre.jit 0), the suffixes include ones that satisfy a
 * lookbehind ("b", "ab") and those fold bytes, and the fold attacks run to
 * 2,000 bytes of input. A family sets every inline option letter in
 * every position PCRE reads it from. Every fifth pattern the analyzer proves safe in
 * the repository corpus is attacked with the same pump machinery, so real
 * shapes, not only generated ones, hold the guarantee.
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

    /**
     * "b" and "ab" satisfy a lookbehind over them; the KELVIN SIGN is a
     * fold byte only under /u, two stray bytes otherwise.
     */
    private const SUFFIXES = ['', '!', "\n", ' ', '0', 'b', 'ab', 'é', self::KELVIN_SIGN];

    private const REPETITIONS = [12, 25, 40];

    private const DEAD_BRANCH_PATTERNS = 150;

    private const WRITTEN_OUT_PATTERNS = 60;

    private const OFFSET_PATTERNS = 100;

    private const BACKREF_PATTERNS = 60;

    private const OUT_OF_MODEL_PATTERNS = 80;

    private const IN_MODEL_PATTERNS = 80;

    private const FOLD_PATTERNS = 60;

    /**
     * Every inline option letter PCRE2 10.43+ reads, each written set,
     * unset, after a caret, and as a bare caret.
     */
    private const OPTION_LETTERS = ['i', 'm', 'n', 'r', 's', 'x', 'xx', 'U', 'J', 'a', 'aD', 'aS', 'aW', 'aP', 'aT'];

    private const OPTION_FORMS = ['%s', '-%s', '^%s', '^'];

    /**
     * Templates and global flags drawn for each letter, position and form.
     */
    private const OPTION_DRAWS = 3;

    /**
     * Where an option is set, relative to a loop over two atoms the option
     * may make overlap or part: {O} is the option, {P} and {Q} the atoms.
     */
    private const OPTION_POSITIONS = [
        'later alternative' => ['^(?:z(?{O})|{P}|{Q})*$', 'x(?{O})|^(?:{P}|{Q})*$', '^(?:(?{O})z|{P}|{Q})*$'],
        'scoped group' => ['^(?{O}:(?:{P}|{Q})*)$', '^(?:(?{O}:{P})|{Q})*$'],
        'nested group end' => ['^(?:z(?{O})|y)?(?:{P}|{Q})*$', '^(?:(?{O}))(?:{P}|{Q})*$'],
        'conditional yes to no' => ['^(?(?=z)z(?{O})|(?:{P}|{Q})*$)', '^(?:(?(?=z)z(?{O})|{P})|{Q})*$'],
        'after a lookaround' => ['^(?=z(?{O})|)(?:{P}|{Q})*$', '^(?:(?=(?{O})){P}|{Q})*$', '(?<!z(?{O}))(?:{P}|{Q})*$'],
    ];

    /**
     * The two atoms each letter moves: case, the Kelvin sign under r, the
     * newline under s, a space under x, U+0663 under aD and aT, U+00A0
     * under aS, U+00E9 under aW and aP.
     */
    private const OPTION_ATOMS = [
        'i' => ['a', 'A'], 'm' => ['a', 'a$'], 'n' => ['(a)', 'a'], 'r' => ['k', '\x{212A}'], 's' => ['.', '\n'],
        'x' => ['a', 'a '], 'xx' => ['[a ]', 'a'], 'U' => ['a+', 'a'], 'J' => ['(?<n>a)', 'a'],
        'a' => ['\d', '\x{663}'], 'aD' => ['\d', '\x{663}'], 'aS' => ['\s', '\x{A0}'], 'aW' => ['\w', '\x{E9}'],
        'aP' => ['[[:alpha:]]', '\x{E9}'], 'aT' => ['[[:digit:]]', '\x{663}'],
    ];

    private const OPTION_FLAGS = ['', 'i', 'u', 'iu', 'ur', 'iur', 'U', 'iU', 'su', 'mu'];

    /**
     * r and the ASCII options only change anything under /u.
     */
    private const UNICODE_OPTION_FLAGS = ['u', 'iu', 'ur', 'iur', 'uU', 'iuU'];

    private const UNICODE_OPTION_LETTERS = ['r', 'a', 'aD', 'aS', 'aW', 'aP', 'aT'];

    /**
     * r restricts caseless matching: it is drawn under /iu.
     */
    private const CASELESS_UNICODE_OPTION_FLAGS = ['iu', 'iur', 'iuU'];

    /**
     * Every Nth pattern the analyzer proves safe in the corpus is attacked.
     */
    private const CORPUS_STRIDE = 5;

    /**
     * U+212A KELVIN SIGN: folds to "k" under caseless /u (engine-verified).
     */
    private const KELVIN_SIGN = "\u{212A}";

    /**
     * U+017F LATIN SMALL LETTER LONG S: folds to "s" under caseless /u.
     */
    private const LONG_S = "\u{017F}";

    /**
     * U+212B ANGSTROM SIGN: folds to U+00E5 under caseless /u.
     */
    private const ANGSTROM_SIGN = "\u{212B}";

    /**
     * U+0131 LATIN SMALL LETTER DOTLESS I: folds to nothing, not to "i".
     */
    private const DOTLESS_I = "\u{0131}";

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
            // The context before the attack: a word character, a non-word
            // one, a newline, a space, a digit.
            static fn (string $pattern): ?string => self::attack($pattern, ['a', 'x', '-', ' ', '0', "\n"], ['a', 'b', '0', ' ', '!', 'ab', 'a!'], ['', '!', 'b'], self::REPETITIONS, offset: 1)
                ?? self::attack($pattern, [''], ['a', 'b', '0', ' ', '!', 'ab', 'a!'], ['', '!', 'b']),
        );
    }

    #[Test]
    public function test_backreference_shapes_never_get_a_proven_verdict(): void
    {
        $analyzer = new RedosAnalyzer();
        $patterns = $this->backrefPatterns();
        $this->assertGreaterThan(self::BACKREF_PATTERNS / 2, \count($patterns));

        $proven = [];
        foreach ($patterns as $pattern) {
            if (RedosProof::Proven === $analyzer->analyze($pattern)->proof) {
                $proven[] = $pattern;
            }
        }

        // A backreference is outside the model: heuristic by design, so no
        // (x)\1+-style shape may carry "proven" until the day the model
        // learns backrefs (verified on ~2,200 of them at build time).
        $this->assertSame([], $proven, \sprintf("%d patterns with a backreference carry a proof:\n%s", \count($proven), implode("\n", $proven)));

        // And the day it does, the pump attacks apply to them unchanged.
        $this->assertNoFalseSafeVerdict($patterns, static fn (string $pattern): ?string => self::attack($pattern), false);
    }

    #[Test]
    public function test_recursion_verb_conditional_and_escape_shapes_never_get_a_proven_verdict(): void
    {
        $analyzer = new RedosAnalyzer();
        $patterns = $this->outOfModelPatterns();
        $this->assertGreaterThan(self::OUT_OF_MODEL_PATTERNS / 2, \count($patterns));

        $proven = [];
        foreach ($patterns as $pattern) {
            if (RedosProof::Proven === $analyzer->analyze($pattern)->proof) {
                $proven[] = $pattern;
            }
        }

        // Recursion (?R), subroutines (?1), the backtracking verbs, the
        // start-of-pattern verbs, conditionals, \R and \X: all outside the
        // model, all heuristic by design.
        $this->assertSame([], $proven, \sprintf("%d out-of-model patterns carry a proof:\n%s", \count($proven), implode("\n", $proven)));

        $this->assertNoFalseSafeVerdict($patterns, static fn (string $pattern): ?string => self::attack($pattern), false);
    }

    #[Test]
    public function test_branch_reset_keep_posix_and_inline_flag_shapes_proven_safe_survive_pump_attacks(): void
    {
        $this->assertNoFalseSafeVerdict(
            $this->inModelPatterns(),
            static fn (string $pattern): ?string => self::attack($pattern, ['', 'a'], ['a', 'k', self::KELVIN_SIGN, '0', 'ab', "\n"], ['', '!', 'b', 'ab', self::DOTLESS_I]),
        );
    }

    #[Test]
    public function test_caseless_unicode_fold_shapes_proven_safe_survive_fold_attacks(): void
    {
        // The premises of the attack alphabet, pinned on the engine with
        // pcre.jit 0 (PHP 8.4.26, PCRE2 10.49): the Kelvin sign, the long s
        // and the angstrom sign fold under /iu, the Turkish dotless i folds
        // with nothing.
        $this->assertSame(1, preg_match('/k/iu', self::KELVIN_SIGN));
        $this->assertSame(1, preg_match('/s/iu', self::LONG_S));
        $this->assertSame(1, preg_match('/\x{E5}/iu', self::ANGSTROM_SIGN));
        $this->assertSame(0, preg_match('/i/ui', self::DOTLESS_I));

        // The long s at 1,000 repetitions is 2,000 bytes, the Kelvin sign
        // at 666 is 1,998: the family attacks proven-safe patterns with
        // two-thousand-byte inputs of fold bytes. The long inputs run on a
        // reduced cross product: one unanchored retry over them is
        // polynomial wall time the backtrack limit never sees (the
        // guarantee is per match attempt), so breadth is kept short and
        // only depth pays.
        $this->assertNoFalseSafeVerdict(
            $this->foldPatterns(),
            static fn (string $pattern): ?string => self::attack(
                $pattern,
                ['', 'a'],
                ['k', 's', 'i', 'K', 'S', self::KELVIN_SIGN, self::LONG_S, self::ANGSTROM_SIGN, self::DOTLESS_I, 'é', 'ki'],
                ['', '!', self::DOTLESS_I, self::KELVIN_SIGN, 'é'],
                [12, 40],
            ) ?? self::attack(
                $pattern,
                [''],
                [self::LONG_S, self::KELVIN_SIGN, self::DOTLESS_I],
                ['', '!'],
                [666, 1000],
                2001,
            ),
        );
    }

    /**
     * Every inline option letter, set, unset, after a caret and as a bare
     * caret, in every position PCRE reads it from: carried into a later
     * alternative, scoped to a group, taken back at a group end, carried
     * from a conditional's yes branch into its no branch, set inside a
     * lookaround; under global flags that include r and U. No pattern of
     * the family proven safe exhausts the limit. Engine facts the attack
     * rests on: "/x(?i)|^(?:a|A)*$/" needs 65 -> 1 025 -> 16 385 steps on
     * "a"{4, 8, 12}."!", "/^(?:z(?-r)|k|\x{212A})*$/iur" 95 -> 1 535 ->
     * 24 575 on the Kelvin sign (pcre.jit 0, "(*NO_START_OPT)").
     */
    #[Test]
    public function test_inline_option_shapes_proven_safe_survive_the_pump_attacks(): void
    {
        $this->assertNoFalseSafeVerdict(
            array_keys($this->inlineOptionPatterns()),
            static fn (string $pattern): ?string => self::attack(
                $pattern,
                ['', 'z', 'y'],
                ['a', 'A', 'k', 'K', self::KELVIN_SIGN, "\n", ' ', "\u{663}", '3', "\u{A0}", "\u{E9}", 'e', 'aa'],
                ['!', '', "\n", 'x'],
                [12, 25],
            ),
        );
    }

    /**
     * The precondition for trusting the net on inline options: it holds a
     * pattern for every letter in every position, the set, unset and caret
     * forms, and global r and U. Before PCRE2 10.43 the engine refuses
     * "(?r)" and the ASCII options, so those letters are absent there.
     */
    #[Test]
    public function test_inline_option_family_covers_every_letter_and_position(): void
    {
        // The family covers every letter only on PCRE2 10.43 and later:
        // before that the engine refuses "(?r)" and the ASCII options.
        if (!PcreTarget::runtime()->pcreAtLeast('10.43')) {
            $this->markTestSkipped(sprintf('The inline option family is verified against PCRE2 10.43 and later; PCRE2 %s reports it differently.', \PCRE_VERSION));
        }

        $entries = $this->inlineOptionPatterns();
        $modern = version_compare(explode(' ', \PCRE_VERSION)[0], '10.43', '>=');

        $covered = [];
        $forms = [];
        $globalFlags = '';
        foreach ($entries as $pattern => $entry) {
            $covered[$entry['letter']][$entry['position']] = true;
            $forms[$entry['form']] = true;
            $globalFlags .= substr($pattern, (int) strrpos($pattern, '/') + 1);
        }

        $missing = [];
        foreach (self::OPTION_LETTERS as $letter) {
            $expected = $modern || !\in_array($letter, self::UNICODE_OPTION_LETTERS, true);
            foreach (array_keys(self::OPTION_POSITIONS) as $position) {
                if ($expected !== isset($covered[$letter][$position])) {
                    $missing[] = $letter.' / '.$position;
                }
            }
        }

        $this->assertSame([], $missing);
        $this->assertSame(self::OPTION_FORMS, array_values(array_intersect(self::OPTION_FORMS, array_keys($forms))));
        $this->assertStringContainsString('U', $globalFlags);
        if (\PHP_VERSION_ID >= 80400 && $modern) {
            $this->assertStringContainsString('r', $globalFlags);
        }
    }

    #[Test]
    public function test_corpus_proven_safe_patterns_survive_pump_attacks(): void
    {
        $contents = file_get_contents(\dirname(__DIR__, 3).'/Fixtures/Corpus/lint-expectations.json');
        $this->assertIsString($contents);
        $corpus = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($corpus);

        $analyzer = new RedosAnalyzer();
        $sample = [];
        $safe = 0;
        foreach ($corpus as $entry) {
            if (!\is_array($entry) || !\is_string($entry['pattern'] ?? null)) {
                continue;
            }
            if ($analyzer->analyze($entry['pattern'])->isProvenSafe()) {
                $safe++;
                // Every Nth proven-safe pattern, in corpus order: the sample
                // moves with the corpus and the model, and stays fixed
                // within one of each.
                if (0 === $safe % self::CORPUS_STRIDE) {
                    $sample[] = $entry['pattern'];
                }
            }
        }

        $this->assertGreaterThan(100, \count($sample), 'the corpus no longer holds a hundred proven-safe patterns');
        $this->assertNoFalseSafeVerdict($sample, static fn (string $pattern): ?string => self::attack($pattern, ['', 'x'], ['a', '0', "\n", 'ab'], ['', '!', 'b'], [12, 40]));
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

            // The replay runs on the call form its verdict came from: a
            // pattern that can match empty (\K, empty alternatives) only
            // blows up on preg_match() without $matches, where PHP retries
            // an empty match; the confirmation's note says which form that
            // was (measured: such a witness fails from 10 pumps without
            // $matches and never with them).
            $withoutMatches = Confirmation::WITHOUT_MATCHES === ($analysis->confirmation->note ?? null);
            $limit = $analysis->confirmation->backtrackLimit ?? 100_000;
            ini_set('pcre.backtrack_limit', (string) $limit);
            $reproduced = false;
            for ($n = 1; !$reproduced && $n <= self::MAX_PUMPS; $n++) {
                $subject = $analysis->witness->build($n);
                $result = $withoutMatches ? @preg_match($pattern, $subject) : @preg_match($pattern, $subject, $matches);
                $reproduced = false === $result && \PREG_BACKTRACK_LIMIT_ERROR === preg_last_error();
            }
            ini_set('pcre.backtrack_limit', '1000000');

            if (!$reproduced) {
                $hits[] = $pattern.': '.$analysis->witness->render().' (backtrack_limit '.$limit.', '.($withoutMatches ? 'without' : 'with').' $matches)';
            }
        }

        $this->assertGreaterThan(0, $replayed, 'no exponential verdict of the net was replayed');
        $this->assertSame([], $hits, \sprintf("%d replayed witnesses do not reproduce:\n%s", \count($hits), implode("\n", $hits)));
    }

    /**
     * @param list<string>              $patterns
     * @param \Closure(string): ?string $attack
     */
    private function assertNoFalseSafeVerdict(array $patterns, \Closure $attack, bool $requireAttacked = true): void
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

        if ($requireAttacked) {
            $this->assertGreaterThan(0, $attacked, 'no pattern of the family was judged safe (proven)');
        }
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
     * (x)\1+-style shapes: a capturing group, its own backreference and
     * quantifiers around both.
     *
     * @return list<string>
     */
    private function backrefPatterns(): array
    {
        $bodies = ['a', '\w', '[a-z]', '\d', '.', 'ab', '(?:ab|a)', '(a|b)', '[^x]', 'x?'];
        $shells = ['(%s)\1%s', '(%s)%s\1', '((%s)\2)\1%s', '(%s)(\1)%s', '(?:%s(\1))%s', '(%s)\1?\1%s', '(?:(%s)\1)+%s'];

        return $this->compilable(self::BACKREF_PATTERNS, fn (): string => '/'.sprintf(
            $this->pick($shells),
            $this->pick($bodies),
            $this->quantifier(),
            $this->quantifier(),
        ).'/'.$this->pick(['', '', 'i', 'u']));
    }

    /**
     * Shapes kept outside the model: recursion, subroutines, the
     * backtracking verbs, the start-of-pattern verbs, conditionals, \R and
     * \X. None of them may ever carry a proof.
     *
     * @return list<string>
     */
    private function outOfModelPatterns(): array
    {
        $startVerbs = ['(*UTF)', '(*UCP)', '(*CR)', '(*ANY)'];
        $verbs = ['(*COMMIT)', '(*PRUNE)', '(*SKIP)', '(*FAIL)', '(*SKIP:m)'];
        $cores = ['a+', '\w+', '(a+)+', '(?:ab|a)+', '[a-z]+!', '\d+$', '(\w\w)+', 'a*b*'];

        return $this->compilable(self::OUT_OF_MODEL_PATTERNS, function () use ($startVerbs, $verbs, $cores): string {
            $core = $this->pick($cores);
            $verb = $this->pick($verbs);
            $shell = $this->pick([
                // Start-of-pattern verbs must come first; the others travel.
                $this->pick($startVerbs).$core,
                $verb.$core,
                $core.$verb.$this->pick(['b', '', 'c']),
                '(?:'.$verb.$core.')+',
                '(?:a(?R)?)+',
                'a(?R)?'.$core,
                $core.'(?R)?b',
                '(a)(?1)?b',
                '(a)(?1)'.$core,
                '(a)?(?(1)'.$core.'|c)',
                '(a)?(?(1)b|'.$core.')',
                '(a)(?(1)'.$core.')',
                '(\R'.$core.')+!',
                '('.$core.'\R)+',
                '(\R+)+!',
                '\R*'.$core.'$',
                '(\X+)+!',
                '('.$core.'\X)+',
            ]);
            $flags = str_contains($shell, '\X') ? 'u' : $this->pick(['', '', 'i', 'u']);

            return '/'.$shell.'/'.$flags;
        });
    }

    /**
     * Shapes built from the constructs the model does analyse: branch
     * reset, \K, POSIX classes, scoped and cancelled inline flags.
     *
     * @return list<string>
     */
    private function inModelPatterns(): array
    {
        $atoms = ['a', '\w', '[a-z]', 'k', '\d', '[[:^space:]]', '[[:alnum:][:space:]]', '[[:punct:]]'];
        $posix = ['[[:alpha:]]', '[[:^digit:]]', '[[:xdigit:]]', '[[:word:]]', '[[:lower:]]'];

        return $this->compilable(self::IN_MODEL_PATTERNS, function () use ($atoms, $posix): string {
            $first = $this->pick($atoms);
            $second = $this->pick($atoms);
            $class = $this->pick($posix);
            $shell = $this->pick([
                '^(?|'.$first.'|'.$second.')+$',
                '(?|'.$first.'|'.$second.')+$',
                '\K(?|'.$first.'|'.$second.')+',
                '^(?|('.$first.'+)|('.$second.'?))+$',
                '^\K'.$class.'+$',
                '('.$class.'+)+!',
                '(?i:'.$first.'+)$',
                $first.'(?-i)'.$second.'$',
                '(?i:'.$first.'|'.$second.')+',
                '^(?i:'.$class.')+$',
                '\K^'.$first.'+$',
                '(?|'.$class.'|'.$first.')'.$this->quantifier(),
            ]);

            return '/'.$shell.'/'.$this->pick(['', '', 'i', 'u', 'iu']);
        });
    }

    /**
     * Caseless unicode folds under /iu: the k, s, a-ring and i letters and
     * their folding partners, inside the shapes that once hid an ambiguity.
     *
     * @return list<string>
     */
    private function foldPatterns(): array
    {
        $letters = ['k', 'K', 's', 'S', 'i', '\x{E5}', '\x{17F}', '\x{212B}', '\x{212A}', '\x{131}', 'é'];
        $shells = ['^(%s+)+!$', '(%s+)+$', '^(%s*)*$', '(%s|%s)+$', '(\w|%s)+$', '(?i:%s+)%s?$', '^(?i:(%s+))+!$', '%s+(%s?)+$', '(?i:[%s%s]+)$', '\x{131}+$', '(%s|\x{131})+$'];

        return $this->compilable(self::FOLD_PATTERNS, fn (): string => '/'.sprintf($this->pick($shells), $this->pick($letters), $this->pick($letters)).'/iu');
    }

    /**
     * One pattern per letter, position and form, its template, global flags
     * and the two atoms the letter moves; those this engine compiles.
     *
     * @return array<string, array{letter: string, position: string, form: string}>
     */
    private function inlineOptionPatterns(): array
    {
        $entries = [];
        foreach (self::OPTION_LETTERS as $letter) {
            foreach (self::OPTION_POSITIONS as $position => $templates) {
                foreach (self::OPTION_FORMS as $form) {
                    [$first, $second] = self::OPTION_ATOMS[$letter];
                    for ($draw = 0; $draw < self::OPTION_DRAWS; $draw++) {
                        $template = $this->pick($templates);
                        $flags = $this->pick(match (true) {
                            'r' === $letter => self::CASELESS_UNICODE_OPTION_FLAGS,
                            \in_array($letter, self::UNICODE_OPTION_LETTERS, true) => self::UNICODE_OPTION_FLAGS,
                            default => self::OPTION_FLAGS,
                        });
                        $pattern = '/'.strtr($template, ['{O}' => \sprintf($form, $letter), '{P}' => $first, '{Q}' => $second]).'/'.$flags;
                        if (false !== @preg_match($pattern, '') && !isset($entries[$pattern])) {
                            $entries[$pattern] = ['letter' => $letter, 'position' => $position, 'form' => $form];
                        }
                    }
                }
            }
        }

        return $entries;
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
            return $this->pick(['a', 'a', 'b', 'x', '0', '!', ' ', '\w', '\d', '\s', '.', '[^b]', '[ab]', '\W', '\S', '[[:alpha:]]', '[[:xdigit:]]']).$this->quantifier();
        }
        if ($roll < 48) {
            return $this->pick(['\b', '\B', '^', '$', '\z', '\K']);
        }
        if ($roll < 62) {
            return '('.$this->sequence($depth - 1).')'.$this->quantifier();
        }
        if ($roll < 76) {
            return '(?:'.$this->sequence($depth - 1).'|'.$this->sequence($depth - 1).')'.$this->quantifier();
        }
        if ($roll < 80) {
            return '(?|'.$this->sequence($depth - 1).'|'.$this->sequence($depth - 1).')'.$this->quantifier();
        }
        if ($roll < 84) {
            return '(?:'.$this->sequence($depth - 1).')'.$this->quantifier();
        }
        if ($roll < 90) {
            return '(?>'.$this->sequence($depth - 1).')'.$this->quantifier();
        }
        if ($roll < 96) {
            return $this->pick(['(?=', '(?!', '(?i:']).$this->sequence($depth - 1).')';
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
