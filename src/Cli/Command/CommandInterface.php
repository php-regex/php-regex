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

namespace RegexParser\Cli\Command;

use RegexParser\Cli\Input;
use RegexParser\Cli\Output;

/**
 * A command of the binary. Every one exits with one of the three codes below.
 */
interface CommandInterface
{
    /**
     * The command did what it was asked and found nothing wrong.
     */
    public const SUCCESS = 0;

    /**
     * The patterns or the files the command judged have a problem.
     */
    public const FAILURE = 1;

    /**
     * The command line or the configuration cannot be used.
     */
    public const INVALID = 2;

    public function getName(): string;

    /**
     * @return array<int, string>
     */
    public function getAliases(): array;

    public function getDescription(): string;

    public function run(Input $input, Output $output): int;
}
