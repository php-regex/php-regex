<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PossessiveRepeatOnceRector\FixtureLater;

final class SkipOnALaterPhp
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/(a|ab){1}+c/', $subject);
    }
}

?>
