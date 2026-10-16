<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\Fixture;

final class SkipWhatPhp73Reads
{
    public function run(string $subject, string $tail): void
    {
        // Read alike by every PHP from 7.3: nothing to respell.
        preg_match('/(?=foo)\w+(?<!bar)/', $subject);
        // Quoted, the name is text.
        preg_match('/\Q(*pla:a)\E/', $subject);
        // Built from more than one string.
        preg_match('/(*pla:a)' . $tail . '/', $subject);
        // A first-class callable holds no pattern.
        $match = preg_match(...);
        // Refused by PHP 7.3 for a second reason the respelling leaves.
        preg_match("/(*pla:a)b\0/", $subject);
    }
}

?>
