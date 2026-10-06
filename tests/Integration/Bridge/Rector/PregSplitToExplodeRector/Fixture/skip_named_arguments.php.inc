<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class SkipNamedArguments
{
    public function run(string $subject): array
    {
        return preg_split(pattern: '/,/', subject: $subject);
    }
}

?>
