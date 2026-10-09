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

namespace PHPRegex\Tests\Benchmark\Support;

/**
 * One pathological input of the benchmarks, read from
 * data/<group>/<slug>.php: the key is the file's place, so adding a case
 * never renames another.
 */
final readonly class BenchCase
{
    public string $key;

    /**
     * @param string      $group   the folder the file sits in, the benchmark group it feeds
     * @param string      $slug    the file name without its extension
     * @param string      $pattern the pattern, delimiters and flags included
     * @param string      $origin  "synthetic", "issue #N" or "corpus:<repo>"
     * @param string      $note    why the case is there
     * @param bool        $invalid whether the case exercises a pattern PCRE refuses
     * @param string|null $expect  the outcome the measured run must reach (redos and automata only, see Workloads::outcome()); null elsewhere
     */
    public function __construct(
        public string $group,
        public string $slug,
        public string $pattern,
        public string $origin,
        public string $note,
        public bool $invalid = false,
        public ?string $expect = null,
    ) {
        $this->key = $group.'/'.$slug;
    }
}
