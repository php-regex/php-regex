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

namespace PHPRegex\Cli\Option;

/**
 * How an option takes its value.
 *
 * @internal
 */
enum OptionValue
{
    /**
     * None: the option is a flag, and "--flag=value" is an option nobody
     * knows.
     */
    case None;

    /**
     * "--name=value", or "--name value" where the value after the space is
     * neither empty nor starts with "-".
     */
    case Required;

    /**
     * "--name=value", or "--name value" whatever the next argument is.
     */
    case Any;

    /**
     * "--name=value" only.
     */
    case Attached;
}
