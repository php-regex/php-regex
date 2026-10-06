<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipFlagsAndOffset
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/foo/', $subject, $matches, 0, 2);
    }
}

?>
