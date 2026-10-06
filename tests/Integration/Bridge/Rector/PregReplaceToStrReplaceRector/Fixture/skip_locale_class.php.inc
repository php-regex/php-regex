<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipLocaleClass
{
    public function run(string $subject): string
    {
        return preg_replace('/\d/', 'X', $subject);
    }
}

?>
