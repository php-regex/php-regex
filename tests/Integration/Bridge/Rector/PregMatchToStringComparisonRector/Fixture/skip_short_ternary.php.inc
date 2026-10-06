<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipShortTernary
{
    public function run(string $subject)
    {
        return preg_match('/foo/', $subject) ?: 0;
    }
}

?>
