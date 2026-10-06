<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipNoStringFunction
{
    public function run(string $subject): bool
    {
        return preg_match('/fo+/', $subject) === 1 || preg_match('/foo$/', $subject) === 1;
    }
}

?>
