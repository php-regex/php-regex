<?php

namespace App\Parity;

use Composer\Pcre\Preg;

final class NamedPatternSecond
{
    public function run(string $subject, string $pattern): void
    {
        preg_match(subject: $subject, pattern: '/named-second/');
        Preg::match(subject: $subject, pattern: '/named-wrapper/');
        preg_match(subject: $subject, matches: $matches);
        preg_match(subject: $subject, pattern: $pattern);
        preg_match(
            subject: $subject,
            pattern: '/named-trailing-comma/',
        );
    }
}
