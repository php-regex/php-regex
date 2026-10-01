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

namespace PhpRegex\Parser\Engine;

/**
 * What the running engine answered for one pattern and one subject.
 */
final readonly class PcreMatch
{
    /**
     * @param bool|null                 $matched   true or false as preg_match() answers; null when it gave no answer
     * @param array<int|string, string> $groups    the groups as preg_match() fills them; empty without an answer
     * @param string|null               $error     why no answer came: the compilation error, or the engine's last error
     * @param int                       $errorCode the engine's last error code, PREG_NO_ERROR with an answer
     */
    public function __construct(
        public ?bool $matched,
        public array $groups = [],
        public ?string $error = null,
        public int $errorCode = \PREG_NO_ERROR,
    ) {}
}
