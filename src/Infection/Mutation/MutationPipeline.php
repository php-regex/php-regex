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

namespace PHPRegex\Infection\Mutation;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Solver\InMemoryDfaCache;
use PHPRegex\Infection\Hint\HintWriter;
use PHPRegex\Infection\Hint\KillString;
use PHPRegex\Infection\Judge\Budget;
use PHPRegex\Infection\Judge\CallReplay;
use PHPRegex\Infection\Judge\DifferentialFallback;
use PHPRegex\Infection\Judge\EquivalenceJudge;
use PHPRegex\Infection\Judge\RedosGuard;
use PHPRegex\Infection\Site\Observation;
use PHPRegex\Infection\Site\PatternCall;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalyzer;

/**
 * The mutants of one family for one call, in the order the family writes
 * them, each kept only when:
 *
 *  - the running engine compiles it;
 *  - the ReDoS analysis does not rate it catastrophic where the original
 *    is not;
 *  - it is not proven alike the original for what the call sees;
 *  - it is not proven alike a mutant of the same node already kept.
 *
 * A kept mutant carries the first subject, from the automata then from the
 * sample generator, on which the replay of the call tells it from the
 * original. Every check takes a unit of one budget per pattern: once spent,
 * the rest are kept unproven and without a kill string.
 *
 * @internal
 */
final class MutationPipeline
{
    private readonly RegexParser $parser;

    private readonly CandidateGenerator $generator;

    private readonly PcreEngine $engine;

    private readonly RedosGuard $redos;

    private readonly CallReplay $replay;

    private readonly DifferentialFallback $fallback;

    private readonly HintWriter $hints;

    public function __construct(private readonly Family $family, private readonly int $budget = Budget::UNITS)
    {
        // No tree is kept from one literal to the next: the ReDoS analysis
        // and the sample generator read with the same parser.
        $this->parser = RegexParser::create(['cache' => null]);
        $this->generator = new CandidateGenerator($this->parser);
        $this->engine = new PcreEngine();
        $this->redos = new RedosGuard(new RedosAnalyzer($this->parser));
        $this->replay = new CallReplay($this->engine);
        $this->fallback = new DifferentialFallback(parser: $this->parser);
        $this->hints = new HintWriter($this->parser, $this->engine);
    }

    /**
     * The mutated pattern literals, kill strings written in.
     *
     * @return list<string>
     */
    public function mutants(PatternCall $call): array
    {
        return iterator_to_array($this->stream($call), false);
    }

    /**
     * The same mutants, one at a time: each one is built, judged and handed
     * over before the next is written, so a long literal never holds all of
     * its mutants in memory at once.
     *
     * @return \Generator<int, string>
     */
    public function stream(PatternCall $call): \Generator
    {
        $original = $call->pattern;
        if (null !== $this->engine->compile($original)) {
            return;
        }

        try {
            $groups = $this->generator->groups($this->family, $original);
            $group = $groups->current();
        } catch (ExceptionInterface) {
            // A pattern the engine compiles and the parser does not read,
            // one past the parser's length limit for one, gets no mutant.
            return;
        }

        // The automata compiled for this pattern are kept while its mutants
        // are judged, the original's above all, and dropped with them.
        $judge = new EquivalenceJudge(new LanguageSolver($this->parser, new InMemoryDfaCache()));
        $budget = new Budget($this->budget);
        $observation = $call->observation();
        $originalCatastrophic = null === $group ? null : $this->redos->catastrophic($original, $budget);

        for (; null !== $group; $groups->next(), $group = $groups->current()) {
            $kept = [];
            foreach ($group as $candidate) {
                if (null !== $this->engine->compile($candidate)
                    || (false === $originalCatastrophic && true === $this->redos->catastrophic($candidate, $budget))) {
                    continue;
                }

                $verdict = $judge->judge($observation, $original, $candidate, $budget);
                if (true === $verdict->alike || $this->alikeAny($judge, $observation, $kept, $candidate, $budget)) {
                    continue;
                }

                // Kept only while a check can still prove a later one alike.
                if (!$budget->spent()) {
                    $kept[] = $candidate;
                }
                $kill = $this->killString($call, $original, $candidate, $verdict->subjects, $budget);

                yield null === $kill ? $candidate : ($this->hints->write($candidate, $kill) ?? $candidate);
            }
        }
    }

    /**
     * Whether the candidate is proven alike a mutant of the same node
     * already kept. With no check left to pay for, the judge proves nothing
     * more: it pays before it reads the patterns.
     *
     * @param list<string> $kept
     */
    private function alikeAny(EquivalenceJudge $judge, Observation $observation, array $kept, string $candidate, Budget $budget): bool
    {
        foreach ($kept as $earlier) {
            if (true === $judge->judge($observation, $earlier, $candidate, $budget)->alike) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $found the subjects the automata found
     */
    private function killString(PatternCall $call, string $original, string $mutant, array $found, Budget $budget): ?string
    {
        if (!$this->replay->replayable($call)) {
            return null;
        }

        // A kill string longer than a hint holds is written into none: the
        // padding up to the offset alone may make it so, and none is then
        // looked for, nor made printable a byte at a time.
        $offset = max(0, $call->integer('offset') ?? 0);
        if ($offset > KillString::MAX_BYTES) {
            return null;
        }

        $kill = $this->firstDiffering($call, $original, $mutant, $found, $offset);
        if (null === $kill && $budget->spend($original, $mutant)) {
            $kill = $this->firstDiffering($call, $original, $mutant, $this->fallback->subjects($original, $mutant), $offset);
        }

        return null === $kill || \strlen($kill) > KillString::MAX_BYTES ? null : $this->printable($call, $original, $mutant, $kill);
    }

    /**
     * The kill string with each ASCII control byte put back as one
     * a test reads more easily, "!", "a", "0" or a space, where the replay
     * still tells the patterns apart: the shortest subject the automata find
     * starts from the lowest byte, a NUL where any non-digit would do. A
     * byte that matters, the newline that tells "$" from "\z", stays.
     */
    private function printable(PatternCall $call, string $original, string $mutant, string $kill): string
    {
        for ($i = 0, $length = \strlen($kill); $i < $length; $i++) {
            // A byte from 0x80 up stays: it may belong to a UTF-8 sequence,
            // which a pattern in UTF mode reads whole.
            if (\ord($kill[$i]) >= 0x20 && 0x7F !== \ord($kill[$i])) {
                continue;
            }

            foreach (['!', 'a', '0', ' '] as $byte) {
                $readable = substr_replace($kill, $byte, $i, 1);
                if ($this->replay->differs($call, $original, $mutant, $readable)) {
                    $kill = $readable;

                    break;
                }
            }
        }

        return $kill;
    }

    /**
     * The first subject the replay tells the patterns apart on. A call that
     * searches from an offset reads the subject from there: each subject is
     * tried behind that many bytes, which the printable pass then writes as
     * it can.
     *
     * @param list<string> $subjects
     */
    private function firstDiffering(PatternCall $call, string $original, string $mutant, array $subjects, int $offset): ?string
    {
        foreach ($subjects as $subject) {
            $candidate = str_repeat("\0", $offset).$subject;
            if ($this->replay->differs($call, $original, $mutant, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
