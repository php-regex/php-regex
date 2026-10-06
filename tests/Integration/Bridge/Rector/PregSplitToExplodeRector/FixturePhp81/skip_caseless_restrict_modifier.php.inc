<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\FixturePhp81;

final class SkipCaselessRestrictModifier
{
    public function run(string $subject): array
    {
        return preg_split('/abc/r', $subject);
    }
}

?>
