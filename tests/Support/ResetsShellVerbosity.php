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

use PHPUnit\Framework\Attributes\After;

/**
 * A command run with --quiet sets SHELL_VERBOSITY in the environment of the
 * process. Symfony Console 7 restores it once the command ends; 6.4 leaves
 * it, and the next command of the test run writes nothing.
 */
trait ResetsShellVerbosity
{
    #[After]
    protected function resetShellVerbosity(): void
    {
        putenv('SHELL_VERBOSITY');
        unset($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);
    }
}
