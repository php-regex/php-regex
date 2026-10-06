<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

// Only the last expression of a for condition decides whether the loop goes
// on; a preg_match() before it is read for nothing but its side effects.
final class SkipForConditionNotLast
{
    public function run(string $subject): int
    {
        $count = 0;
        for ($i = 0; preg_match('/foo/', $subject), $i < 3; ++$i) {
            ++$count;
        }

        return $count;
    }
}

?>
