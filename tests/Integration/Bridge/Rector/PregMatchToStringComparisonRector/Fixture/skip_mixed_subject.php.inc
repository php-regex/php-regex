<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipMixedSubject
{
    public function run(mixed $subject): bool
    {
        return (bool) preg_match('/foo/', $subject);
    }
}

?>
