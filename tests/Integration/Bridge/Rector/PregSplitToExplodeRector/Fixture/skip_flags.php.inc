<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class SkipFlags
{
    public function run(string $subject): array
    {
        return preg_split('/,/', $subject, -1, PREG_SPLIT_NO_EMPTY);
    }
}

?>
