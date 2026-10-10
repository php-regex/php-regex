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

namespace PHPRegex\Redos;

use PHPRegex\Redos\Internal\Backtrack\WitnessRenderer;

/**
 * The cost of an unanchored search whose every attempt is linear: an
 * attempt started inside a run of the run word reads to the end of the run,
 * then fails on the breaker, and the search starts one at each position of
 * the run. On the prefix, the run repeated n times, then the breaker,
 * PCRE2's interpreter takes a number of steps quadratic in n; so do the
 * retries of preg_match_all(), preg_replace() and preg_split(). The JIT may
 * avoid it for some patterns. pcre.backtrack_limit does not stop it: the
 * limit is counted per attempt, and trips only when one attempt exceeds it.
 *
 * The prefix, often empty, keeps the first attempt from matching the bare
 * run: "^\s+" in "/^\s+|\s+$/" would.
 *
 * The parts hold raw bytes; toArray() gives them as PHP double-quoted
 * literals, like RedosWitness.
 *
 * @api
 *
 * @phpstan-type SearchCostArray array{degree: int, witness: array{prefix: string, run: string, breaker: string}, replayed: bool|null}
 */
final readonly class RedosSearchCost
{
    /**
     * @internal built by RedosAnalyzer::analyze(), for RedosAnalysis::$searchCost
     *
     * @param int       $degree   the degree of the search's cost in the run's length: 2
     * @param string    $prefix   what comes before the run: the first attempt fails on it
     * @param bool|null $replayed whether the engine replay, without the JIT, counted attempts growing with the rest of the run; null when no replay was made
     */
    public function __construct(
        public int $degree,
        public string $prefix,
        public string $run,
        public string $breaker,
        public bool $unicode,
        public ?bool $replayed = null,
    ) {}

    /**
     * The raw input: the prefix, the run repeated, then the breaker.
     */
    public function build(int $repetitions): string
    {
        return $this->prefix.str_repeat($this->run, max(0, $repetitions)).$this->breaker;
    }

    /**
     * The attack as PHP reads it, like RedosWitness::render():
     * '"x" . " " x n . "!"', an empty prefix or breaker left out.
     */
    public function render(): string
    {
        $parts = [];
        if ('' !== $this->prefix) {
            $parts[] = '"'.WitnessRenderer::literal($this->prefix, $this->unicode).'"';
        }

        $parts[] = '"'.WitnessRenderer::literal($this->run, $this->unicode).'" x n';
        if ('' !== $this->breaker) {
            $parts[] = '"'.WitnessRenderer::literal($this->breaker, $this->unicode).'"';
        }

        return implode(' . ', $parts);
    }

    /**
     * The degree, the witness parts as the inside of PHP double-quoted
     * literals, and what the replay found.
     *
     * @return SearchCostArray
     */
    public function toArray(): array
    {
        return [
            'degree' => $this->degree,
            'witness' => [
                'prefix' => WitnessRenderer::literal($this->prefix, $this->unicode),
                'run' => WitnessRenderer::literal($this->run, $this->unicode),
                'breaker' => WitnessRenderer::literal($this->breaker, $this->unicode),
            ],
            'replayed' => $this->replayed,
        ];
    }
}
