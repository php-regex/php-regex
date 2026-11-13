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

namespace PHPRegex\Tests\Support;

/**
 * The delimiters ClassSetProvider may write its engine probe with, and a
 * pattern whose class holds every one of them: no probe can be written for
 * it, which the tests of what reads a set through the probe ask about.
 */
final class ProbeDelimiters
{
    /**
     * A caseless class holding every delimiter the engine probe can be
     * written with: the usual ones, the punctuation and the control
     * characters PHP takes.
     */
    public const CASELESS_CLASS_OF_EVERY_ONE = "/^[\\/#~%!@;\"',&`=\x01\x02\x03\x04\x05\x06\x07\x08\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F]$/i";

    private function __construct() {}
}
