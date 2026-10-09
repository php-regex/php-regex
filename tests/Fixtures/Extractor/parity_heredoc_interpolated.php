<?php

namespace App\Parity;

final class HeredocInterpolated
{
    public function run(string $subject, string $suffix): void
    {
        preg_match(<<<RE
            /heredoc-{$suffix}/
            RE, $subject);
        preg_match(<<<RE
            /heredoc-$suffix/
            RE, $subject);
    }
}
