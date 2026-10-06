<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipTernarySubject
{
    public function run(bool $which, string $subject, string $other): int
    {
        return preg_match('/^foo\z/', $which ? $subject : $other) ? 1 : 2;
    }
}

?>
