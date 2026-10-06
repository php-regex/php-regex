<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\FixturePhp81;

final class SkipRawNul
{
    public function run(string $subject): array
    {
        return preg_split("/\0/", $subject);
    }
}

?>
