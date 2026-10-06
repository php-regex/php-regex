<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\FixturePhp81;

final class SkipQuantifierWithoutAMinimum
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/a{,0}b/', $subject);
    }
}

?>
