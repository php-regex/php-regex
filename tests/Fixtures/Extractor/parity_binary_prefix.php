<?php

namespace App\Parity;

final class BinaryPrefix
{
    public function run(string $subject): void
    {
        preg_match(b'/binary-single/', $subject);
        preg_match(B'/binary-upper/', $subject);
        preg_match(b"/binary-\d\x41/", $subject);
        preg_match(b<<<'RE'
            /binary-nowdoc\d/
            RE, $subject);
        preg_replace([b'/binary-in-array/'], '', $subject);
    }
}
