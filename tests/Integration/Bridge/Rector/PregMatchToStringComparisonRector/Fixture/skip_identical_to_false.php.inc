<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipIdenticalToFalse
{
    public function run(string $subject): bool
    {
        return preg_match('/foo/', $subject) === false;
    }
}

?>
