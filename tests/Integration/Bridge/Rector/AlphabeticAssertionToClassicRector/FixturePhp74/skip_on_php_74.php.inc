<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\FixturePhp74;

final class SkipOnPhp74
{
    public function run(string $subject): bool
    {
        // PHP 7.4.0 bundles PCRE2 10.33, which reads the alphabetic names.
        return 1 === preg_match('/(*pla:foo)\w+/', $subject);
    }
}

?>
