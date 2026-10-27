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

namespace PHPRegex\Linter\Exception;

use PHPRegex\Parser\Exception\ExceptionInterface;

/**
 * Thrown when the PHP a project is to be judged for runs an engine the
 * library does not judge: PHP 7.2 and earlier ship PCRE1, not PCRE2.
 */
final class UnsupportedTargetException extends \InvalidArgumentException implements ExceptionInterface {}
