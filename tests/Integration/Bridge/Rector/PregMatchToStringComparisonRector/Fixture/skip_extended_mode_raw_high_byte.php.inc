<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipExtendedModeRawHighByte
{
    public function run(string $subject): bool
    {
        return (bool) preg_match("/prix\xa0eur/x", $subject);
    }
}

?>
