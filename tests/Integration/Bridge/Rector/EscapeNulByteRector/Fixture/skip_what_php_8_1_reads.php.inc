<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeNulByteRector\Fixture;

final class SkipWhatPhp81Reads
{
    public function run(string $subject, string $tail): void
    {
        // The escape, read by every PHP.
        preg_match('/a\x00b/', $subject);
        // Built from more than one string.
        preg_match("/a\0" . $tail . '/', $subject);
        // Quoted: "\x00" there would be four characters of text.
        preg_match("/\Qa\0b\E/", $subject);
    }
}

?>
