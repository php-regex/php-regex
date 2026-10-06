<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class SkipExtendedModeRawHighByte
{
    public function run(string $subject): array
    {
        return preg_split("/prix\xa0eur/x", $subject);
    }
}

?>
