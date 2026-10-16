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

namespace PHPRegex\Laravel\Command;

use Illuminate\Console\Command;

/**
 * The string an argument or a value option holds, read as the console
 * gives it: the empty string when it holds none.
 *
 * @internal
 *
 * @phpstan-require-extends Command
 */
trait ReadsStringInput
{
    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return \is_string($value) ? $value : '';
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return \is_string($value) ? $value : '';
    }
}
