<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipReturn
{
    public function run(string $subject)
    {
        return preg_match('/foo/', $subject);
    }
}

?>
