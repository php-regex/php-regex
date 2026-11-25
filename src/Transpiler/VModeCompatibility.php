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

namespace PHPRegex\Transpiler;

use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Transpiler\Target\JavaScript\VModeScan;

/**
 * What a pattern written for PCRE, read as is by a browser under the v
 * flag, does wrong: the HTML pattern attribute compiles its value with
 * "v" alone, and a value the browser refuses to compile validates nothing.
 * Each finding names the construct, where it is written in the pattern
 * (a byte offset into its body) and what the browser does with it.
 *
 * A class under v escapes more than one under u: "(", ")", "[", "{", "}",
 * "/", "-" and "|" must be escaped anywhere in it, and a punctuator from
 * the reserved list must not be written twice in a row ("&&", "--"...).
 */
final class VModeCompatibility
{
    /**
     * What the browser does wrong with the pattern, in the order written.
     * The checker keeps nothing between two calls.
     *
     * @param RegexNode $ast The pattern, parsed with its source, as RegexParser::parse() gives it
     *
     * @return list<VModeFinding>
     */
    public function check(RegexNode $ast): array
    {
        return VModeScan::of($ast);
    }
}
