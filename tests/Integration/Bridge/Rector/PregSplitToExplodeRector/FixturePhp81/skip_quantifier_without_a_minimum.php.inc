<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\FixturePhp81;

final class SkipQuantifierWithoutAMinimum
{
    public function run(string $subject): array
    {
        return preg_split('/a{,0}b/', $subject);
    }
}

?>
