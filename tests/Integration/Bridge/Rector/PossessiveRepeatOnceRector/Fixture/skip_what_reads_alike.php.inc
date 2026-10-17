<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PossessiveRepeatOnceRector\Fixture;

final class SkipWhatReadsAlike
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/a{1}+b|(?>a|ab){1}+c|(?=a|ab){1}+d|(a|ab){1}c|(a|ab){2}+c|(*asr:a|ab){1}+e/', $subject);
    }
}

?>
