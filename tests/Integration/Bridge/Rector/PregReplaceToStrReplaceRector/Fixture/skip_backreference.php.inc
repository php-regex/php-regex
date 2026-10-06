<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipBackreference
{
    public function run(string $subject): string
    {
        return preg_replace('/(a)\1/', 'X', $subject);
    }
}

?>
