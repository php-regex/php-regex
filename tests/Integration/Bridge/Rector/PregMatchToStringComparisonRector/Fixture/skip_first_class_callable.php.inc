<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipFirstClassCallable
{
    public function run(): \Closure
    {
        return preg_match(...);
    }
}

?>
