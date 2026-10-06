<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipIntSubject
{
    public function run(int $subject): bool
    {
        return (bool) preg_match('/1/', $subject);
    }
}

?>
