<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipOtherPregFunction
{
    public function run(string $subject): bool
    {
        return (bool) preg_match_all('/foo/', $subject);
    }
}

?>
