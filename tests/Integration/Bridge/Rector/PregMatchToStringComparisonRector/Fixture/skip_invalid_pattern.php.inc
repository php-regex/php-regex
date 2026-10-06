<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipInvalidPattern
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/(foo/', $subject);
    }
}

?>
