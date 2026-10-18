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

namespace PHPRegex\Tests\Support\PhpUnitCompat\Native;

/**
 * PHPUnit 13.2 and later declare expectExceptionMessageIsOrContains(),
 * final: the test case uses theirs.
 */
trait ExpectExceptionMessageIsOrContains {}
