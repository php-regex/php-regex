<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipMatchSubject
{
    public function run(string $subject): string
    {
        return match (preg_match('/foo/', $subject)) {
            1 => 'yes',
            default => 'no',
        };
    }
}

?>
