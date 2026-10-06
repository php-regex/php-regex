<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipCaseless
{
    public function run(string $subject): string
    {
        return preg_replace('/foo/i', 'X', $subject);
    }
}

?>
