<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipNonConstantPattern
{
    public function run(string $subject, string $pattern, bool $flag): bool
    {
        return (bool) preg_match($pattern, $subject) || (bool) preg_match($flag ? '/a/' : '/b/', $subject);
    }
}

?>
