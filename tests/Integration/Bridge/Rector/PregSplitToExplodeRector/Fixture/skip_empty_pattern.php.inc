<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class SkipEmptyPattern
{
    public function run(string $subject): array
    {
        return preg_split('//', $subject);
    }
}

?>
