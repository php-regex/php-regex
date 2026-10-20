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
 * The colors the console reports write text in, as ANSI codes and as
 * console tags.
 *
 * @internal
 */
enum ConsoleStyle
{
    case Accent;
    case AccentBold;
    case Error;
    case ErrorBold;
    case Success;
    case SuccessBold;
    case Warning;
    case WarningBold;
    case Plain;
    case Muted;

    private const BOLD = "\033[1m";

    public function ansi(): string
    {
        return match ($this) {
            self::Accent => "\033[36m",
            self::AccentBold => "\033[36m".self::BOLD,
            self::Error => "\033[31m",
            self::ErrorBold => "\033[31m".self::BOLD,
            self::Success => "\033[32m",
            self::SuccessBold => "\033[32m".self::BOLD,
            self::Warning => "\033[33m",
            self::WarningBold => "\033[33m".self::BOLD,
            self::Plain => "\033[37m",
            self::Muted => "\033[90m",
        };
    }

    public function tag(): string
    {
        return match ($this) {
            self::Accent => 'fg=cyan',
            self::AccentBold => 'fg=cyan;options=bold',
            self::Error => 'fg=red',
            self::ErrorBold => 'fg=red;options=bold',
            self::Success => 'fg=green',
            self::SuccessBold => 'fg=green;options=bold',
            self::Warning => 'fg=yellow',
            self::WarningBold => 'fg=yellow;options=bold',
            self::Plain => 'fg=white',
            self::Muted => 'fg=gray',
        };
    }
}
