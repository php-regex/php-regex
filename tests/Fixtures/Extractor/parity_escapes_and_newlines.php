<?php

namespace App\Parity;

final class EscapesAndNewlines
{
    public function run(string $subject): void
    {
        preg_match("/upper-\X41\X4/", $subject);
        preg_match(<<<RE
            /heredoc-upper-\X41/
            RE, $subject);
        preg_match("/trailing-newline/\n", $subject);
        preg_match(<<<'RE'
            /heredoc-blank-line/

            RE, $subject);
        preg_match("/flags-then-newline/i\n", $subject);
    }
}
