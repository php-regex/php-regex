<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipConcatenationSubject
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/^foo\z/', $subject . 'x');
    }
}

?>
