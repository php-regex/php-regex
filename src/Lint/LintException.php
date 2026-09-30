<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Lint;

use RegexParser\Exception\RegexParserExceptionInterface;

/**
 * The linter could not do what it was asked: a formatter it does not know,
 * a worker that failed, a report it cannot write out.
 */
final class LintException extends \RuntimeException implements RegexParserExceptionInterface {}
