<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipLoneBackslash
{
    public function run(string $subject): string
    {
        return preg_replace('/foo/', 'a\\b', $subject);
    }
}

?>
