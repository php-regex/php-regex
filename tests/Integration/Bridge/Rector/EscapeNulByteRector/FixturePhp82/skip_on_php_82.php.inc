<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeNulByteRector\FixturePhp82;

final class SkipOnPhp82
{
    public function run(string $subject): bool
    {
        // PHP 8.2 reads a NUL byte in a pattern: nothing to escape.
        return 1 === preg_match("/a\0b/", $subject);
    }
}

?>
