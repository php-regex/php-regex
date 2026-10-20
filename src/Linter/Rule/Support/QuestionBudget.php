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

namespace PHPRegex\Linter\Rule\Support;

/**
 * How many questions a rule may put to the automata about one pattern:
 * each costs milliseconds, and a long pattern of distinct atoms would ask
 * hundreds. Past the number the rule has no answer, and stays silent for
 * the rest of the pattern.
 *
 * A linter reuses its rules from one pattern to the next: a rule resets
 * its budget as it begins each pattern.
 *
 * @internal
 */
final class QuestionBudget
{
    /**
     * The questions a rule may ask about one pattern: a few hundred
     * milliseconds at most, and well above the three a pattern of the
     * corpus asks at most.
     */
    public const QUESTIONS_PER_PATTERN = 8;

    private int $asked = 0;

    public function __construct(private readonly int $limit = self::QUESTIONS_PER_PATTERN) {}

    /**
     * Whether one more question may be asked about the pattern; when it
     * may, the question is counted.
     *
     * @phpstan-impure
     */
    public function allows(): bool
    {
        if ($this->asked >= $this->limit) {
            return false;
        }

        $this->asked++;

        return true;
    }

    /**
     * Count again from none, for the next pattern.
     */
    public function reset(): void
    {
        $this->asked = 0;
    }

    /**
     * The questions counted for the current pattern.
     */
    public function asked(): int
    {
        return $this->asked;
    }
}
