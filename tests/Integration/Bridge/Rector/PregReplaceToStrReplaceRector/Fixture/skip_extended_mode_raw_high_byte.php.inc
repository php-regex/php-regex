<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipExtendedModeRawHighByte
{
    public function run(string $subject): string
    {
        return preg_replace("/prix\xa0eur/x", 'X', $subject);
    }
}

?>
