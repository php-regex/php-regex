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

use PHPRegex\Linter\Rule\LintContext;

/**
 * How many questions a rule may put to the automata about one pattern:
 * each costs milliseconds, and a long pattern of distinct atoms would ask
 * hundreds. Past the number the rule has no answer, and stays silent for
 * the rest of the pattern.
 *
 * A linter reuses its rules from one pattern to the next, and builds a new
 * context for each: the count starts again with the first question about
 * another context.
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

    private ?LintContext $pattern = null;

    private int $asked = 0;

    public function __construct(private readonly int $limit = self::QUESTIONS_PER_PATTERN) {}

    /**
     * Whether one more question may be asked about the pattern of the
     * context; when it may, the question is counted.
     *
     * @phpstan-impure
     */
    public function allows(LintContext $context): bool
    {
        if ($context !== $this->pattern) {
            $this->pattern = $context;
            $this->asked = 0;
        }

        if ($this->asked >= $this->limit) {
            return false;
        }

        $this->asked++;

        return true;
    }

    /**
     * The questions counted for the current pattern.
     */
    public function asked(): int
    {
        return $this->asked;
    }
}
