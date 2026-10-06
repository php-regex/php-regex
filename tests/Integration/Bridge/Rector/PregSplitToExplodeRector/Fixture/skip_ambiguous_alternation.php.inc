<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class SkipAmbiguousAlternation
{
    public function run(string $subject): array|false
    {
        return preg_split('/(?:a|a){20}b/', $subject);
    }
}

?>
