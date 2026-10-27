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

namespace PHPRegex\Redos\Backtrack;

use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Engine\NoJit;
use PHPRegex\Parser\Engine\PcreEngine;

/**
 * Asks the running engine about one attempt of a witness, where the model
 * leaves a lookaround undecided: whether the attempt started at an offset
 * matches. The attempt is pinned there with the "A" modifier and run in the
 * interpreter, "(*NO_JIT)", with "(*NO_START_OPT)", so that no start-up
 * optimization answers for it. Subjects stay a few pumps long. The engine
 * compiles the pattern under the tables of the locale it runs under, the
 * target's.
 *
 * @internal
 */
final class WitnessCheck
{
    private const NO_START_OPT = '(*NO_START_OPT)';

    /**
     * The pattern pinned to the offset; null when no delimiter is left for
     * the verbs, and the engine is then not asked.
     */
    private readonly ?string $pinned;

    /**
     * @param string|null $locale the LC_CTYPE locale the pattern runs under; the one PHP
     *                            compiles under now when null
     */
    public function __construct(
        string $pattern,
        private readonly PcreEngine $engine = new PcreEngine(),
        private readonly ?string $locale = null,
    ) {
        $prepared = $this->engine->prepare($pattern);
        $this->pinned = NoJit::VERB === substr($prepared, 1, \strlen(NoJit::VERB))
            ? substr_replace($prepared, NoJit::VERB.self::NO_START_OPT, 1, \strlen(NoJit::VERB)).'A'
            : null;
    }

    /**
     * Whether the attempt at the offset matches: true or false as the
     * engine answers, null when it gives none.
     */
    public function matches(string $subject, int $offset): ?bool
    {
        $pinned = $this->pinned;
        if (null === $pinned) {
            return null;
        }

        $matched = null;
        CharacterTables::under($this->locale ?? CharacterTables::current(), function () use ($pinned, $subject, $offset, &$matched): void {
            $matched = $this->engine->match($pinned, $subject, null, $offset)->matched;
        });

        return $matched;
    }
}
