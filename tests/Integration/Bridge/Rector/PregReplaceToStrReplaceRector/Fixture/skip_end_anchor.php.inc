<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipEndAnchor
{
    public function run(string $subject): string
    {
        return preg_replace('/foo\z/', 'X', $subject);
    }
}

?>
