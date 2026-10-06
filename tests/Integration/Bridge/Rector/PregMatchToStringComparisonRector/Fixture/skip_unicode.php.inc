<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipUnicode
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/foo/u', $subject);
    }
}

?>
