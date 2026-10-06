<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipShortTernarySubject
{
    public function run(string $subject, string $other): bool
    {
        if (preg_match('/^foo\z/', $subject ?: $other)) {
            return true;
        }

        return false;
    }
}

?>
