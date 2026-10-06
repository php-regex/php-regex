<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipWordBoundary
{
    public function run(string $subject): string
    {
        return preg_replace('/\bfoo\b/', 'X', $subject);
    }
}

?>
