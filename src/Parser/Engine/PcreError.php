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

namespace PHPRegex\Parser\Engine;

/**
 * Why the running engine refuses a pattern, as PHP reports it for the
 * pattern as the caller wrote it: "reference to non-existent subpattern at
 * offset 3", "Unknown modifier 'Q'".
 */
final readonly class PcreError
{
    /**
     * @internal built by PcreEngine::compile()
     *
     * @param string   $message the message, without the function name and without "Compilation failed: "
     * @param int|null $offset  the byte offset in the pattern body the message names, null when it names none
     */
    public function __construct(public string $message, public ?int $offset = null) {}
}
