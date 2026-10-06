<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipAssignment
{
    public function run(string $subject)
    {
        $found = preg_match('/foo/', $subject);

        return $found;
    }
}

?>
