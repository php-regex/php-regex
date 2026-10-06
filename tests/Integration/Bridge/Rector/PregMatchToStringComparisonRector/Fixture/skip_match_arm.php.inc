<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipMatchArm
{
    public function run(string $subject): string
    {
        return match (true) {
            preg_match('/foo/', $subject) => 'yes',
            default => 'no',
        };
    }
}

?>
