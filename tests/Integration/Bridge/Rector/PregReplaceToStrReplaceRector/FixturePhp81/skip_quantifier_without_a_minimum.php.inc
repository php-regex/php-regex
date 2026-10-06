<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\FixturePhp81;

final class SkipQuantifierWithoutAMinimum
{
    public function run(string $subject): string
    {
        return preg_replace('/a{,0}b/', 'X', $subject);
    }
}

?>
