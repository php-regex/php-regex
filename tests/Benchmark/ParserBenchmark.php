<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Benchmark;

use PhpBench\Attributes as Bench;
use PhpRegex\Toolkit\Regex;

final class ParserBenchmark
{
    #[Bench\Warmup(2)]
    #[Bench\Iterations(5)]
    #[Bench\Revs(100)]
    public function benchParseComplex(): void
    {
        $regex = Regex::create();
        $regex->parse('/^(?P<name>[a-z]+)([0-9]{1,3})?$/i');
    }
}
