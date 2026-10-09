<?php

namespace App\Parity;

final class Nowdoc
{
    public function run(string $subject): void
    {
        preg_match(<<<'RE'
            /nowdoc
              (\d+) \. \\x \' $x " {$y} \u{41}

            /x
            RE, $subject);
        preg_replace([<<<'RE'
            /nowdoc-in-array/
            RE, '/after-nowdoc/'], '', $subject);
    }
}
