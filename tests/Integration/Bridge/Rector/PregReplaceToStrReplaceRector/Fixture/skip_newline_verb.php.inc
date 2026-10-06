<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipNewlineVerb
{
    public function run(string $subject): string
    {
        return preg_replace('/(*CRLF)foo/', 'X', $subject);
    }
}

?>
