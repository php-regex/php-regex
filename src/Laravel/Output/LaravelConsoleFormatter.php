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

namespace PHPRegex\Laravel\Output;

use PHPRegex\Linter\Formatter\AbstractConsoleTagFormatter;

/**
 * Laravel-specific console output formatter.
 *
 * Renders the classic Nuno-style layout with console tags.
 */
final readonly class LaravelConsoleFormatter extends AbstractConsoleTagFormatter {}
