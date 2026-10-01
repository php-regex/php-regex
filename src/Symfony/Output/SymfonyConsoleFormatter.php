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

namespace PhpRegex\Symfony\Output;

use PhpRegex\Linter\Formatter\AbstractConsoleTagFormatter;

/**
 * Symfony-specific console output formatter.
 *
 * Renders the classic Nuno-style layout with Symfony console tags.
 */
final readonly class SymfonyConsoleFormatter extends AbstractConsoleTagFormatter {}
