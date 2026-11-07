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

namespace PHPRegex\Infection\Judge;

use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\RegexParser;

/**
 * Subjects to try when the automata found none: strings each pattern
 * matches, drawn by the sample generator. Nothing here proves anything; a
 * subject becomes a kill string only once the replay of the call tells the
 * two patterns apart on it. The draw is seeded from the two patterns, so
 * the same pair gets the same subjects on every run.
 *
 * @internal
 */
final class DifferentialFallback
{
    /**
     * The samples drawn from each pattern.
     */
    public const SAMPLES = 4;

    public function __construct(private readonly SampleGenerator $generator = new SampleGenerator(), private readonly ?RegexParser $parser = null) {}

    /**
     * @return list<string>
     */
    public function subjects(string $original, string $mutant): array
    {
        $seed = crc32($original."\0".$mutant);

        $subjects = [];
        foreach ([$original, $mutant] as $pattern) {
            for ($i = 0; $i < self::SAMPLES; $i++) {
                $this->generator->setSeed($seed + $i);

                try {
                    $subject = $this->generator->generate($pattern, $this->parser);
                } catch (ExceptionInterface) {
                    break;
                }
                if (!\in_array($subject, $subjects, true)) {
                    $subjects[] = $subject;
                }
            }
        }

        return $subjects;
    }
}
