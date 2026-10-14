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

/**
 * What a terminal shows once it has printed a stream: carriage returns,
 * line feeds, "erase the line" and "cursor up" applied, colours dropped.
 * The lines come back right-trimmed; the last one is where the cursor is.
 */
final class TerminalScreen
{
    /**
     * @return list<string>
     */
    public static function render(string $stream): array
    {
        /** @var list<list<string>> $lines */
        $lines = [[]];
        $row = 0;
        $column = 0;

        // Operating system commands (a taskbar's progress) draw nothing.
        $stream = (string) preg_replace('/\e\][^\x07\e]*(?:\x07|\e\\\\)/', '', $stream);
        $tokens = preg_split('/(\e\[[0-9;]*[A-Za-z]|\r|\n)/u', $stream, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            if ("\n" === $token) {
                $row++;
                $column = 0;
                $lines[$row] ??= [];
            } elseif ("\r" === $token) {
                $column = 0;
            } elseif (1 === preg_match('/^\e\[(\d*)A$/', $token, $up)) {
                $row = max(0, $row - max(1, (int) $up[1]));
            } elseif (1 === preg_match('/^\e\[(\d*)G$/', $token, $to)) {
                $column = max(1, (int) $to[1]) - 1;
            } elseif (1 === preg_match('/^\e\[2K$/', $token)) {
                $lines[$row] = [];
            } elseif (str_starts_with($token, "\e[")) {
                continue;
            } else {
                foreach (mb_str_split($token) as $character) {
                    for ($i = \count($lines[$row]); $i < $column; $i++) {
                        $lines[$row][$i] = ' ';
                    }
                    $lines[$row][$column++] = $character;
                }
            }
        }

        ksort($lines);

        return array_values(array_map(static fn (array $line): string => rtrim(implode('', $line)), $lines));
    }
}
