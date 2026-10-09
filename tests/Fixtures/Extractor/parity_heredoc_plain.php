<?php

namespace App\Parity;

final class HeredocPlain
{
    public function run(string $subject): void
    {
        preg_match(<<<RE
            /heredoc
              (\d+) \. \\x \" \$ \x41 \101 \u{42} \t {x} $) a$

            /x
            RE, $subject);
    }
}
