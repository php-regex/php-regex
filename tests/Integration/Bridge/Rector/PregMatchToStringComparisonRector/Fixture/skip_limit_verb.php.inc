<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipLimitVerb
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/(*LIMIT_MATCH=1)foo/', $subject);
    }
}

?>
