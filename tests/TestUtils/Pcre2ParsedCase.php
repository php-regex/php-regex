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

namespace PHPRegex\Tests\TestUtils;

/**
 * One pattern case while the extractor parses a testinput file: the pattern
 * as parsed so far, the modifier list after its terminator, and the command
 * context the pattern was parsed under.
 *
 * This is a mutable accumulator, not a value object: the body and modifier
 * list grow while a multi-line pattern is continued, and the output lines
 * fill in while the matching testoutput is paired. Only the context captured
 * when the pattern line starts is readonly. The extractor turns each one
 * into an immutable array row once both files are read.
 */
final class Pcre2ParsedCase
{
    /**
     * @var list<string> output-only lines seen before the case's first subject echo
     */
    public array $patternOutputLines = [];

    /**
     * @param list<string> $defaultModifiers "#pattern" defaults active when the case started
     */
    public function __construct(
        public readonly int $line,
        public readonly string $delimiter,
        public readonly array $defaultModifiers,
        public readonly bool $newlineCommand,
        public string $body = '',
        public string $modsRaw = '',
    ) {}
}
