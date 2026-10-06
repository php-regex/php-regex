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

namespace PHPRegex\Rector\Internal;

use PHPRegex\Automata\TrivialMatch;
use PHPRegex\Automata\TrivialMatchClassifier;
use PHPRegex\Parser\RegexParser;

/**
 * What the automata prove of a pattern, asked once per pattern and target
 * PHP version: a project repeats its patterns, the proof costs a solver run.
 *
 * A verdict holds only when the PHP the code targets reads the pattern as
 * the running engine does: the pattern compiles there, and the automata,
 * reading it with the PCRE2 that PHP bundles, prove the same answer. Before
 * PCRE2 10.43, "a{,0}b" is the text "a{,0}b", not "b".
 *
 * @internal
 */
final class ProvenLiterals
{
    /**
     * @var array<string, string|false>
     */
    private array $literals = [];

    /**
     * @var array<string, TrivialMatch|false>
     */
    private array $matches = [];

    private ?TrivialMatchClassifier $running = null;

    /**
     * The classifier and the parser per target PHP version.
     *
     * @var array<int, array{TrivialMatchClassifier, RegexParser}>
     */
    private array $targets = [];

    /**
     * The one string the pattern matches, or null when the running engine
     * refuses the pattern, when the target PHP does or reads it otherwise,
     * or when the automata prove no single string.
     */
    public function of(string $pattern, int $phpVersion): ?string
    {
        $key = $phpVersion.':'.$pattern;
        if (!\array_key_exists($key, $this->literals)) {
            $literal = $this->runs($pattern, $phpVersion) ? $this->running()->matchedLiteral($pattern) : null;
            $agrees = null !== $literal && $literal === $this->target($phpVersion)[0]->matchedLiteral($pattern);
            $this->literals[$key] = $agrees && PregCall::spellable($literal) ? $literal : false;
        }

        $literal = $this->literals[$key];

        // "0" is a literal, not a refusal.
        return false === $literal ? null : $literal;
    }

    /**
     * The string function the pattern behaves as under preg_match(), on the
     * same terms as of().
     */
    public function match(string $pattern, int $phpVersion): ?TrivialMatch
    {
        $key = $phpVersion.':'.$pattern;
        if (!\array_key_exists($key, $this->matches)) {
            $match = $this->runs($pattern, $phpVersion) ? $this->running()->classify($pattern) : null;
            $target = null === $match ? null : $this->target($phpVersion)[0]->classify($pattern);
            $agrees = null !== $target && $match->kind === $target->kind && $match->literals === $target->literals;
            $this->matches[$key] = $agrees && PregCall::spellable(...$match->literals) ? $match : false;
        }

        $match = $this->matches[$key];

        return false === $match ? null : $match;
    }

    /**
     * Whether the running engine and the target PHP both compile the
     * pattern.
     */
    private function runs(string $pattern, int $phpVersion): bool
    {
        return PregCall::compiles($pattern) && PregCall::targetAccepts($pattern, $this->target($phpVersion)[1]);
    }

    private function running(): TrivialMatchClassifier
    {
        return $this->running ??= new TrivialMatchClassifier();
    }

    /**
     * @return array{TrivialMatchClassifier, RegexParser}
     */
    private function target(int $phpVersion): array
    {
        if (!isset($this->targets[$phpVersion])) {
            $parser = PregCall::targetParser($phpVersion);
            $this->targets[$phpVersion] = [new TrivialMatchClassifier($parser), $parser];
        }

        return $this->targets[$phpVersion];
    }
}
