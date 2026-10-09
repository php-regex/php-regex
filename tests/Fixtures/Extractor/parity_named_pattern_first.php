<?php

namespace App\Parity;

final class NamedPatternFirst
{
    public function run(string $subject): void
    {
        preg_match(pattern: '/named-first/i', subject: $subject);
    }
}
