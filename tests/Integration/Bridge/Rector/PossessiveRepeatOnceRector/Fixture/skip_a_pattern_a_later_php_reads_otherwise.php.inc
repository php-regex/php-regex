<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PossessiveRepeatOnceRector\Fixture;

final class SkipAPatternALaterPhpReadsOtherwise
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/(a|ab){1}+c{,3}/', $subject);
    }
}

?>
