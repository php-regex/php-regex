<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture;

final class SkipAPatternALaterPhpRefuses
{
    public function run(string $subject): bool
    {
        // PHP 8.5 refuses "\K" in a lookbehind, whatever the brace reads:
        // the range up to the newest PHP is not read alike, so it is left.
        return 1 === preg_match('/(?<=a\Kb)c{,3}/', $subject);
    }
}

?>
