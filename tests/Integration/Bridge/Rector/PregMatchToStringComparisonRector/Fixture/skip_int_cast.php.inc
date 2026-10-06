<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipIntCast
{
    public function run(string $subject): int
    {
        return (int) preg_match('/foo/', $subject);
    }
}

?>
