<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipTwoStrings
{
    public function run(string $subject): string
    {
        return preg_replace('/a|ab/', 'X', $subject);
    }
}

?>
