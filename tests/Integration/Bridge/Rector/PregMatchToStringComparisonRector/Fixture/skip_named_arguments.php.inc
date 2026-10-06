<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipNamedArguments
{
    public function run(string $subject): bool
    {
        return (bool) preg_match(pattern: '/foo/', subject: $subject);
    }
}

?>
