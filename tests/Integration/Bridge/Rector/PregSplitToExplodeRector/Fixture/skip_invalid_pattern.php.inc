<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class SkipInvalidPattern
{
    public function run(string $subject)
    {
        return preg_split('/(,/', $subject);
    }
}

?>
