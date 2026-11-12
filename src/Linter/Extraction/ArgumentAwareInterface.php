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

namespace PHPRegex\Linter\Extraction;

use PHPRegex\Linter\PatternOccurrence;

/**
 * An extractor that can say, besides the patterns it reads, what it knows
 * of every pattern argument it meets: one whose pattern is built at run
 * time holds no pattern to read, and is an argument all the same. The lint
 * run asks for them; extract() keeps giving the patterns and nothing else.
 *
 * @internal
 */
interface ArgumentAwareInterface
{
    /**
     * What extract() returns, in the same order, and one
     * PatternOccurrence::argument() for each pattern argument of the files.
     *
     * @param array<string> $files List of PHP file paths to analyze
     *
     * @return list<PatternOccurrence>
     */
    public function extractWithArguments(array $files): array;
}
