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

namespace PhpRegex\Cli;

use PhpRegex\Parser\Exception\ExceptionInterface;

/**
 * The command line tool could not do what it was asked: a format it does not
 * write, a self-update that cannot proceed.
 */
final class CliException extends \RuntimeException implements ExceptionInterface {}
