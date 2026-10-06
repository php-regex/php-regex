<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipCoalesceSubject
{
    public function run(?string $subject, string $other): bool
    {
        return preg_match('/^foo\z/', $subject ?? $other) === 0;
    }
}

?>
