<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipCaseless
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/foo/i', $subject);
    }
}

?>
