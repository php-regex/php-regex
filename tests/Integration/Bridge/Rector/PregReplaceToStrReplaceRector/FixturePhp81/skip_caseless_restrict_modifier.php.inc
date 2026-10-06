<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\FixturePhp81;

final class SkipCaselessRestrictModifier
{
    public function run(string $subject): string
    {
        return preg_replace('/abc/r', 'X', $subject);
    }
}

?>
