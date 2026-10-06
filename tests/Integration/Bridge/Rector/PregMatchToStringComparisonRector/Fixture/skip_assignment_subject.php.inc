<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipAssignmentSubject
{
    public function run(string $subject): bool
    {
        return !preg_match('/^\z/', $copy = $subject);
    }
}

?>
