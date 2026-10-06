<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\FixturePhp74;

final class SkipBelowTheRuleFloor
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/foo/', $subject) || (bool) preg_match('/^foo\z/', $subject);
    }
}

?>
