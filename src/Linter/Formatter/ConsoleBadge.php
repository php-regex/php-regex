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

namespace PHPRegex\Linter\Formatter;

/**
 * The badges of the console reports: a label on a background, as ANSI codes
 * and as console tags.
 *
 * @internal
 */
enum ConsoleBadge
{
    case Fail;
    case Warn;
    case Info;
    case Tip;
    case Pass;
    case PassWithWarnings;

    public function label(): string
    {
        return match ($this) {
            self::Fail => 'FAIL',
            self::Warn => 'WARN',
            self::Info => 'INFO',
            self::Tip => 'TIP',
            self::Pass, self::PassWithWarnings => 'PASS',
        };
    }

    /**
     * The background, then the foreground, as ANSI codes.
     */
    public function ansi(): string
    {
        return match ($this) {
            self::Fail => "\033[41m\033[37m",
            self::Warn, self::PassWithWarnings => "\033[43m\033[30m",
            self::Info => "\033[100m\033[37m",
            self::Tip => "\033[46m\033[30m",
            self::Pass => "\033[42m\033[37m",
        };
    }

    public function tag(): string
    {
        return match ($this) {
            self::Fail => 'bg=red;fg=white',
            self::Warn, self::PassWithWarnings => 'bg=yellow;fg=black',
            self::Info => 'bg=gray;fg=white',
            self::Tip => 'bg=cyan;fg=black',
            self::Pass => 'bg=green;fg=white',
        };
    }
}
