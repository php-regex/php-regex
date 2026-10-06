<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class SkipNullableSubject
{
    public function run(?string $subject): array
    {
        return preg_split('/,/', $subject);
    }
}

?>
