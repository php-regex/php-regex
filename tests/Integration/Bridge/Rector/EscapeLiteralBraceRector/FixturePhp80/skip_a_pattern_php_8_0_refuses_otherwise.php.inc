<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\FixturePhp80;

final class SkipAPatternPhp80RefusesOtherwise
{
    public function run(string $subject): void
    {
        // A closed count past 65535 is refused by every PHP.
        preg_match('/a{65536}/', $subject);
        // Refused by PHP 8.0 for a second reason the escape leaves:
        // "sc:" arrived in PCRE2 10.40.
        preg_match('/a{65536\p{sc:Greek}/u', $subject);
        // Read alike by every PHP from 8.0.
        preg_match('/a{65535/', $subject);
    }
}

?>
