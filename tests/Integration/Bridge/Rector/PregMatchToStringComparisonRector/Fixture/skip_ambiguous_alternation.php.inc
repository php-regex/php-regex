<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipAmbiguousAlternation
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/(?:a|a){4}(?:a|a){4}(?:a|a){4}(?:a|a){4}(?:a|a){4}b/', $subject);
    }
}

?>
