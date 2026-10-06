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

namespace PHPRegex\Cli;

/**
 * Whether a command line asks for JSON: --format=json, --format json or
 * --json, before "--" ends the options. When the command line names more
 * than one format, the last one wins, as it does when the command reads its
 * options. Read before the options themselves are, so that a command line
 * the command cannot use is still reported as the JSON error envelope.
 *
 * @internal
 */
final class JsonRequest
{
    /**
     * @param array<int, string> $args
     */
    public static function in(array $args): bool
    {
        return 'json' === self::format($args);
    }

    /**
     * The format the command line asks for, lowercased, "json" for --json;
     * null when it names none.
     *
     * @param array<int, string> $args
     */
    public static function format(array $args): ?string
    {
        $args = array_values($args);
        $format = null;

        foreach ($args as $index => $arg) {
            if ('--' === $arg) {
                break;
            }

            if ('--json' === $arg) {
                $format = 'json';
            } elseif (str_starts_with($arg, '--format=')) {
                $format = strtolower(substr($arg, \strlen('--format=')));
            } elseif ('--format' === ($args[$index - 1] ?? null)) {
                $format = strtolower($arg);
            }
        }

        return $format;
    }
}
