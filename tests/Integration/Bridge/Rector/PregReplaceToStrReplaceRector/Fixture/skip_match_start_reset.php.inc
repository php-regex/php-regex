<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipMatchStartReset
{
    public function run(string $subject): string
    {
        return preg_replace('/a\Kb/', 'X', $subject);
    }
}

?>
