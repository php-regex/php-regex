<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipLocaleClasses
{
    public function run(string $subject): bool
    {
        return preg_match('/^\s\z/', $subject) === 1 || preg_match('/^[[:digit:]]\z/', $subject) === 1;
    }
}

?>
