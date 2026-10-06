<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipUnicode
{
    public function run(string $subject): string
    {
        return preg_replace('/foo/u', 'X', $subject);
    }
}

?>
