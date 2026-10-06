<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipNewlineVerb
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/(*CRLF)foo/', $subject);
    }
}

?>
