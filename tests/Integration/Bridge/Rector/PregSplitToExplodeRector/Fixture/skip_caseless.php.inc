<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class SkipCaseless
{
    public function run(string $subject): array
    {
        return preg_split('/x/i', $subject);
    }
}

?>
