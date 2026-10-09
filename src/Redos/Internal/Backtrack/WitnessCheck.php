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

namespace PHPRegex\Redos\Internal\Backtrack;

use PHPRegex\Parser\Engine\PcreEngine;

/**
 * Asks the running engine about one attempt of a witness, where the model
 * leaves a lookaround undecided: whether the attempt started at an offset
 * matches. The attempt is pinned there with the "A" modifier and run in the
 * interpreter, "(*NO_JIT)", with "(*NO_START_OPT)", so that no start-up
 * optimization answers for it. Subjects stay a few pumps long.
 *
 * @internal
 */
final readonly class WitnessCheck
{
    private const NO_JIT = '(*NO_JIT)';

    private const NO_START_OPT = '(*NO_START_OPT)';

    /**
     * The pattern pinned to the offset; null when no delimiter is left for
     * the verbs, and the engine is then not asked.
     */
    private ?string $pinned;

    public function __construct(string $pattern, private PcreEngine $engine = new PcreEngine())
    {
        $prepared = $this->engine->prepare($pattern);
        $this->pinned = self::NO_JIT === substr($prepared, 1, \strlen(self::NO_JIT))
            ? substr_replace($prepared, self::NO_JIT.self::NO_START_OPT, 1, \strlen(self::NO_JIT)).'A'
            : null;
    }

    /**
     * Whether the attempt at the offset matches: true or false as the
     * engine answers, null when it gives none.
     */
    public function matches(string $subject, int $offset): ?bool
    {
        if (null === $this->pinned) {
            return null;
        }

        return $this->engine->match($this->pinned, $subject, null, $offset)->matched;
    }
}
