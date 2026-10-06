<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\FixturePhp81;

final class SkipNoAutoCaptureModifier
{
    public function run(string $subject): string
    {
        return preg_replace('/abc/n', 'X', $subject);
    }
}

?>
