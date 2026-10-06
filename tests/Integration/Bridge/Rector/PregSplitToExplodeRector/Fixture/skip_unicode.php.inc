<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class SkipUnicode
{
    public function run(string $subject): array
    {
        return preg_split('/,/u', $subject);
    }
}

?>
