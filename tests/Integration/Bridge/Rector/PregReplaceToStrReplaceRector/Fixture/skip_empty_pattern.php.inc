<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipEmptyPattern
{
    public function run(string $subject): string
    {
        return preg_replace('//', 'X', $subject);
    }
}

?>
