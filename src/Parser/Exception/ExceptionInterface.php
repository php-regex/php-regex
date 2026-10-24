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

namespace PHPRegex\Parser\Exception;

/**
 * Defines a common contract for all exceptions thrown by the php-regex library.
 *
 * The one catch point for a consumer: every failure the library can
 * raise crosses this interface. What the catch means depends on the
 * concrete type — a RegexException says the pattern cannot be read, an
 * InternalFailureException says the library itself gave up (a runtime
 * limit, an environment without ini_set()) and carries no verdict on the
 * pattern at all. An analyser catching this interface should degrade to
 * a wider type or no shape, never report the pattern as invalid.
 */
interface ExceptionInterface extends \Throwable {}
