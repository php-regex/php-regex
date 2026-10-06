<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipAnchoredModifier
{
    public function run(string $subject): string
    {
        return preg_replace('/foo/A', 'X', $subject);
    }
}

?>
