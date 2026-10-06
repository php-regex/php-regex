<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipFirstClassCallable
{
    public function run(): \Closure
    {
        return preg_replace(...);
    }
}

?>
